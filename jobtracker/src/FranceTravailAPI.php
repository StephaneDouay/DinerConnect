<?php
declare(strict_types=1);

class FranceTravailAPI
{
    private string $clientId;
    private string $clientSecret;
    private ?string $token = null;
    private int $tokenExpiry = 0;

    public function __construct(array $cfg)
    {
        $this->clientId     = $cfg['client_id'];
        $this->clientSecret = $cfg['client_secret'];
    }

    private function getToken(): string
    {
        if ($this->token && time() < $this->tokenExpiry - 30) {
            return $this->token;
        }

        $url  = 'https://entreprise.francetravail.fr/connexion/oauth2/access_token?realm=%2Fpartenaire';
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => 'api_offresdemploiv2 o2dsoffre',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$resp) {
            throw new RuntimeException("France Travail OAuth failed (HTTP {$code}): {$resp}");
        }

        $data = json_decode($resp, true);
        if (empty($data['access_token'])) {
            throw new RuntimeException('France Travail: no access_token in response');
        }

        $this->token       = $data['access_token'];
        $this->tokenExpiry = time() + (int)($data['expires_in'] ?? 1800);
        return $this->token;
    }

    public function search(string $motCle, string $lieu, int $rayonKm = 30): array
    {
        $token = $this->getToken();

        $params = http_build_query([
            'motsCles'      => $motCle,
            'commune'       => strlen($lieu) <= 2 ? null : $lieu,  // commune code if >2 chars
            'departement'   => strlen($lieu) <= 2 ? $lieu : null,
            'distance'      => $rayonKm,
            'range'         => '0-49',
            'sort'          => '1',  // date desc
        ], '', '&', PHP_QUERY_RFC3986);
        // remove null params
        $params = preg_replace('/[^&]*=&/', '', $params);
        $params = preg_replace('/[^&]*=$/', '', $params);

        $url = 'https://api.francetravail.io/partenaire/offresdemploi/v2/offres/search?' . $params;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 206 || $code === 200) {
            $data = json_decode($resp, true);
            return array_map([$this, 'normalize'], $data['resultats'] ?? []);
        }

        if ($code === 204) {
            return [];
        }

        throw new RuntimeException("France Travail search failed (HTTP {$code}): " . substr($resp, 0, 300));
    }

    private function normalize(array $r): array
    {
        return [
            'source'           => 'francetravail',
            'external_id'      => $r['id'] ?? '',
            'titre'            => $r['intitule'] ?? '',
            'entreprise'       => $r['entreprise']['nom'] ?? '',
            'lieu'             => $r['lieuTravail']['libelle'] ?? '',
            'url'              => $r['origineOffre']['urlOrigine'] ?? ('https://candidat.francetravail.fr/offres/recherche/detail/' . ($r['id'] ?? '')),
            'description'      => $r['description'] ?? '',
            'date_publication' => isset($r['dateCreation']) ? substr($r['dateCreation'], 0, 10) : null,
        ];
    }
}
