CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT NOT NULL,
    libelle TEXT NOT NULL,
    montant REAL NOT NULL,
    type TEXT NOT NULL CHECK(type IN ('revenu','depense')),
    categorie TEXT DEFAULT 'autre',
    note TEXT DEFAULT '',
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS factures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nom TEXT NOT NULL,
    montant REAL NOT NULL,
    jour_echeance INTEGER NOT NULL,
    categorie TEXT DEFAULT 'autre',
    actif INTEGER DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS paiements_factures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    facture_id INTEGER NOT NULL,
    mois TEXT NOT NULL,
    date_paiement TEXT,
    statut TEXT DEFAULT 'en_attente' CHECK(statut IN ('en_attente','payee')),
    FOREIGN KEY(facture_id) REFERENCES factures(id),
    UNIQUE(facture_id, mois)
);

CREATE TABLE IF NOT EXISTS epargne_objectifs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nom TEXT NOT NULL,
    montant_cible REAL NOT NULL,
    montant_actuel REAL DEFAULT 0,
    date_cible TEXT,
    description TEXT DEFAULT '',
    actif INTEGER DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS epargne_versements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    objectif_id INTEGER NOT NULL,
    date TEXT NOT NULL,
    montant REAL NOT NULL,
    note TEXT DEFAULT '',
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY(objectif_id) REFERENCES epargne_objectifs(id)
);

CREATE TABLE IF NOT EXISTS settings (
    cle TEXT PRIMARY KEY,
    valeur TEXT
);
