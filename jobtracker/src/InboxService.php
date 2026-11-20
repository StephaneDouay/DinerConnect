<?php
declare(strict_types=1);

class InboxService
{
    private array  $cfg;
    private string $stateFile;
    private Mailer $mailer;

    public function __construct(array $cfg, string $storageDir)
    {
        $this->cfg       = $cfg;
        $this->stateFile = rtrim($storageDir, '/') . '/imap_state.json';
        $this->mailer    = new Mailer($cfg);
    }

    public function analyzeInbox(): array
    {
        $imap = new ImapClient($this->cfg['imap']);
        $imap->connect();

        $state     = $this->loadState();
        $inboxCfg  = $this->cfg['inbox'];
        $counts    = ['analysed' => 0, 'status_updated' => 0, 'offres_detected' => 0];

        try {
            $info = $imap->select($inboxCfg['dossier']);
            $since = date('d-M-Y', strtotime("-{$inboxCfg['jours_historique']} days"));
            $uids  = $imap->search("SINCE {$since}");

            // Only process UIDs newer than last seen
            $lastUid  = $state['last_uid'] ?? 0;
            $newUids  = array_filter($uids, fn($u) => $u > $lastUid);

            foreach ($newUids as $uid) {
                $counts['analysed']++;
                try {
                    $headers = $imap->fetchHeaders($uid);
                    $from    = $headers['from'] ?? '';
                    $subject = $headers['subject'] ?? '';
                    $msgId   = $headers['message-id'] ?? '';
                    $inReply  = $headers['in-reply-to'] ?? '';
                    $refs     = $headers['references'] ?? '';

                    // Skip ignored senders
                    if ($this->isIgnored($from, $inboxCfg['expediteurs_ignores'])) {
                        continue;
                    }

                    // Try to match to existing candidature
                    $cand = $this->matchCandidature($from, $inReply, $refs);

                    if ($cand) {
                        $body = $imap->fetchBody($uid);
                        $newStatus = $this->detectStatusChange($subject, $body);
                        if ($newStatus && $newStatus !== $cand['statut']) {
                            DB::execute(
                                'UPDATE candidatures SET statut = ?, date_derniere_action = NOW() WHERE id = ?',
                                [$newStatus, $cand['id']]
                            );
                            DB::insert(
                                'INSERT INTO messages (candidature_id, direction, sujet, corps, expediteur, message_id_header, date_message)
                                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                                [$cand['id'], 'entrant', $subject, substr($body, 0, 5000), $from, $msgId]
                            );
                            $this->sendAlert($cand, $newStatus, $from, $subject);
                            $counts['status_updated']++;
                        }
                    } else {
                        // Check if incoming job offer
                        $body  = $imap->fetchBody($uid);
                        $score = $this->scoreJobOffer($subject, $body, $this->cfg['recherche']['mots_cles']);
                        if ($score >= $inboxCfg['score_min']) {
                            $this->createOffreFromEmail($from, $subject, $body, $msgId);
                            $counts['offres_detected']++;
                        }
                    }

                    $lastUid = max($lastUid, $uid);
                } catch (Throwable $e) {
                    Logger::warning('inbox', "Error processing UID {$uid}: " . $e->getMessage());
                }
            }

            $state['last_uid']     = $lastUid;
            $state['last_run']     = date('Y-m-d H:i:s');
            $state['uidvalidity']  = $info['uidvalidity'];
            $this->saveState($state);

        } finally {
            $imap->disconnect();
        }

        return $counts;
    }

    private function matchCandidature(string $from, string $inReply, string $refs): ?array
    {
        // Match by Message-ID in references or in-reply-to
        $ids = array_filter(array_map('trim', explode(' ', "$inReply $refs")));
        foreach ($ids as $id) {
            if (!$id) continue;
            $cand = DB::fetch(
                'SELECT c.*, o.titre, o.entreprise FROM candidatures c
                 LEFT JOIN offres o ON o.id = c.offre_id
                 JOIN messages m ON m.candidature_id = c.id
                 WHERE m.message_id_header = ? LIMIT 1',
                [$id]
            );
            if ($cand) return $cand;
        }

        // Match by sender email
        $email = $this->extractEmail($from);
        if ($email) {
            return DB::fetch(
                'SELECT c.*, o.titre, o.entreprise FROM candidatures c
                 LEFT JOIN offres o ON o.id = c.offre_id
                 WHERE c.recruteur_email = ? AND c.statut NOT IN (?,?,?) ORDER BY c.date_creation DESC LIMIT 1',
                [$email, 'sans_reponse', 'refus', 'acceptee']
            );
        }

        return null;
    }

