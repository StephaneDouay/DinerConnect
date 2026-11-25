CREATE TABLE IF NOT EXISTS offres (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    external_id TEXT NOT NULL,
    titre TEXT NOT NULL,
    entreprise TEXT,
    lieu TEXT,
    url TEXT,
    description TEXT,
    date_pub TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    statut TEXT DEFAULT 'nouveau',
    UNIQUE(source, external_id)
);

CREATE TABLE IF NOT EXISTS candidatures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    offre_id INTEGER REFERENCES offres(id),
    titre TEXT,
    entreprise TEXT,
    canal TEXT DEFAULT 'email',
    statut TEXT DEFAULT 'brouillon',
    recruteur_email TEXT,
    date_candidature TEXT DEFAULT (datetime('now')),
    notes TEXT,
    message_id_smtp TEXT
);

CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    candidature_id INTEGER NOT NULL REFERENCES candidatures(id),
    direction TEXT NOT NULL,
    sujet TEXT,
    corps TEXT,
    date_envoi TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nom TEXT NOT NULL,
    sujet TEXT NOT NULL,
    corps TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    level TEXT NOT NULL,
    job TEXT,
    message TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
);

INSERT OR IGNORE INTO templates (id, nom, sujet, corps) VALUES
(1, 'Candidature spontanée',
 'Candidature au poste de {titre} – {nom}',
 'Madame, Monsieur,\n\nPassionné par le support IT VIP et fort de 25 ans d''expérience auprès de Directions Générales et Comités Exécutifs (Sanofi, ArianeGroup, SPIE ICS), je me permets de vous adresser ma candidature pour le poste de {titre}.\n\nJe suis disponible immédiatement pour un entretien à votre convenance.\n\nCordialement,\n{nom}\n{telephone}\n{linkedin}'),
(2, 'Réponse à offre entrante',
 'Re: {sujet}',
 'Madame, Monsieur,\n\nJe vous remercie pour votre message concernant le poste de {titre}.\n\nFort de 25 ans d''expérience en support IT VIP et bilingue FR/EN, je serais ravi d''échanger sur cette opportunité.\n\nCordialement,\n{nom}\n{telephone}'),
(3, 'Relance J+7',
 'Relance – {titre} chez {entreprise}',
 'Madame, Monsieur,\n\nJe me permets de revenir vers vous suite à ma candidature du {date} pour le poste de {titre}.\n\nToujours très intéressé par cette opportunité, je reste disponible pour un entretien.\n\nCordialement,\n{nom}'),
(4, 'Relance J+14',
 'Deuxième relance – {titre} chez {entreprise}',
 'Madame, Monsieur,\n\nJe me permets de vous relancer une dernière fois concernant ma candidature du {date} pour le poste de {titre}.\n\nJe reste à votre disposition pour tout complément d''information.\n\nCordialement,\n{nom}');
