<?php
declare(strict_types=1);

class RelanceService
{
    private array  $cfg;
    private Mailer $mailer;
    private string $storageDir;

    public function __construct(array $cfg, string $storageDir)
    {
        $this->cfg        = $cfg;
        $this->mailer     = new Mailer($cfg);
        $this->storageDir = rtrim($storageDir, '/');
    }

    public function processRelances(): array
    {
        $delais = $this->cfg['relances']['delais'];
        $delay1 = $delais[0] ?? 7;
        $delay2 = $delais[1] ?? 14;
        $noResp = $this->cfg['relances']['sans_reponse'] ?? 10;
        $counts = ['relance1' => 0, 'relance2' => 0, 'sans_reponse' => 0];

        // Relance 1: envoyée il y a $delay1 jours, pas encore relancée
        $cands1 = DB::fetchAll(
            "SELECT c.*, o.titre, o.entreprise FROM candidatures c
             LEFT JOIN offres o ON o.id = c.offre_id
             WHERE c.statut = 'envoyee'
               AND c.date_envoi <= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND c.date_relance1 IS NULL
               AND c.recruteur_email IS NOT NULL",
            [$delay1]
        );

        foreach ($cands1 as $cand) {
            if ($this->sendRelance($cand, 'relance1')) {
                DB::execute(
                    "UPDATE candidatures SET statut = 'relance1', date_relance1 = NOW(), date_derniere_action = NOW() WHERE id = ?",
                    [$cand['id']]
                );
                $counts['relance1']++;
            }
        }

        // Relance 2: relancée il y a $delay2-$delay1 jours
        $gap = $delay2 - $delay1;
        $cands2 = DB::fetchAll(
            "SELECT c.*, o.titre, o.entreprise FROM candidatures c
             LEFT JOIN offres o ON o.id = c.offre_id
             WHERE c.statut = 'relance1'
               AND c.date_relance1 <= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND c.date_relance2 IS NULL
               AND c.recruteur_email IS NOT NULL",
            [$gap]
        );

        foreach ($cands2 as $cand) {
            if ($this->sendRelance($cand, 'relance2')) {
                DB::execute(
                    "UPDATE candidatures SET statut = 'relance2', date_relance2 = NOW(), date_derniere_action = NOW() WHERE id = ?",
                    [$cand['id']]
                );
                $counts['relance2']++;
            }
        }

        // Mark sans_reponse: relance2 envoyée il y a $noResp jours
        DB::execute(
            "UPDATE candidatures SET statut = 'sans_reponse', date_derniere_action = NOW()
             WHERE statut = 'relance2'
               AND date_relance2 <= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$noResp]
        );
        $counts['sans_reponse'] = DB::fetch('SELECT ROW_COUNT() as n')['n'] ?? 0;

        return $counts;
    }

    private function sendRelance(array $cand, string $type): bool
    {
        $template = DB::fetch("SELECT * FROM templates WHERE nom = ? LIMIT 1", [$type]);
        if (!$template) {
            Logger::warning('relances', "Template {$type} introuvable");
            return false;
        }

        $corps    = $this->fillTemplate($template['corps'], $cand);
        $sujet    = $this->fillTemplate($template['sujet'], $cand);
        $cvPath   = $this->storageDir . '/cv.pdf';
        $atts     = is_file($cvPath) ? [['path' => $cvPath, 'name' => 'CV_Stephane_Douay.pdf']] : [];

        // Thread the reply using original message-id
        $headers = [];
        if ($cand['message_id_smtp']) {
            $headers['In-Reply-To'] = $cand['message_id_smtp'];
            $headers['References']  = $cand['message_id_smtp'];
        }

        try {
            $msgId = $this->mailer->send($cand['recruteur_email'], $sujet, $corps, $atts, $headers);
            DB::insert(
                'INSERT INTO messages (candidature_id, direction, sujet, corps, destinataire, message_id_header, date_message)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$cand['id'], 'sortant', $sujet, $corps, $cand['recruteur_email'], $msgId]
            );
            Logger::info('relances', "Relance {$type} envoyée pour candidature #{$cand['id']}");
            return true;
        } catch (Throwable $e) {
            Logger::error('relances', "Échec relance #{$cand['id']}: " . $e->getMessage());
            return false;
        }
    }

    private function fillTemplate(string $tpl, array $cand): string
    {
        $cfg  = $this->cfg['candidat'];
        $vars = [
            '{titre}'       => $cand['titre'] ?? '',
            '{entreprise}'  => $cand['entreprise'] ?? '',
            '{nom}'         => $cfg['nom'],
            '{telephone}'   => $cfg['telephone'],
            '{linkedin}'    => $cfg['linkedin'],
            '{date_envoi}'  => $cand['date_envoi'] ? date('d/m/Y', strtotime($cand['date_envoi'])) : '',
        ];
        return str_replace(array_keys($vars), array_values($vars), $tpl);
    }
}
