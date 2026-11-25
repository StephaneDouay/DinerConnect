import hashlib, os

DB_PATH = "finance.db"

APP = {
    "secret_key": "f1n4nc3tr4ck3r2026s3cr3t",
    "admin_password_hash": "",  # généré automatiquement au premier accès
    "port": 5001,
    "debug": False,
}

REVENU_MENSUEL_DEFAUT = 1800
OBJECTIF_CUISINE = 10000  # 10 000 € dès que possible

FACTURES_DEFAUT = [
    {"nom": "Loyer",          "montant": 750,  "jour_echeance": 1,  "categorie": "logement"},
    {"nom": "EDF",            "montant": 80,   "jour_echeance": 10, "categorie": "energie"},
    {"nom": "Internet",       "montant": 35,   "jour_echeance": 5,  "categorie": "telecom"},
    {"nom": "Mobile",         "montant": 20,   "jour_echeance": 5,  "categorie": "telecom"},
    {"nom": "Assurance hab.", "montant": 25,   "jour_echeance": 1,  "categorie": "assurance"},
    {"nom": "Assurance auto", "montant": 60,   "jour_echeance": 1,  "categorie": "assurance"},
    {"nom": "Mutuelle",       "montant": 50,   "jour_echeance": 1,  "categorie": "sante"},
    {"nom": "Streaming",      "montant": 15,   "jour_echeance": 15, "categorie": "loisirs"},
]
