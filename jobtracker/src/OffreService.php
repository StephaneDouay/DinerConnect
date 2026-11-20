<?php
declare(strict_types=1);

class OffreService
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function fetchAndStore(): array
    {
        $ftApi  = new FranceTravailAPI($this->cfg['francetravail']);
        $azApi  = new AdzunaAPI($this->cfg['adzuna']);
        $search = $this->cfg['recherche'];
        $counts = ['francetravail' => 0, 'adzuna' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($search['mots_cles'] as $motCle) {
            foreach ($search['lieux'] as $lieu) {
                // France Travail
                try {
                    if ($this->cfg['francetravail']['client_id']) {
                        $offres = $ftApi->search($motCle, $lieu, $search['rayon_km']);
                        foreach ($offres as $o) {
                            $result = $this->storeOffre($o, $search['exclusions']);
                            if ($result === 'inserted') $counts['francetravail']++;
                            elseif ($result === 'skipped') $counts['skipped']++;
                        }
                    }
                } catch (Throwable $e) {
                    Logger::error('offres', "FT error [{$motCle}/{$lieu}]: " . $e->getMessage());
                    $counts['errors']++;
                }

                // Adzuna
                try {
                    if ($this->cfg['adzuna']['app_id']) {
                        $offres = $azApi->search($motCle, $lieu, $search['rayon_km']);
                        foreach ($offres as $o) {
                            $result = $this->storeOffre($o, $search['exclusions']);
                            if ($result === 'inserted') $counts['adzuna']++;
                            elseif ($result === 'skipped') $counts['skipped']++;
                        }
                    }
                } catch (Throwable $e) {
                    Logger::error('offres', "Adzuna error [{$motCle}/{$lieu}]: " . $e->getMessage());
                    $counts['errors']++;
                }

                usleep(300_000); // 300ms between calls
            }
        }

        return $counts;
    }

    private function storeOffre(array $o, array $exclusions): string
    {
        $titreNorm     = $this->normalize($o['titre']);
        $entrepriseNorm = $this->normalize($o['entreprise'] ?? '');

        // Filter exclusions
        foreach ($exclusions as $excl) {
            if (stripos($o['titre'], $excl) !== false || stripos($o['description'] ?? '', $excl) !== false) {
                return 'excluded';
            }
        }

        // Dedup by external_id
        if (!empty($o['external_id'])) {
            $exists = DB::fetch(
                'SELECT id FROM offres WHERE source = ? AND external_id = ?',
                [$o['source'], $o['external_id']]
            );
            if ($exists) return 'skipped';
        }

        // Dedup by normalized titre + entreprise (last 30 days)
        if ($titreNorm && $entrepriseNorm) {
            $exists = DB::fetch(
                'SELECT id FROM offres WHERE titre_normalized = ? AND entreprise_normalized = ? AND date_import > DATE_SUB(NOW(), INTERVAL 30 DAY)',
                [$titreNorm, $entrepriseNorm]
            );
            if ($exists) return 'skipped';
        }

        DB::insert(
            'INSERT INTO offres (source, external_id, titre, titre_normalized, entreprise, entreprise_normalized, lieu, url, description, date_publication)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $o['source'],
                $o['external_id'] ?: null,
                $o['titre'],
                $titreNorm,
                $o['entreprise'] ?: null,
                $entrepriseNorm ?: null,
                $o['lieu'] ?: null,
                $o['url'] ?: null,
                $o['description'] ?: null,
                $o['date_publication'] ?: null,
            ]
        );
        return 'inserted';
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim($s);
    }
}
