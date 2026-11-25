# Copier ce fichier en config.py et remplir vos informations

DB_PATH = "jobtracker.db"

SMTP = {
    "host": "smtp.gmail.com",
    "port": 587,
    "user": "votre@gmail.com",
    "pass": "xxxx xxxx xxxx xxxx",  # mot de passe d'application Gmail
}

IMAP = {
    "host": "imap.gmail.com",
    "port": 993,
    "user": "votre@gmail.com",
    "pass": "xxxx xxxx xxxx xxxx",
}

CANDIDAT = {
    "nom": "Stéphane Douay",
    "email": "votre@gmail.com",
    "telephone": "+33 6 XX XX XX XX",
    "linkedin": "https://www.linkedin.com/in/votreprofil/",
}

FRANCE_TRAVAIL = {
    "client_id": "",
    "client_secret": "",
}

ADZUNA = {
    "app_id": "",
    "app_key": "",
}

RECHERCHE = {
    "mots_cles": ["Support IT VIP", "Technicien support VIP", "IT Support COMEX"],
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
    "secret_key": "CHANGER_CE_SECRET_32_CHARS",
    "admin_password_hash": "",  # généré au premier accès
    "port": 5000,
    "debug": False,
    "cv_path": "storage/cv.pdf",
    "log_path": "storage/logs/app.log",
}
