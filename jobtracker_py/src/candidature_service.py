import os

_BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def _fill_template(corps, offre, candidat, date=""):
    replacements = {
        "{titre}": offre.get("titre", ""),
        "{entreprise}": offre.get("entreprise", ""),
        "{nom}": candidat.get("nom", ""),
        "{telephone}": candidat.get("telephone", ""),
        "{linkedin}": candidat.get("linkedin", ""),
        "{email}": candidat.get("email", ""),
        "{date}": date,
        "{sujet}": offre.get("titre", ""),
    }
    for k, v in replacements.items():
        corps = corps.replace(k, v)
    return corps


def create(offre_id, template_id=1):
    import config
    from src.database import get_db
    db = get_db()

    offre = db.execute("SELECT * FROM offres WHERE id=?", (offre_id,)).fetchone()
    tpl = db.execute("SELECT * FROM templates WHERE id=?", (template_id,)).fetchone()
    if not offre or not tpl:
        return None

    from datetime import datetime
    date_str = datetime.now().strftime("%d/%m/%Y")
    candidat = config.CANDIDAT

    corps = _fill_template(tpl["corps"], dict(offre), candidat, date_str)
    sujet = _fill_template(tpl["sujet"], dict(offre), candidat, date_str)

    cur = db.execute(
        """INSERT INTO candidatures (offre_id, titre, entreprise, statut)
           VALUES (?,?,?,?)""",
        (offre_id, offre["titre"], offre["entreprise"], "brouillon")
    )
    cand_id = cur.lastrowid
    db.execute(
        "INSERT INTO messages (candidature_id, direction, sujet, corps) VALUES (?,?,?,?)",
        (cand_id, "brouillon", sujet, corps)
    )
    db.commit()
    return cand_id


def send(candidature_id, recruteur_email):
    import config
    from src.database import get_db
    from src.mailer import Mailer
    from src.logger import log
    db = get_db()

    cand = db.execute("SELECT * FROM candidatures WHERE id=?", (candidature_id,)).fetchone()
    if not cand:
        raise ValueError("Candidature introuvable")

    msg_row = db.execute(
        "SELECT * FROM messages WHERE candidature_id=? AND direction='brouillon' ORDER BY id DESC LIMIT 1",
        (candidature_id,)
    ).fetchone()

    sujet = msg_row["sujet"] if msg_row else f"Candidature – {cand['titre']}"
    corps = msg_row["corps"] if msg_row else ""

    cv_path = os.path.join(_BASE, config.APP.get("cv_path", "storage/cv.pdf"))
    attachments = [cv_path] if os.path.exists(cv_path) else []

    smtp = config.SMTP
    mailer = Mailer(smtp["host"], smtp["port"], smtp["user"], smtp["pass"],
                    from_name=config.CANDIDAT.get("nom"))

    html_body = corps.replace("\n", "<br>")
    msg_id = mailer.send(recruteur_email, sujet, html_body, attachments)

    db.execute(
        "UPDATE candidatures SET statut='envoyee', recruteur_email=?, message_id_smtp=? WHERE id=?",
        (recruteur_email, msg_id, candidature_id)
    )
    db.execute(
        "INSERT INTO messages (candidature_id, direction, sujet, corps) VALUES (?,?,?,?)",
        (candidature_id, "sortant", sujet, corps)
    )
    db.commit()
    log("info", "candidature", f"Email envoyé à {recruteur_email} pour {cand['titre']}")
    return msg_id


def add_manual(titre, entreprise, canal="linkedin", recruteur_email=None, notes=None):
    from src.database import get_db
    db = get_db()
    cur = db.execute(
        "INSERT INTO candidatures (titre, entreprise, canal, recruteur_email, notes, statut) VALUES (?,?,?,?,?,?)",
        (titre, entreprise, canal, recruteur_email, notes, "envoyee")
    )
    db.commit()
    return cur.lastrowid


def get_stats():
    from src.database import get_db
    db = get_db()
    rows = db.execute(
        "SELECT statut, COUNT(*) as n FROM candidatures GROUP BY statut"
    ).fetchall()
    return {r["statut"]: r["n"] for r in rows}
