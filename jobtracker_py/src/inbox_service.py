import json
import os
import re

_BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STATE_FILE = os.path.join(_BASE, "storage", "imap_state.json")

ENTRETIEN_WORDS = ["entretien", "interview", "rencontre", "visio", "teams", "zoom", "appel"]
REFUS_WORDS = ["retenu", "ne pas retenir", "sans suite", "refus", "candidature n'a pas", "other candidates"]
OFFRE_WORDS = ["poste", "mission", "opportunite", "recherchons", "recrutement", "profil", "cdi", "cdd"]


def _load_state():
    if os.path.exists(STATE_FILE):
        with open(STATE_FILE) as f:
            return json.load(f)
    return {"last_uid": "0"}


def _save_state(state):
    os.makedirs(os.path.dirname(STATE_FILE), exist_ok=True)
    with open(STATE_FILE, "w") as f:
        json.dump(state, f)


def _score_offre(text):
    t = text.lower()
    return sum(1 for w in OFFRE_WORDS if w in t)


def analyze():
    import config
    from src.database import get_db
    from src.imap_client import ImapClient
    from src.logger import log
    from src.mailer import Mailer

    cfg_imap = config.IMAP
    cfg_inbox = config.INBOX
    db = get_db()

    ignored = cfg_inbox.get("expediteurs_ignores", [])

    try:
        client = ImapClient(cfg_imap["host"], cfg_imap["port"], cfg_imap["user"], cfg_imap["pass"])
        client.connect()
    except Exception as e:
        log("error", "inbox", f"Connexion IMAP impossible: {e}")
        return {"errors": [str(e)]}

    state = _load_state()
    days = cfg_inbox.get("jours_historique", 7)
    uids = client.search_recent(days)

    results = {"replies": 0, "offres": 0, "errors": []}

    for uid in uids:
        try:
            msg = client.fetch_message(uid)
            if not msg:
                continue

            from_header = client.decode_header_value(msg.get("From", ""))
            from_email = client.extract_email_address(from_header)
            subject = client.decode_header_value(msg.get("Subject", ""))
            body = client.get_body(msg)
            in_reply_to = msg.get("In-Reply-To", "")
            references = msg.get("References", "")

            if any(ign in from_email for ign in ignored):
                continue

            # Detect reply to existing candidature
            matched = None
            if in_reply_to or references:
                ref_ids = (in_reply_to + " " + references).strip()
                matched = db.execute(
                    "SELECT id, statut FROM candidatures WHERE message_id_smtp IN (" +
                    ",".join("?" * len(ref_ids.split())) + ")",
                    ref_ids.split()
                ).fetchone()

            if not matched and from_email:
                matched = db.execute(
                    "SELECT id, statut FROM candidatures WHERE lower(recruteur_email)=? AND statut NOT IN ('refus','sans_reponse')",
                    (from_email,)
                ).fetchone()

            body_low = (subject + " " + body).lower()

            if matched:
                new_status = "reponse_recue"
                if any(w in body_low for w in ENTRETIEN_WORDS):
                    new_status = "entretien"
                elif any(w in body_low for w in REFUS_WORDS):
                    new_status = "refus"
                db.execute("UPDATE candidatures SET statut=? WHERE id=?", (new_status, matched["id"]))
                db.execute(
                    "INSERT INTO messages (candidature_id, direction, sujet, corps) VALUES (?,?,?,?)",
                    (matched["id"], "entrant", subject, body[:3000])
                )
                db.commit()
                log("info", "inbox", f"Statut mis à jour → {new_status} pour candidature #{matched['id']}")
                results["replies"] += 1

            else:
                score = _score_offre(body_low)
                if score >= cfg_inbox.get("score_min", 2):
                    # Create offer + draft candidature
                    cur = db.execute(
                        "INSERT INTO offres (source, external_id, titre, entreprise, description) VALUES (?,?,?,?,?)",
                        ("email", f"email_{uid}", subject[:200], from_email, body[:2000])
                    )
                    offre_id = cur.lastrowid
                    db.execute(
                        "INSERT INTO candidatures (offre_id, titre, entreprise, recruteur_email, statut) VALUES (?,?,?,?,?)",
                        (offre_id, subject[:200], from_email, from_email, "brouillon")
                    )
                    db.commit()
                    log("info", "inbox", f"Offre entrante détectée: {subject[:80]}")
                    results["offres"] += 1

        except Exception as e:
            results["errors"].append(str(e))
            log("error", "inbox", str(e))

    _save_state({"last_uid": uids[-1] if uids else state["last_uid"]})
    client.disconnect()
    return results
