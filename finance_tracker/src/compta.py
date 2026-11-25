import io, csv
from datetime import date
from .database import get_conn

def ajouter_transaction(date_str, libelle, montant, type_, categorie='autre', note=''):
    conn = get_conn()
    conn.execute(
        "INSERT INTO transactions(date,libelle,montant,type,categorie,note) VALUES(?,?,?,?,?,?)",
        (date_str, libelle, float(montant), type_, categorie, note)
    )
    conn.commit()
    conn.close()

def supprimer_transaction(id_):
    conn = get_conn()
    conn.execute("DELETE FROM transactions WHERE id=?", (id_,))
    conn.commit()
    conn.close()

def get_transactions(mois=None, annee=None):
    conn = get_conn()
    if mois and annee:
        rows = conn.execute(
            "SELECT * FROM transactions WHERE strftime('%Y',date)=? AND strftime('%m',date)=? ORDER BY date DESC",
            (str(annee), str(mois).zfill(2))
        ).fetchall()
    else:
        rows = conn.execute("SELECT * FROM transactions ORDER BY date DESC LIMIT 200").fetchall()
    conn.close()
    return [dict(r) for r in rows]

def get_solde_mois(mois, annee):
    conn = get_conn()
    revenus = conn.execute(
        "SELECT COALESCE(SUM(montant),0) FROM transactions WHERE type='revenu' AND strftime('%Y',date)=? AND strftime('%m',date)=?",
        (str(annee), str(mois).zfill(2))
    ).fetchone()[0]
    depenses = conn.execute(
        "SELECT COALESCE(SUM(montant),0) FROM transactions WHERE type='depense' AND strftime('%Y',date)=? AND strftime('%m',date)=?",
        (str(annee), str(mois).zfill(2))
    ).fetchone()[0]
    conn.close()
    return {'revenus': revenus, 'depenses': depenses, 'solde': revenus - depenses}

def export_csv(mois=None, annee=None):
    rows = get_transactions(mois, annee)
    output = io.StringIO()
    writer = csv.DictWriter(output, fieldnames=['id','date','libelle','montant','type','categorie','note'])
    writer.writeheader()
    for r in rows:
        writer.writerow({k: r[k] for k in ['id','date','libelle','montant','type','categorie','note']})
    return output.getvalue()

CATEGORIES = ['salaire','freelance','autre_revenu','loyer','alimentation','transport',
              'sante','loisirs','vetements','energie','telecom','assurance','epargne','autre']
