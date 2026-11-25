import sys
import os

BASE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, BASE)

# Auto-copy config if missing
if not os.path.exists(os.path.join(BASE, "config.py")):
    import shutil
    shutil.copy(os.path.join(BASE, "config.sample.py"), os.path.join(BASE, "config.py"))
    print("[JobTracker] config.py créé depuis config.sample.py — veuillez le configurer.")

import config

from flask import (Flask, render_template, redirect, url_for, request,
                   session, jsonify, flash, send_from_directory)
from werkzeug.utils import secure_filename

app = Flask(__name__, template_folder=os.path.join(BASE, "templates"))
app.secret_key = config.APP.get("secret_key", "dev-secret-change-me")

from src.database import init_db, get_db
from src.auth import login as auth_login, logout as auth_logout, is_logged_in, require_login, make_hash

with app.app_context():
    init_db()


def config_ok():
    missing = []
    if not config.SMTP.get("user") or "@gmail.com" not in config.SMTP.get("user", ""):
        missing.append("SMTP email")
    if not config.SMTP.get("pass") or "xxxx" in config.SMTP.get("pass", ""):
        missing.append("SMTP mot de passe d'application")
    if not config.APP.get("admin_password_hash"):
        missing.append("Mot de passe dashboard")
    return missing


# ── Auth ─────────────────────────────────────────────────────────────────────

@app.route("/login", methods=["GET", "POST"])
def login_page():
    if is_logged_in():
        return redirect(url_for("dashboard"))
    error = None
    if request.method == "POST":
        pw = request.form.get("password", "")
        if not config.APP.get("admin_password_hash"):
            # First run: set password
            if pw and len(pw) >= 6:
                h = make_hash(pw)
                _update_config_hash(h)
                config.APP["admin_password_hash"] = h
                session["logged_in"] = True
                flash("Mot de passe défini — bienvenue !", "success")
                return redirect(url_for("dashboard"))
            error = "Le mot de passe doit contenir au moins 6 caractères."
        elif auth_login(pw):
            session["logged_in"] = True
            return redirect(url_for("dashboard"))
        else:
            error = "Mot de passe incorrect."
    first_run = not config.APP.get("admin_password_hash")
    return render_template("login.html", error=error, first_run=first_run)


def _update_config_hash(h):
    path = os.path.join(BASE, "config.py")
    with open(path) as f:
        content = f.read()
    content = content.replace(
        '"admin_password_hash": ""',
        f'"admin_password_hash": "{h}"'
    )
    with open(path, "w") as f:
        f.write(content)


@app.route("/logout")
def logout():
    auth_logout()
    return redirect(url_for("login_page"))


# ── Dashboard ─────────────────────────────────────────────────────────────────

@app.route("/")
@require_login
def index():
    return redirect(url_for("dashboard"))


@app.route("/dashboard")
@require_login
def dashboard():
    db = get_db()
    missing = config_ok()
    stats = {
        "offres_nouvelles": db.execute("SELECT COUNT(*) FROM offres WHERE statut='nouveau'").fetchone()[0],
        "candidatures_total": db.execute("SELECT COUNT(*) FROM candidatures").fetchone()[0],
        "en_attente": db.execute("SELECT COUNT(*) FROM candidatures WHERE statut IN ('envoyee','relance_j7','relance_j14')").fetchone()[0],
        "entretiens": db.execute("SELECT COUNT(*) FROM candidatures WHERE statut='entretien'").fetchone()[0],
        "reponses": db.execute("SELECT COUNT(*) FROM candidatures WHERE statut='reponse_recue'").fetchone()[0],
        "refus": db.execute("SELECT COUNT(*) FROM candidatures WHERE statut='refus'").fetchone()[0],
    }
    recent = db.execute(
        """SELECT c.id, c.titre, c.entreprise, c.statut, c.date_candidature, c.canal
           FROM candidatures c ORDER BY c.date_candidature DESC LIMIT 10"""
    ).fetchall()
    return render_template("dashboard.html", stats=stats, recent=recent, missing=missing)


# ── Offres ───────────────────────────────────────────────────────────────────

@app.route("/offres")
@require_login
def offres():
    db = get_db()
    statut = request.args.get("statut", "nouveau")
    rows = db.execute(
        "SELECT * FROM offres WHERE statut=? ORDER BY created_at DESC LIMIT 100",
        (statut,)
    ).fetchall()
    return render_template("offres.html", offres=rows, statut=statut)


