<?php
declare(strict_types=1);

class DigestService
{
    private array  $cfg;
    private Mailer $mailer;

    public function __construct(array $cfg)
    {
        $this->cfg    = $cfg;
        $this->mailer = new Mailer($cfg);
    }

    public function sendDigest(): bool
    {
        $to   = $this->cfg['candidat']['email'];
        $nom  = $this->cfg['candidat']['nom'];
        $url  = $this->cfg['app']['url'];
        $date = date('d/m/Y');

        // Pending replies (reponse_recue)
        $reponses = DB::fetchAll(
            "SELECT c.id, o.titre, o.entreprise, c.recruteur_email, c.date_derniere_action
             FROM candidatures c LEFT JOIN offres o ON o.id = c.offre_id
             WHERE c.statut = 'reponse_recue'
             ORDER BY c.date_derniere_action DESC LIMIT 20"
        );

        // Brouillons to send
        $brouillons = DB::fetchAll(
            "SELECT c.id, o.titre, o.entreprise, c.date_creation
             FROM candidatures c LEFT JOIN offres o ON o.id = c.offre_id
             WHERE c.statut = 'brouillon'
             ORDER BY c.date_creation DESC LIMIT 20"
        );

        // New offers since yesterday
        $nouvellesOffres = DB::fetchAll(
            "SELECT id, titre, entreprise, lieu, source, date_import FROM offres
             WHERE date_import >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND vue = 0
             ORDER BY date_import DESC LIMIT 30"
        );

        // Stats
        $stats = DB::fetchAll("SELECT statut, COUNT(*) as n FROM candidatures GROUP BY statut");
        $statsMap = [];
        foreach ($stats as $s) $statsMap[$s['statut']] = $s['n'];

        $html  = "<!DOCTYPE html><html><body style='font-family:sans-serif;color:#222;max-width:700px;margin:auto'>";
        $html .= "<h2 style='color:#c9a96e'>📋 JobTracker – Récapitulatif du {$date}</h2>";
        $html .= "<p>Bonjour {$nom},</p>";

        // Stats bar
        $html .= "<table style='width:100%;border-collapse:collapse;margin-bottom:1.5em'><tr>";
        $statLabels = ['envoyee' => 'Envoyées', 'entretien' => 'Entretiens', 'reponse_recue' => 'À traiter', 'sans_reponse' => 'Sans réponse'];
        foreach ($statLabels as $k => $l) {
            $n    = $statsMap[$k] ?? 0;
            $col  = $k === 'entretien' ? '#4ade80' : ($k === 'reponse_recue' ? '#facc15' : '#c9a96e');
            $html .= "<td style='padding:12px;text-align:center;border:1px solid #eee'><strong style='font-size:1.5em;color:{$col}'>{$n}</strong><br>{$l}</td>";
        }
        $html .= "</tr></table>";

        if ($reponses) {
            $html .= "<h3 style='color:#facc15'>⚠️ Réponses à traiter (" . count($reponses) . ")</h3><ul>";
            foreach ($reponses as $r) {
                $html .= "<li><a href='{$url}/?tab=candidatures'><strong>{$r['titre']}</strong> – {$r['entreprise']}</a> (de {$r['recruteur_email']})</li>";
            }
            $html .= "</ul>";
        }

        if ($brouillons) {
            $html .= "<h3 style='color:#93c5fd'>📝 Brouillons à envoyer (" . count($brouillons) . ")</h3><ul>";
            foreach ($brouillons as $b) {
                $html .= "<li><a href='{$url}/?tab=candidatures'>{$b['titre']}</a> – {$b['entreprise']}</li>";
            }
            $html .= "</ul>";
        }

        if ($nouvellesOffres) {
            $html .= "<h3 style='color:#86efac'>🆕 Nouvelles offres (" . count($nouvellesOffres) . ")</h3><ul>";
            foreach ($nouvellesOffres as $o) {
                $html .= "<li><strong>{$o['titre']}</strong> – {$o['entreprise']} <em>({$o['lieu']})</em> <a href='{$url}/?tab=offres&id={$o['id']}'>Postuler</a></li>";
            }
            $html .= "</ul>";
        }

        if (!$reponses && !$brouillons && !$nouvellesOffres) {
            $html .= "<p>Rien de nouveau aujourd'hui. Bonne journée !</p>";
        }

        $html .= "<p style='margin-top:2em;font-size:.8em;color:#888'><a href='{$url}'>Accéder à JobTracker</a></p></body></html>";

        $this->mailer->send($to, "[JobTracker] Récapitulatif du {$date}", $html);
        Logger::info('digest', "Digest envoyé à {$to}");
        return true;
    }
}
