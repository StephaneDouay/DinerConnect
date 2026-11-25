-- JobTracker schema
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS offres (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source           ENUM('francetravail','adzuna','manuel','email') NOT NULL DEFAULT 'manuel',
  external_id      VARCHAR(128) DEFAULT NULL,
  titre            VARCHAR(255) NOT NULL,
  titre_normalized VARCHAR(255) NOT NULL,
  entreprise       VARCHAR(255) DEFAULT NULL,
  entreprise_normalized VARCHAR(255) DEFAULT NULL,
  lieu             VARCHAR(128) DEFAULT NULL,
  url              TEXT DEFAULT NULL,
  description      MEDIUMTEXT DEFAULT NULL,
  date_publication DATE DEFAULT NULL,
  date_import      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  vue              TINYINT(1) NOT NULL DEFAULT 0,
  INDEX idx_source_extid (source, external_id(64)),
  INDEX idx_titre_norm (titre_normalized(100), entreprise_normalized(100)),
  INDEX idx_date_import (date_import)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS templates (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom     VARCHAR(100) NOT NULL,
  sujet   VARCHAR(255) NOT NULL,
  corps   MEDIUMTEXT NOT NULL,
  INDEX idx_nom (nom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO templates (nom, sujet, corps) VALUES
('candidature',
 'Candidature au poste de {titre} – {nom}',
 '<p>Madame, Monsieur,</p>
<p>Ayant pris connaissance de votre offre pour le poste de <strong>{titre}</strong> chez <strong>{entreprise}</strong>, je me permets de vous adresser ma candidature.</p>
<p>Fort de 25 ans d'expérience en support informatique VIP et COMEX auprès de grandes entreprises (Sanofi, ArianeGroup, SPIE ICS), je suis convaincu de pouvoir répondre pleinement à vos attentes.</p>
<p>Vous trouverez ci-joint mon CV. Je reste disponible pour un entretien à votre convenance.</p>
<p>Cordialement,<br>{nom}<br>{telephone}<br>{linkedin}</p>'),
('relance1',
 'Relance – Candidature {titre} – {nom}',
 '<p>Madame, Monsieur,</p>
<p>Je me permets de relancer ma candidature au poste de <strong>{titre}</strong> que je vous ai adressée le {date_envoi}.</p>
<p>Mon intérêt pour ce poste reste intact et je serais ravi d'échanger avec vous à ce sujet.</p>
<p>Cordialement,<br>{nom}<br>{telephone}</p>'),
('relance2',
 'Deuxième relance – {titre} – {nom}',
 '<p>Madame, Monsieur,</p>
<p>Je me permets de vous recontacter une dernière fois concernant ma candidature au poste de <strong>{titre}</strong>.</p>
<p>Je reste disponible pour tout entretien ou complément d'information.</p>
<p>Cordialement,<br>{nom}<br>{telephone}</p>'),
('offre_entrante',
 'Réponse à votre proposition – {titre}',
 '<p>Madame, Monsieur,</p>
<p>Merci pour votre message concernant le poste de <strong>{titre}</strong>.</p>
<p>Cette opportunité m'intéresse vivement. Je vous adresse ci-joint mon CV et reste disponible pour un entretien.</p>
<p>Cordialement,<br>{nom}<br>{telephone}<br>{linkedin}</p>');

CREATE TABLE IF NOT EXISTS candidatures (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  offre_id             INT UNSIGNED DEFAULT NULL,
  statut               ENUM('brouillon','envoyee','relance1','relance2','reponse_recue','entretien','refus','sans_reponse','acceptee') NOT NULL DEFAULT 'brouillon',
  recruteur_email      VARCHAR(255) DEFAULT NULL,
  recruteur_nom        VARCHAR(255) DEFAULT NULL,
  canal                ENUM('email','linkedin','indeed','manuel') NOT NULL DEFAULT 'email',
  message_id_smtp      VARCHAR(512) DEFAULT NULL,
  date_envoi           DATETIME DEFAULT NULL,
  date_relance1        DATETIME DEFAULT NULL,
  date_relance2        DATETIME DEFAULT NULL,
  date_derniere_action DATETIME DEFAULT NULL,
  note                 TEXT DEFAULT NULL,
  date_creation        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (offre_id) REFERENCES offres(id) ON DELETE SET NULL,
  INDEX idx_statut (statut),
  INDEX idx_date_envoi (date_envoi),
  INDEX idx_recruteur_email (recruteur_email(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidature_id   INT UNSIGNED NOT NULL,
  direction        ENUM('sortant','entrant') NOT NULL,
  sujet            VARCHAR(500) DEFAULT NULL,
  corps            MEDIUMTEXT DEFAULT NULL,
  expediteur       VARCHAR(255) DEFAULT NULL,
  destinataire     VARCHAR(255) DEFAULT NULL,
  message_id_header VARCHAR(512) DEFAULT NULL,
  date_message     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (candidature_id) REFERENCES candidatures(id) ON DELETE CASCADE,
  INDEX idx_cand_id (candidature_id),
  INDEX idx_message_id (message_id_header(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS logs (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  level        ENUM('info','warning','error','debug') NOT NULL DEFAULT 'info',
  job          VARCHAR(50) NOT NULL,
  message      TEXT NOT NULL,
  context      TEXT DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_job (job),
  INDEX idx_level (level),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  `key`   VARCHAR(100) PRIMARY KEY,
  `value` TEXT DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
