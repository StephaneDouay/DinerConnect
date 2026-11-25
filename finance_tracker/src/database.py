import sqlite3, os, sys
from datetime import datetime, date
from dateutil.relativedelta import relativedelta

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
import config

def get_conn():
    conn = sqlite3.connect(config.DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    return conn

def init_db():
    schema = os.path.join(os.path.dirname(__file__), '..', 'db', 'schema.sql')
    conn = get_conn()
    with open(schema) as f:
        conn.executescript(f.read())
    conn.commit()

    # Seed factures par défaut si table vide
    cur = conn.execute("SELECT COUNT(*) FROM factures")
    if cur.fetchone()[0] == 0:
        for f in config.FACTURES_DEFAUT:
            conn.execute(
                "INSERT INTO factures(nom,montant,jour_echeance,categorie) VALUES(?,?,?,?)",
                (f['nom'], f['montant'], f['jour_echeance'], f['categorie'])
            )

    # Seed objectif cuisine si table vide
    cur = conn.execute("SELECT COUNT(*) FROM epargne_objectifs")
    if cur.fetchone()[0] == 0:
        date_cible = (date.today() + relativedelta(months=12)).isoformat()
        conn.execute(
            "INSERT INTO epargne_objectifs(nom,montant_cible,date_cible,description) VALUES(?,?,?,?)",
            ("Remplacement cuisine", config.OBJECTIF_CUISINE, date_cible, "Projet rénovation cuisine complète")
        )

    # Seed revenu mensuel par défaut
    cur = conn.execute("SELECT valeur FROM settings WHERE cle='revenu_mensuel'")
    if cur.fetchone() is None:
        conn.execute(
            "INSERT INTO settings(cle,valeur) VALUES('revenu_mensuel',?)",
            (str(config.REVENU_MENSUEL_DEFAUT),)
        )

    conn.commit()
    conn.close()

def get_setting(cle, defaut=None):
    conn = get_conn()
    row = conn.execute("SELECT valeur FROM settings WHERE cle=?", (cle,)).fetchone()
    conn.close()
    return row['valeur'] if row else defaut

def set_setting(cle, valeur):
    conn = get_conn()
    conn.execute(
        "INSERT INTO settings(cle,valeur) VALUES(?,?) ON CONFLICT(cle) DO UPDATE SET valeur=excluded.valeur",
        (cle, str(valeur))
    )
    conn.commit()
    conn.close()