@app.route("/offres/refresh", methods=["POST"])
@require_login
def offres_refresh():
    from src.offre_service import fetch_and_store
    try:
        counts = fetch_and_store()
        return jsonify({"ok": True, "counts": counts})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500


@app.route("/offres/<int:offre_id>/ignorer", methods=["POST"])
@require_login
def offre_ignorer(offre_id):
    db = get_db()
    db.execute("UPDATE offres SET statut='ignore' WHERE id=?", (offre_id,))
    db.commit()
    return redirect(url_for("offres"))


@app.route("/offres/<int:offre_id>/postuler", methods=["GET", "POST"])
@require_login
def postuler(offre_id):
    db = get_db()
    offre = db.execute("SELECT * FROM offres WHERE id=?", (offre_id,)).fetchone()
    if not offre:
        flash("Offre introuvable.", "error")
        return redirect(url_for("offres"))
    templates = db.execute("SELECT * FROM templates").fetchall()

    if request.method == "POST":
        from src.candidature_service import create, send
        tpl_id = int(request.form.get("template_id", 1))
        recruteur_email = request.form.get("recruteur_email", "").strip()
        action = request.form.get("action", "brouillon")

        cand_id = create(offre_id, tpl_id)
        if action == "envoyer" and recruteur_email:
            try:
                send(cand_id, recruteur_email)
                flash("Candidature envoyée !", "success")
            except Exception as e:
                flash(f"Erreur d'envoi : {e}", "error")
        else:
            flash("Brouillon créé.", "success")
        db.execute("UPDATE offres SET statut='postule' WHERE id=?", (offre_id,))
        db.commit()
        return redirect(url_for("candidatures"))

    return render_template("postuler.html", offre=offre, templates=templates,
                           candidat=config.CANDIDAT)


# ── Candidatures ─────────────────────────────────────────────────────────────

@app.route("/candidatures")
@require_login
def candidatures():
    db = get_db()
    statut = request.args.get("statut", "")
    if statut:
        rows = db.execute(
            "SELECT * FROM candidatures WHERE statut=? ORDER BY date_candidature DESC",
            (statut,)
        ).fetchall()
    else:
        rows = db.execute(
            "SELECT * FROM candidatures ORDER BY date_candidature DESC"
        ).fetchall()
    return render_template("candidatures.html", candidatures=rows, statut=statut)


@app.route("/candidatures/add", methods=["POST"])
@require_login
def candidature_add():
    from src.candidature_service import add_manual
    titre = request.form.get("titre", "").strip()
    entreprise = request.form.get("entreprise", "").strip()
    canal = request.form.get("canal", "linkedin")
    recruteur_email = request.form.get("recruteur_email", "").strip()
    notes = request.form.get("notes", "").strip()
    if titre:
        add_manual(titre, entreprise, canal, recruteur_email or None, notes or None)
        flash("Candidature ajoutée.", "success")
    return redirect(url_for("candidatures"))


@app.route("/candidatures/<int:cand_id>")
@require_login
def candidature_detail(cand_id):
    db = get_db()
    cand = db.execute("SELECT * FROM candidatures WHERE id=?", (cand_id,)).fetchone()
    if not cand:
        flash("Candidature introuvable.", "error")
        return redirect(url_for("candidatures"))
    messages = db.execute(
        "SELECT * FROM messages WHERE candidature_id=? ORDER BY date_envoi",
        (cand_id,)
    ).fetchall()
    offre = None
    if cand["offre_id"]:
        offre = db.execute("SELECT * FROM offres WHERE id=?", (cand["offre_id"],)).fetchone()
    return render_template("candidature_detail.html", cand=cand, messages=messages, offre=offre)


@app.route("/candidatures/<int:cand_id>/send", methods=["POST"])
@require_login
def candidature_send(cand_id):
    from src.candidature_service import send
    recruteur_email = request.form.get("recruteur_email", "").strip()
    if not recruteur_email:
        flash("Email du recruteur requis.", "error")
        return redirect(url_for("candidature_detail", cand_id=cand_id))
    try:
        send(cand_id, recruteur_email)
        flash("Email envoyé !", "success")
    except Exception as e:
        flash(f"Erreur : {e}", "error")
    return redirect(url_for("candidature_detail", cand_id=cand_id))


