DB_PATH = "jobtracker.db"

SMTP = {
    "host": "smtp.gmail.com",
    "port": 587,
    "user": "sdouay1er@gmail.com",
    "pass": "",  # ← coller ici votre mot de passe d'application Gmail (16 caractères)
}

IMAP = {
    "host": "imap.gmail.com",
    "port": 993,
    "user": "sdouay1er@gmail.com",
    "pass": "",  # ← même mot de passe d'application Gmail
}

CANDIDAT = {
    "nom": "Stéphane Douay",
    "email": "sdouay1er@gmail.com",
    "telephone": "+33 6 62 84 60 78",
    "linkedin": "https://www.linkedin.com/in/stephanedouay/",
}

FRANCE_TRAVAIL = {
    "client_id": "",     # ← francetravail.io → Mon espace → votre application
    "client_secret": "", # ← idem
}

ADZUNA = {
    "app_id": "",   # ← developer.adzuna.com (facultatif)
    "app_key": "",  # ← idem
}

RECHERCHE = {
    "mots_cles": ["Support IT VIP", "Technicien support VIP", "IT Support COMEX", "Expert support informatique"],
    "lieux": ["75", "92", "93", "94", "95"],
    "rayon_km": 30,
    "exclusions": ["stage", "alternance", "apprentissage"],
}

INBOX = {
    "jours_historique": 7,
    "score_min": 2,
    "auto_repondre": False,
    "expediteurs_ignores": ["noreply@", "newsletter@", "no-reply@"],
}

RELANCES = {
    "delais": [7, 14],
    "sans_reponse": 10,
}

APP = {
    "secret_key": "645e4fb05f64814b4628004dfb665398",
    "admin_password_hash": "",  # généré automatiquement au premier accès
    "port": 5000,
    "debug": False,
    "cv_path": "storage/cv.pdf",
    "log_path": "storage/logs/app.log",
}