    private function detectStatusChange(string $subject, string $body): ?string
    {
        $text = strtolower($subject . ' ' . $body);

        $entretienKw = ['entretien', 'interview', 'rencontrer', 'convocation', 'rendez-vous', 'disponibilité'];
        $refusKw     = ['refus', 'retenu', 'candidature n\'a pas été', 'ne correspond pas', 'ne pas retenir', 'regret'];
        $reponseKw   = ['votre candidature', 'nous avons bien reçu', 'pris connaissance', 'intéresse', 'profil correspond'];

        foreach ($entretienKw as $kw) {
            if (str_contains($text, $kw)) return 'entretien';
        }
        foreach ($refusKw as $kw) {
            if (str_contains($text, $kw)) return 'refus';
        }
        foreach ($reponseKw as $kw) {
            if (str_contains($text, $kw)) return 'reponse_recue';
        }
        return 'reponse_recue'; // Default if matched candidature
    }

    private function scoreJobOffer(string $subject, string $body, array $keywords): int
    {
        $text  = strtolower($subject . ' ' . $body);
        $score = 0;
        foreach ($keywords as $kw) {
            if (str_contains($text, strtolower($kw))) $score++;
        }
        $offerKw = ['poste', 'opportunité', 'recrutement', 'mission', 'offre d\'emploi', 'cdi', 'cdd'];
        foreach ($offerKw as $kw) {
            if (str_contains($text, $kw)) $score++;
        }
        return $score;
    }

    private function createOffreFromEmail(string $from, string $subject, string $body, string $msgId): void
    {
        $offre_id = DB::insert(
            'INSERT INTO offres (source, titre, titre_normalized, entreprise, entreprise_normalized, description)
             VALUES (?, ?, ?, ?, ?, ?)',
            ['email', $subject, $this->normalize($subject), $this->extractEmail($from) ?: $from, $this->normalize($from), substr($body, 0, 5000)]
        );

        $template = DB::fetch("SELECT * FROM templates WHERE nom = 'offre_entrante' LIMIT 1");
        $candId   = DB::insert(
            'INSERT INTO candidatures (offre_id, statut, recruteur_email, canal) VALUES (?, ?, ?, ?)',
            [$offre_id, 'brouillon', $this->extractEmail($from), 'email']
        );

        if ($template) {
            DB::insert(
                'INSERT INTO messages (candidature_id, direction, sujet, corps, expediteur, message_id_header, date_message)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$candId, 'entrant', $subject, substr($body, 0, 5000), $from, $msgId]
            );
        }

        // Auto-reply if configured
        if ($this->cfg['inbox']['auto_repondre'] && $template && $this->extractEmail($from)) {
            $offre  = DB::fetch('SELECT * FROM offres WHERE id = ?', [$offre_id]);
            $corps  = $this->fillTemplate($template['corps'], $offre);
            $sujet  = $this->fillTemplate($template['sujet'], $offre);
            $cvPath = dirname(__DIR__) . '/storage/cv.pdf';
            $atts   = is_file($cvPath) ? [['path' => $cvPath, 'name' => 'CV_Stephane_Douay.pdf']] : [];
            $newMsgId = $this->mailer->send($this->extractEmail($from), $sujet, $corps, $atts, ['In-Reply-To' => $msgId]);
            DB::execute('UPDATE candidatures SET statut = ?, date_envoi = NOW(), message_id_smtp = ? WHERE id = ?',
                ['envoyee', $newMsgId, $candId]);
        }
    }

    private function sendAlert(array $cand, string $newStatus, string $from, string $subject): void
    {
        $labels = [
            'reponse_recue' => 'Réponse reçue',
            'entretien'     => '🎉 Entretien !',
            'refus'         => 'Refus',
        ];
        $label   = $labels[$newStatus] ?? $newStatus;
        $titre   = $cand['titre'] ?? 'N/A';
        $alertTo = $this->cfg['candidat']['email'];
        $appUrl  = $this->cfg['app']['url'];
        $body    = "<p><strong>{$label}</strong> pour votre candidature : <em>{$titre}</em></p>"
                 . "<p>De : {$from}<br>Sujet : {$subject}</p>"
                 . "<p><a href='{$appUrl}/?tab=candidatures'>Voir dans JobTracker</a></p>";
        try {
            $this->mailer->send($alertTo, "[JobTracker] {$label} – {$titre}", $body);
        } catch (Throwable $e) {
            Logger::warning('inbox', 'Alert email failed: ' . $e->getMessage());
        }
    }

    private function extractEmail(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) return strtolower(trim($m[1]));
        if (filter_var(trim($from), FILTER_VALIDATE_EMAIL)) return strtolower(trim($from));
        return '';
    }

    private function isIgnored(string $from, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (str_contains(strtolower($from), strtolower($p))) return true;
        }
        return false;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim($s);
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

    private function loadState(): array
    {
        if (is_file($this->stateFile)) {
            $data = json_decode(file_get_contents($this->stateFile), true);
            return is_array($data) ? $data : [];
        }
        return [];
    }

    private function saveState(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
