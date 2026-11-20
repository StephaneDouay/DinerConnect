<?php
declare(strict_types=1);

class ImapClient
{
    private $socket = null;
    private int $tag = 0;
    private string $host;
    private int    $port;
    private string $user;
    private string $pass;

    public function __construct(array $cfg)
    {
        $this->host = $cfg['host'];
        $this->port = (int)$cfg['port'];
        $this->user = $cfg['user'];
        $this->pass = $cfg['pass'];
    }

    public function connect(): void
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ]]);

        $this->socket = @stream_socket_client(
            "ssl://{$this->host}:{$this->port}",
            $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx
        );

        if (!$this->socket) {
            throw new RuntimeException("IMAP: connexion impossible à {$this->host}:{$this->port} – {$errstr}");
        }

        stream_set_timeout($this->socket, 30);

        // Read greeting
        $this->readLine();

        // Login
        $resp = $this->command("LOGIN \"{$this->user}\" \"{$this->pass}\"");
        if (!str_starts_with($resp['status'], 'OK')) {
            throw new RuntimeException('IMAP: [AUTHENTICATIONFAILED] ' . $resp['status']);
        }
    }

    public function select(string $mailbox = 'INBOX'): array
    {
        $resp = $this->command("SELECT \"{$mailbox}\"");
        if (!str_starts_with($resp['status'], 'OK')) {
            throw new RuntimeException("IMAP SELECT failed: " . $resp['status']);
        }
        $info = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
        foreach ($resp['lines'] as $line) {
            if (preg_match('/\* (\d+) EXISTS/', $line, $m)) $info['exists'] = (int)$m[1];
            if (preg_match('/UIDVALIDITY (\d+)/', $line, $m)) $info['uidvalidity'] = (int)$m[1];
            if (preg_match('/UIDNEXT (\d+)/', $line, $m)) $info['uidnext'] = (int)$m[1];
        }
        return $info;
    }

    public function search(string $criteria = 'ALL'): array
    {
        $resp = $this->command("UID SEARCH {$criteria}");
        $uids = [];
        foreach ($resp['lines'] as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                $parts = explode(' ', trim(substr($line, 8)));
                foreach ($parts as $p) {
                    if (ctype_digit($p) && $p !== '') $uids[] = (int)$p;
                }
            }
        }
        return $uids;
    }

    public function fetchHeaders(int $uid): array
    {
        $resp = $this->command("UID FETCH {$uid} (RFC822.HEADER)");
        $raw = $this->extractFetchData($resp['lines'], 'RFC822.HEADER');
        return $this->parseHeaders($raw);
    }

    public function fetchBody(int $uid): string
    {
        $resp = $this->command("UID FETCH {$uid} (RFC822)");
        $raw = $this->extractFetchData($resp['lines'], 'RFC822');
        return $this->extractTextFromMime($raw);
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            try {
                $this->command('LOGOUT');
            } catch (Throwable) {}
            fclose($this->socket);
            $this->socket = null;
        }
    }

    // --- Private helpers ---

    private function nextTag(): string
    {
        return 'A' . str_pad((string)(++$this->tag), 4, '0', STR_PAD_LEFT);
    }

    private function command(string $cmd): array
    {
        $tag  = $this->nextTag();
        $line = "{$tag} {$cmd}\r\n";
        fwrite($this->socket, $line);

        $lines  = [];
        $status = '';
        while (true) {
            $l = $this->readLine();
            if ($l === false) break;
            if (str_starts_with($l, $tag . ' OK') || str_starts_with($l, $tag . ' NO') || str_starts_with($l, $tag . ' BAD')) {
                $status = ltrim(substr($l, strlen($tag) + 1));
                break;
            }
            $lines[] = $l;
        }
        return ['status' => $status, 'lines' => $lines];
    }

    private function readLine(): string|false
    {
        $buf = '';
        while (!feof($this->socket)) {
            $c = fread($this->socket, 1);
            if ($c === false) break;
            $buf .= $c;
            if (str_ends_with($buf, "\r\n")) return rtrim($buf, "\r\n");
            // Handle literal {N}
            if (preg_match('/\{(\d+)\}\s*$/', $buf, $m)) {
                $n    = (int)$m[1];
                $data = '';
                while (strlen($data) < $n) {
                    $chunk = fread($this->socket, $n - strlen($data));
                    if ($chunk === false) break;
                    $data .= $chunk;
                }
                return rtrim($buf) . $data;
            }
        }
        return $buf ?: false;
    }

    private function extractFetchData(array $lines, string $part): string
    {
        $raw   = implode("\r\n", $lines);
        $upper = strtoupper($part);
        // Find the literal after BODY[...] or RFC822.HEADER etc.
        $pos = strpos(strtoupper($raw), $upper);
        if ($pos === false) return '';
        // Skip past the {N} literal marker
        $after = substr($raw, $pos + strlen($upper));
        if (preg_match('/\{(\d+)\}\r?\n([\s\S]*)/s', $after, $m)) {
            return substr($m[2], 0, (int)$m[1]);
        }
        // No literal, grab rest
        return trim(substr($after, strpos($after, "\r\n") + 2));
    }

    private function parseHeaders(string $raw): array
    {
        // Unfold headers
        $raw = preg_replace("/\r?\n[ \t]+/", ' ', $raw);
        $headers = [];
        foreach (explode("\n", $raw) as $line) {
            $line = rtrim($line, "\r");
            if (strpos($line, ':') === false) continue;
            [$name, $value] = explode(':', $line, 2);
            $name  = strtolower(trim($name));
            $value = $this->decodeMimeStr(trim($value));
            if (!isset($headers[$name])) {
                $headers[$name] = $value;
            } else {
                $headers[$name] .= ' ' . $value;
            }
        }
        return $headers;
    }

    private function decodeMimeStr(string $str): string
    {
        return preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', function ($m) {
            $charset  = $m[1];
            $encoding = strtoupper($m[2]);
            $text     = $m[3];
            if ($encoding === 'B') {
                $decoded = base64_decode($text);
            } else {
                $decoded = quoted_printable_decode(str_replace('_', ' ', $text));
            }
            return mb_convert_encoding($decoded, 'UTF-8', $charset);
        }, $str);
    }

    private function extractTextFromMime(string $raw): string
    {
        // Split headers and body
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
        $headerRaw = $parts[0] ?? '';
        $body      = $parts[1] ?? '';

        $headers = $this->parseHeaders($headerRaw);
        $ct      = $headers['content-type'] ?? '';
        $te      = strtolower($headers['content-transfer-encoding'] ?? '');

        // Decode transfer encoding first
        if ($te === 'base64') {
            $body = base64_decode(str_replace(["\r", "\n"], '', $body));
        } elseif ($te === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        }

        // Convert charset
        if (preg_match('/charset=["\']?([^;"\'>\s]+)/i', $ct, $m)) {
            $charset = strtoupper(trim($m[1], '"\''));
            if ($charset !== 'UTF-8') {
                $body = @mb_convert_encoding($body, 'UTF-8', $charset) ?: $body;
            }
        }

        // If multipart, recurse into first text part
        if (preg_match('/boundary=["\']?([^;"\'>\s]+)/i', $ct, $m)) {
            $boundary = trim($m[1], '"\'');
            $subparts = preg_split('/--' . preg_quote($boundary, '/') . '(?:--)?/', $body);
            foreach ($subparts as $sub) {
                $sub = ltrim($sub, "\r\n");
                if (!$sub || $sub === '--') continue;
                $subParts = preg_split("/\r?\n\r?\n/", $sub, 2);
                $subHeaders = $this->parseHeaders($subParts[0] ?? '');
                $subCt = $subHeaders['content-type'] ?? '';
                if (str_contains(strtolower($subCt), 'text/plain') || str_contains(strtolower($subCt), 'text/html')) {
                    $result = $this->extractTextFromMime($sub);
                    if ($result !== '') return $result;
                }
            }
        }

        // Strip HTML tags if HTML
        if (str_contains(strtolower($ct), 'text/html')) {
            $body = strip_tags($body);
        }

        return trim($body);
    }
}