@app.route("/candidatures/<int:cand_id>/statut", methods=["POST"])
@require_login
def candidature_statut(cand_id):
    db = get_db()
    new_statut = request.form.get("statut", "")
    valid = ["brouillon", "envoyee", "reponse_recue", "entretien", "refus", "sans_reponse", "relance_j7", "relance_j14"]
    if new_statut in valid:
        db.execute("UPDATE candidatures SET statut=? WHERE id=?", (new_statut, cand_id))
        db.commit()
    return redirect(request.referrer or url_for("candidatures"))


# ── Modèles & CV ─────────────────────────────────────────────────────────────

@app.route("/modeles")
@require_login
def modeles():
    db = get_db()
    templates = db.execute("SELECT * FROM templates ORDER BY id").fetchall()
    cv_exists = os.path.exists(os.path.join(BASE, config.APP.get("cv_path", "storage/cv.pdf")))
    return render_template("modeles.html", templates=templates, cv_exists=cv_exists)


@app.route("/modeles/<int:tpl_id>/edit", methods=["POST"])
@require_login
def modele_edit(tpl_id):
    db = get_db()
    nom = request.form.get("nom", "").strip()
    sujet = request.form.get("sujet", "").strip()
    corps = request.form.get("corps", "").strip()
    db.execute("UPDATE templates SET nom=?, sujet=?, corps=? WHERE id=?",
               (nom, sujet, corps, tpl_id))
    db.commit()
    flash("Modèle sauvegardé.", "success")
    return redirect(url_for("modeles"))


@app.route("/cv/upload", methods=["POST"])
@require_login
def cv_upload():
    f = request.files.get("cv")
    if not f or not f.filename.endswith(".pdf"):
        flash("Fichier PDF requis.", "error")
        return redirect(url_for("modeles"))
    f.seek(0, 2)
    size = f.tell()
    f.seek(0)
    if size > 5 * 1024 * 1024:
        flash("Fichier trop grand (max 5 Mo).", "error")
        return redirect(url_for("modeles"))
    cv_path = os.path.join(BASE, config.APP.get("cv_path", "storage/cv.pdf"))
    os.makedirs(os.path.dirname(cv_path), exist_ok=True)
    f.save(cv_path)
    flash("CV téléversé.", "success")
    return redirect(url_for("modeles"))


# ── Journal ───────────────────────────────────────────────────────────────────

@app.route("/journal")
@require_login
def journal():
    db = get_db()
    logs = db.execute(
        "SELECT * FROM logs ORDER BY created_at DESC LIMIT 200"
    ).fetchall()
    return render_template("journal.html", logs=logs)


@app.route("/jobs/run", methods=["POST"])
@require_login
def jobs_run():
    job = request.json.get("job", "")
    output = ""
    error = None
    try:
        if job == "offres":
            from src.offre_service import fetch_and_store
            counts = fetch_and_store()
            output = f"France Travail: {counts.get('francetravail', 0)} nouvelles, Adzuna: {counts.get('adzuna', 0)} nouvelles"
        elif job == "inbox":
            from src.inbox_service import analyze
            r = analyze()
            output = f"Réponses: {r.get('replies', 0)}, Offres détectées: {r.get('offres', 0)}"
            if r.get("errors"):
                error = r["errors"][0]
        elif job == "relances":
            from src.relance_service import process
            r = process()
            output = f"Relances envoyées: {r.get('sent', 0)}"
        elif job == "digest":
            from src.digest_service import send_digest
            ok = send_digest()
            output = "Digest envoyé." if ok else "Erreur lors de l'envoi."
        else:
            error = "Job inconnu"
    except Exception as e:
        error = str(e)
    return jsonify({"ok": error is None, "output": output, "error": error})


# ── Settings ──────────────────────────────────────────────────────────────────

@app.route("/settings", methods=["GET", "POST"])
@require_login
def settings():
    generated_hash = None
    if request.method == "POST":
        pw = request.form.get("new_password", "").strip()
        if pw and len(pw) >= 6:
            h = make_hash(pw)
            _update_config_hash(h)
            config.APP["admin_password_hash"] = h
            generated_hash = h
            flash("Mot de passe mis à jour.", "success")
        else:
            flash("Le mot de passe doit contenir au moins 6 caractères.", "error")
    return render_template("settings.html", generated_hash=generated_hash,
                           cfg=config, missing=config_ok())


if __name__ == "__main__":
    port = config.APP.get("port", 5000)
    debug = config.APP.get("debug", False)
    print(f"\n✅ JobTracker démarré → http://localhost:{port}\n")
    try:
        import webbrowser
        webbrowser.open(f"http://localhost:{port}")
    except Exception:
        pass
    app.run(host="127.0.0.1", port=port, debug=debug)
