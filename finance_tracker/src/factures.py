from datetime import date, datetime
from .database import get_conn

def get_factures(actif_only=True):
    conn = get_conn()
    q = "SELECT * FROM factures"
    if actif_only:
        q += " WHERE actif=1"
    q += " ORDER BY jour_echeance"
    rows = conn.execute(q).fetchall()
    conn.close()
    return [dict(r) for r in rows]

def ajouter_facture(nom, montant, jour_echeance, categorie='autre'):
    conn = get_conn()
    conn.execute(
        "INSERT INTO factures(nom,montant,jour_echeance,categorie) VALUES(?,?,?,?)",
        (nom, float(montant), int(jour_echeance), categorie)
    )
    conn.commit()
    conn.close()

def supprimer_facture(id_):
    conn = get_conn()
    conn.execute("UPDATE factures SET actif=0 WHERE id=?", (id_,))
    conn.commit()
    conn.close()

def get_mois_str(mois=None, annee=None):
    if mois and annee:
        return f"{annee}-{str(mois).zfill(2)}"
    return date.today().strftime('%Y-%m')

def get_paiements_mois(mois_str):
    conn = get_conn()
    rows = conn.execute(
        "SELECT pf.*, f.nom, f.montant, f.jour_echeance, f.categorie "
        "FROM paiements_factures pf JOIN factures f ON f.id=pf.facture_id "
        "WHERE pf.mois=? ORDER BY f.jour_echeance",
        (mois_str,)
    ).fetchall()
    conn.close()
    return [dict(r) for r in rows]

def init_paiements_mois(mois_str):
    """Crée les entrées de paiement pour toutes les factures actives du mois si absentes."""
    conn = get_conn()
    factures = conn.execute("SELECT id FROM factures WHERE actif=1").fetchall()
    for f in factures:
        conn.execute(
            "INSERT OR IGNORE INTO paiements_factures(facture_id,mois,statut) VALUES(?,?,'en_attente')",
            (f['id'], mois_str)
        )
    conn.commit()
    conn.close()

def marquer_payee(facture_id, mois_str):
    conn = get_conn()
    today = date.today().isoformat()
    conn.execute(
        "INSERT INTO paiements_factures(facture_id,mois,date_paiement,statut) VALUES(?,?,?,'payee') "
        "ON CONFLICT(facture_id,mois) DO UPDATE SET statut='payee', date_paiement=excluded.date_paiement",
        (facture_id, mois_str, today)
    )
    conn.commit()
    conn.close()

def marquer_non_payee(facture_id, mois_str):
    conn = get_conn()
    conn.execute(
        "UPDATE paiements_factures SET statut='en_attente', date_paiement=NULL "
        "WHERE facture_id=? AND mois=?",
        (facture_id, mois_str)
    )
    conn.commit()
    conn.close()

def get_total_factures():
    conn = get_conn()
    total = conn.execute("SELECT COALESCE(SUM(montant),0) FROM factures WHERE actif=1").fetchone()[0]
    conn.close()
    return total

def get_prochaines_echeances(n=5):
    today = date.today()
    mois_str = today.strftime('%Y-%m')
    init_paiements_mois(mois_str)
    conn = get_conn()
    rows = conn.execute(
        "SELECT f.nom, f.montant, f.jour_echeance, pf.statut "
        "FROM factures f LEFT JOIN paiements_factures pf ON pf.facture_id=f.id AND pf.mois=? "
        "WHERE f.actif=1 ORDER BY f.jour_echeance LIMIT ?",
        (mois_str, n)
    ).fetchall()
    conn.close()
    result = []
    for r in rows:
        d = r['jour_echeance']
        try:
            echeance = date(today.year, today.month, d)
        except ValueError:
            from calendar import monthrange
            echeance = date(today.year, today.month, monthrange(today.year, today.month)[1])
        result.append({
            'nom': r['nom'],
            'montant': r['montant'],
            'jour': d,
            'statut': r['statut'] or 'en_attente',
            'retard': echeance < today and (r['statut'] or '') != 'payee',
        })
    return result
