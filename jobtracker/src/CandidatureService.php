<?php
declare(strict_types=1);

class CandidatureService
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

    public function create(int $offreId, int $templateId = 0): int
    {
        $offre = DB::fetch('SELECT * FROM offres WHERE id = ?', [$offreId]);
        if (!$offre) throw new RuntimeException("Offre {$offreId} introuvable");

        if (!$templateId) {
            $tmpl = DB::fetch("SELECT id FROM templates WHERE nom = 'candidature' LIMIT 1");
            $templateId = $tmpl ? (int)$tmpl['id'] : 0;
        }

        $candId = DB::insert(
            'INSERT INTO candidatures (offre_id, statut, canal) VALUES (?, ?, ?)',
            [$offreId, 'brouillon', 'email']
        );

        if ($templateId) {
            $template = DB::fetch('SELECT * FROM templates WHERE id = ?', [$templateId]);
            if ($template) {
                $corps = $this->fillTemplate($template['corps'], $offre);
                $sujet = $this->fillTemplate($template['sujet'], $offre);
                DB::insert(
                    'INSERT INTO messages (candidature_id, direction, sujet, corps, date_message) VALUES (?, ?, ?, ?, NOW())',
                    [$candId, 'sortant', $sujet, $corps]
                );
            }
        }

        return $candId;
    }

    public function send(int $candId, string $recruteurEmail): string
    {
        $cand = DB::fetch(
            'SELECT c.*, o.titre, o.entreprise FROM candidatures c LEFT JOIN offres o ON o.id = c.offre_id WHERE c.id = ?',
            [$candId]
        );
        if (!$cand) throw new RuntimeException("Candidature {$candId} introuvable");
        if ($cand['statut'] !== 'brouillon') throw new RuntimeException("Candidature déjà envoyée");

        // Get latest draft message
        $msg = DB::fetch(
            'SELECT * FROM messages WHERE candidature_id = ? AND direction = ? ORDER BY id DESC LIMIT 1',
            [$candId, 'sortant']
        );

        if (!$msg) {
            // Build from default template
            $offre    = DB::fetch('SELECT * FROM offres WHERE id = ?', [$cand['offre_id']]);
            $template = DB::fetch("SELECT * FROM templates WHERE nom = 'candidature' LIMIT 1");
            $corps    = $this->fillTemplate($template['corps'] ?? '', $offre);
            $sujet    = $this->fillTemplate($template['sujet'] ?? 'Candidature – ' . $cand['nom'], $offre);
        } else {
            $corps = $msg['corps'];
            $sujet = $msg['sujet'];
        }

        $cvPath = $this->storageDir . '/cv.pdf';
        $atts   = is_file($cvPath) ? [['path' => $cvPath, 'name' => 'CV_Stephane_Douay.pdf']] : [];

        $msgId = $this->mailer->send($recruteurEmail, $sujet, $corps, $atts);

        DB::execute(
            'UPDATE candidatures SET statut = ?, recruteur_email = ?, date_envoi = NOW(), date_derniere_action = NOW(), message_id_smtp = ? WHERE id = ?',
            ['envoyee', $recruteurEmail, $msgId, $candId]
        );

        // Record sent message with Message-ID
        if ($msg) {
            DB::execute('UPDATE messages SET message_id_header = ? WHERE id = ?', [$msgId, $msg['id']]);
        }

        Logger::info('candidature', "Envoyée #{$candId} à {$recruteurEmail}");
        return $msgId;
    }

    public function getStats(): array
    {
        $rows = DB::fetchAll(
            'SELECT statut, COUNT(*) as n FROM candidatures GROUP BY statut'
        );
        $stats = ['total' => 0, 'brouillon' => 0, 'envoyee' => 0, 'reponse_recue' => 0, 'entretien' => 0, 'refus' => 0, 'sans_reponse' => 0, 'acceptee' => 0];
        foreach ($rows as $r) {
            $stats[$r['statut']] = (int)$r['n'];
            $stats['total'] += (int)$r['n'];
        }
        return $stats;
    }

    private function fillTemplate(string $tpl, ?array $offre): string
    {
        $cand = $this->cfg['candidat'];
        $vars = [
            '{titre}'       => $offre['titre'] ?? '',
            '{entreprise}'  => $offre['entreprise'] ?? '',
            '{nom}'         => $cand['nom'],
            '{telephone}'   => $cand['telephone'],
            '{linkedin}'    => $cand['linkedin'],
            '{date_envoi}'  => date('d/m/Y'),
        ];
        return str_replace(array_keys($vars), array_values($vars), $tpl);
    }
}
