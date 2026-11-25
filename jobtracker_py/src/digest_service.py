from datetime import datetime, timedelta


def send_digest():
    import config
    from src.database import get_db
    from src.mailer import Mailer
    from src.logger import log
    db = get_db()

    candidat = config.CANDIDAT
    smtp = config.SMTP
    mailer = Mailer(smtp["host"], smtp["port"], smtp["user"], smtp["pass"],
                    from_name=candidat.get("nom"))

    since = (datetime.now() - timedelta(days=1)).isoformat()

    new_offres = db.execute(
        "SELECT COUNT(*) as n FROM offres WHERE created_at >= ?", (since,)
    ).fetchone()["n"]

    pending_replies = db.execute(
        "SELECT COUNT(*) as n FROM candidatures WHERE statut='reponse_recue'"
    ).fetchone()["n"]

    entretiens = db.execute(
        "SELECT COUNT(*) as n FROM candidatures WHERE statut='entretien'"
    ).fetchone()["n"]

    to_send = db.execute(
        "SELECT COUNT(*) as n FROM candidatures WHERE statut='brouillon'"
    ).fetchone()["n"]

    # Recent offres
    offres_rows = db.execute(
        "SELECT titre, entreprise, lieu, url FROM offres WHERE created_at >= ? ORDER BY created_at DESC LIMIT 10",
        (since,)
    ).fetchall()

    offres_html = ""
    for o in offres_rows:
        url = o["url"] or "#"
        offres_html += f'<li><a href="{url}">{o["titre"]}</a> — {o["entreprise"]} ({o["lieu"]})</li>'

    html = f"""
<html><body style="font-family:sans-serif;color:#333;max-width:600px;margin:auto">
<h2 style="color:#c9a96e">📋 Récapitulatif JobTracker — {datetime.now().strftime('%d/%m/%Y')}</h2>
<div style="display:flex;gap:20px;margin:20px 0">
  <div style="background:#f5f5f5;padding:16px;border-radius:8px;text-align:center">
    <div style="font-size:2em;font-weight:bold">{new_offres}</div>
    <div>Nouvelles offres</div>
  </div>
  <div style="background:#f5f5f5;padding:16px;border-radius:8px;text-align:center">
    <div style="font-size:2em;font-weight:bold">{pending_replies}</div>
    <div>Réponses à traiter</div>
  </div>
  <div style="background:#f5f5f5;padding:16px;border-radius:8px;text-align:center">
    <div style="font-size:2em;font-weight:bold">{entretiens}</div>
    <div>Entretiens</div>
  </div>
  <div style="background:#f5f5f5;padding:16px;border-radius:8px;text-align:center">
    <div style="font-size:2em;font-weight:bold">{to_send}</div>
    <div>Brouillons à envoyer</div>
  </div>
</div>
{"<h3>Nouvelles offres du jour</h3><ul>" + offres_html + "</ul>" if offres_html else ""}
<p style="color:#999;font-size:12px">JobTracker — {datetime.now().strftime('%d/%m/%Y %H:%M')}</p>
</body></html>
"""

    subject = f"JobTracker – {new_offres} offres, {pending_replies} réponses – {datetime.now().strftime('%d/%m')}"
    try:
        mailer.send(candidat["email"], subject, html)
        log("info", "digest", f"Digest envoyé à {candidat['email']}")
        return True
    except Exception as e:
        log("error", "digest", f"Erreur digest: {e}")
        return False
