<?php
declare(strict_types=1);

class AdzunaAPI
{
    private string $appId;
    private string $appKey;

    public function __construct(array $cfg)
    {
        $this->appId  = $cfg['app_id'];
        $this->appKey = $cfg['app_key'];
    }

    public function search(string $motCle, string $lieu, int $rayonKm = 30): array
    {
        if (!$this->appId || !$this->appKey) {
            return [];
        }

        $params = http_build_query([
            'app_id'           => $this->appId,
            'app_key'          => $this->appKey,
            'results_per_page' => 50,
            'what'             => $motCle,
            'where'            => $lieu,
            'distance'         => $rayonKm,
            'sort_by'          => 'date',
            'content-type'     => 'application/json',
        ]);

        $url = "https://api.adzuna.com/v1/api/jobs/fr/search/1?{$params}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$resp) {
            throw new RuntimeException("Adzuna search failed (HTTP {$code})");
        }

        $data = json_decode($resp, true);
        return array_map([$this, 'normalize'], $data['results'] ?? []);
    }

    private function normalize(array $r): array
    {
        return [
            'source'           => 'adzuna',
            'external_id'      => (string)($r['id'] ?? ''),
            'titre'            => $r['title'] ?? '',
            'entreprise'       => $r['company']['display_name'] ?? '',
            'lieu'             => $r['location']['display_name'] ?? '',
            'url'              => $r['redirect_url'] ?? '',
            'description'      => strip_tags($r['description'] ?? ''),
            'date_publication' => isset($r['created']) ? substr($r['created'], 0, 10) : null,
        ];
    }
}
