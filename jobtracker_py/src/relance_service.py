from datetime import datetime, timedelta
import os

_BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def process():
    import config
    from src.database import get_db
    from src.mailer import Mailer
    from src.logger import log
    db = get_db()

    delais = config.RELANCES.get("delais", [7, 14])
    sans_reponse_delai = config.RELANCES.get("sans_reponse", 10)
    now = datetime.now()
    smtp = config.SMTP
    cv_path = os.path.join(_BASE, config.APP.get("cv_path", "storage/cv.pdf"))
    attachments = [cv_path] if os.path.exists(cv_path) else []
    mailer = Mailer(smtp["host"], smtp["port"], smtp["user"], smtp["pass"],
                    from_name=config.CANDIDAT.get("nom"))

    # Candidatures envoyées sans réponse
    candidatures = db.execute(
        """SELECT c.*, o.titre as offre_titre, o.entreprise as offre_entreprise
           FROM candidatures c LEFT JOIN offres o ON c.offre_id=o.id
           WHERE c.statut IN ('envoyee','relance_j7') AND c.recruteur_email IS NOT NULL AND c.recruteur_email != ''"""
    ).fetchall()

    sent = 0
    for cand in candidatures:
        date_cand = datetime.fromisoformat(cand["date_candidature"]) if cand["date_candidature"] else now
        days_elapsed = (now - date_cand).days
        titre = cand["titre"] or cand["offre_titre"] or "le poste"
        entreprise = cand["entreprise"] or cand["offre_entreprise"] or ""
        date_str = date_cand.strftime("%d/%m/%Y")

        # Determine which relance to send
        tpl_id = None
        new_statut = None

        if cand["statut"] == "envoyee" and days_elapsed >= delais[0]:
            tpl_id = 3
            new_statut = "relance_j7"
        elif cand["statut"] == "relance_j7" and days_elapsed >= (delais[1] if len(delais) > 1 else 14):
            tpl_id = 4
            new_statut = "relance_j14"

        if not tpl_id:
            # Check sans réponse after last relance
            if cand["statut"] == "relance_j7" and days_elapsed >= (delais[1] if len(delais) > 1 else 14) + sans_reponse_delai:
                db.execute("UPDATE candidatures SET statut='sans_reponse' WHERE id=?", (cand["id"],))
                db.commit()
            continue

        tpl = db.execute("SELECT * FROM templates WHERE id=?", (tpl_id,)).fetchone()
        if not tpl:
            continue

        corps = tpl["corps"].replace("{titre}", titre).replace("{entreprise}", entreprise).replace("{date}", date_str).replace("{nom}", config.CANDIDAT.get("nom", ""))
        sujet = tpl["sujet"].replace("{titre}", titre).replace("{entreprise}", entreprise)

        # Find original message-id for threading
        orig = db.execute(
            "SELECT message_id_smtp FROM candidatures WHERE id=?", (cand["id"],)
        ).fetchone()
        in_reply_to = orig["message_id_smtp"] if orig else None

        try:
            html_body = corps.replace("\n", "<br>")
            if in_reply_to:
                mid = mailer.reply(cand["recruteur_email"], sujet, html_body,
                                   in_reply_to, in_reply_to, attachments)
            else:
                mid = mailer.send(cand["recruteur_email"], sujet, html_body, attachments)

            db.execute("UPDATE candidatures SET statut=? WHERE id=?", (new_statut, cand["id"]))
            db.execute(
                "INSERT INTO messages (candidature_id, direction, sujet, corps) VALUES (?,?,?,?)",
                (cand["id"], "sortant", sujet, corps)
            )
            db.commit()
            log("info", "relances", f"Relance envoyée à {cand['recruteur_email']} ({titre})")
            sent += 1
        except Exception as e:
            log("error", "relances", f"Erreur relance #{cand['id']}: {e}")

    return {"sent": sent}
