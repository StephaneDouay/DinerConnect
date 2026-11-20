<?php
return [
  'db' => [
    'host'    => 'localhost',
    'name'    => 'jobtracker',
    'user'    => 'jobtracker',
    'pass'    => 'MOT_DE_PASSE_DB',
    'charset' => 'utf8mb4',
  ],
  'smtp' => [
    'host'      => 'smtp.gmail.com',
    'port'      => 587,
    'user'      => 'votre@gmail.com',
    'pass'      => 'xxxx xxxx xxxx xxxx',
    'from_name' => 'Stéphane Douay',
  ],
  'imap' => [
    'host' => 'imap.gmail.com',
    'port' => 993,
    'user' => 'votre@gmail.com',
    'pass' => 'xxxx xxxx xxxx xxxx',
  ],
  'francetravail' => [
    'client_id'     => '',
    'client_secret' => '',
  ],
  'adzuna' => [
    'app_id'  => '',
    'app_key' => '',
  ],
  'candidat' => [
    'nom'       => 'Stéphane Douay',
    'email'     => 'votre@gmail.com',
    'telephone' => '+33 6 XX XX XX XX',
    'linkedin'  => 'https://www.linkedin.com/in/votreprofil/',
  ],
  'recherche' => [
    'mots_cles'  => ['Support IT VIP', 'Technicien support VIP', 'IT Support COMEX'],
    'lieux'      => ['75', '92', '93', '94', '95'],
    'rayon_km'   => 30,
    'exclusions' => ['stage', 'alternance', 'apprentissage', 'interim'],
  ],
  'inbox' => [
    'dossier'             => 'INBOX',
    'jours_historique'    => 7,
    'score_min'           => 2,
    'auto_repondre'       => false,
    'expediteurs_ignores' => ['noreply@', 'newsletter@', 'no-reply@'],
  ],
  'relances' => [
    'delais'       => [7, 14],
    'sans_reponse' => 10,
  ],
  'app' => [
    'url'                  => 'https://jobs.votredomaine.fr',
    'cron_token'           => 'REMPLACER_PAR_32_CHARS_ALEATOIRES',
    'admin_password_hash'  => '',
    'timezone'             => 'Europe/Paris',
    'debug'                => false,
  ],
];
