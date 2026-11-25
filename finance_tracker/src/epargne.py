from datetime import date
from .database import get_conn

def get_objectifs():
    conn = get_conn()
    rows = conn.execute("SELECT * FROM epargne_objectifs WHERE actif=1 ORDER BY created_at").fetchall()
    conn.close()
    result = []
    for r in rows:
        obj = dict(r)
        total_verse = get_total_verse(obj['id'])
        obj['montant_actuel'] = total_verse
        obj['reste'] = max(0, obj['montant_cible'] - total_verse)
        obj['pct'] = round(min(100, total_verse / obj['montant_cible'] * 100), 1) if obj['montant_cible'] > 0 else 0
        # Mois restants
        if obj['date_cible']:
            cible = date.fromisoformat(obj['date_cible'])
            today = date.today()
            mois_restants = max(1, (cible.year - today.year) * 12 + (cible.month - today.month))
            obj['mois_restants'] = mois_restants
            obj['mensualite_requise'] = round(obj['reste'] / mois_restants, 2)
        else:
            obj['mois_restants'] = None
            obj['mensualite_requise'] = None
        result.append(obj)
    return result

def get_total_verse(objectif_id):
    conn = get_conn()
    total = conn.execute(
        "SELECT COALESCE(SUM(montant),0) FROM epargne_versements WHERE objectif_id=?",
        (objectif_id,)
    ).fetchone()[0]
    conn.close()
    return total

def ajouter_versement(objectif_id, montant, note='', date_str=None):
    if not date_str:
        date_str = date.today().isoformat()
    conn = get_conn()
    conn.execute(
        "INSERT INTO epargne_versements(objectif_id,date,montant,note) VALUES(?,?,?,?)",
        (objectif_id, date_str, float(montant), note)
    )
    conn.commit()
    conn.close()

def get_versements(objectif_id):
    conn = get_conn()
    rows = conn.execute(
        "SELECT * FROM epargne_versements WHERE objectif_id=? ORDER BY date DESC",
        (objectif_id,)
    ).fetchall()
    conn.close()
    return [dict(r) for r in rows]

def ajouter_objectif(nom, montant_cible, date_cible=None, description=''):
    conn = get_conn()
    conn.execute(
        "INSERT INTO epargne_objectifs(nom,montant_cible,date_cible,description) VALUES(?,?,?,?)",
        (nom, float(montant_cible), date_cible, description)
    )
    conn.commit()
    conn.close()

def supprimer_versement(id_):
    conn = get_conn()
    conn.execute("DELETE FROM epargne_versements WHERE id=?", (id_,))
    conn.commit()
    conn.close()
