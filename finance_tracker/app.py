import os, sys
from datetime import date, datetime
from flask import Flask, render_template, request, redirect, url_for, session, flash, Response

sys.path.insert(0, os.path.dirname(__file__))
import config
from src.database import init_db, get_setting, set_setting
from src.compta import (ajouter_transaction, supprimer_transaction,
                        get_transactions, get_solde_mois, export_csv, CATEGORIES)
from src.factures import (get_factures, ajouter_facture, supprimer_facture,
                          get_paiements_mois, init_paiements_mois, marquer_payee,
                          marquer_non_payee, get_total_factures, get_prochaines_echeances,
                          get_mois_str)
from src.epargne import (get_objectifs, ajouter_versement, get_versements,
                         ajouter_objectif, supprimer_versement)

app = Flask(__name__)
app.secret_key = config.APP['secret_key']

# ── Auth ──────────────────────────────────────────────────────────────────────

def check_auth():
    return session.get('auth') is True

def verify_password(pwd):
    from werkzeug.security import check_password_hash, generate_password_hash
    stored = get_setting('password_hash')
    if stored is None:
        # Premier accès : initialiser avec mot de passe par défaut
        h = generate_password_hash('jobtracker', method='pbkdf2:sha256')
        set_setting('password_hash', h)
        stored = h
    return check_password_hash(stored, pwd)

@app.route('/login', methods=['GET', 'POST'])
def login():
    error = None
    if request.method == 'POST':
        if verify_password(request.form.get('password', '')):
            session['auth'] = True
            return redirect(url_for('dashboard'))
        error = "Mot de passe incorrect"
    return render_template('login.html', error=error)

@app.route('/logout')
def logout():
    session.clear()
    return redirect(url_for('login'))

# ── Dashboard ─────────────────────────────────────────────────────────────────

@app.route('/')
def dashboard():
    if not check_auth():
        return redirect(url_for('login'))

    today = date.today()
    mois, annee = today.month, today.year
    solde = get_solde_mois(mois, annee)
    revenu = float(get_setting('revenu_mensuel', config.REVENU_MENSUEL_DEFAUT))
    total_factures = get_total_factures()
    prochaines = get_prochaines_echeances(5)
    objectifs = get_objectifs()
    cuisine = next((o for o in objectifs if 'cuisine' in o['nom'].lower()), None)

    capacite_epargne = revenu - solde['depenses'] - total_factures
    # Calcul dynamique: mois nécessaires selon capacité d'épargne actuelle
    mois_cuisine = round(cuisine['montant_cible'] / max(capacite_epargne, 1)) if cuisine and capacite_epargne > 0 else None
    alerte_epargne = cuisine and (capacite_epargne <= 0 or mois_cuisine is None)

    return render_template('dashboard.html',
        solde=solde, revenu=revenu,
        total_factures=total_factures,
        prochaines=prochaines,
        objectifs=objectifs,
        cuisine=cuisine,
        capacite_epargne=capacite_epargne,
        alerte_epargne=alerte_epargne,
        mois_cuisine=mois_cuisine,
        mois_nom=today.strftime('%B %Y'),
        today=today,
    )

# ── Comptabilité ──────────────────────────────────────────────────────────────

@app.route('/compta')
def compta():
    if not check_auth():
        return redirect(url_for('login'))
    mois = int(request.args.get('mois', date.today().month))
    annee = int(request.args.get('annee', date.today().year))
    transactions = get_transactions(mois, annee)
    solde = get_solde_mois(mois, annee)
    revenu = float(get_setting('revenu_mensuel', config.REVENU_MENSUEL_DEFAUT))
    return render_template('compta.html',
        transactions=transactions, solde=solde, revenu=revenu,
        mois=mois, annee=annee, categories=CATEGORIES,
        mois_list=range(1, 13), annee_list=range(2024, date.today().year + 2),
    )

@app.route('/compta/ajouter', methods=['POST'])
def compta_ajouter():
    if not check_auth():
        return redirect(url_for('login'))
    ajouter_transaction(
        request.form['date'], request.form['libelle'],
        request.form['montant'], request.form['type'],
        request.form.get('categorie', 'autre'),
        request.form.get('note', '')
    )
    flash('Transaction ajoutée', 'success')
    return redirect(url_for('compta', mois=request.form.get('mois'), annee=request.form.get('annee')))

@app.route('/compta/supprimer/<int:id_>')
def compta_supprimer(id_):
    if not check_auth():
        return redirect(url_for('login'))
    supprimer_transaction(id_)
    flash('Transaction supprimée', 'info')
    return redirect(request.referrer or url_for('compta'))

@app.route('/compta/export')
def compta_export():
    if not check_auth():
        return redirect(url_for('login'))
    mois = request.args.get('mois')
    annee = request.args.get('annee')
    csv_data = export_csv(mois, annee)
    fname = f"transactions_{annee}_{str(mois).zfill(2)}.csv" if mois else "transactions.csv"
    return Response(csv_data, mimetype='text/csv',
                    headers={'Content-Disposition': f'attachment; filename={fname}'})

@app.route('/compta/revenu', methods=['POST'])
def compta_revenu():
    if not check_auth():
        return redirect(url_for('login'))
    set_setting('revenu_mensuel', request.form['revenu'])
    flash('Revenu mensuel mis à jour', 'success')
    return redirect(url_for('compta'))

# ── Factures ──────────────────────────────────────────────────────────────────

@app.route('/factures')
def factures():
    if not check_auth():
        return redirect(url_for('login'))
    mois = int(request.args.get('mois', date.today().month))
    annee = int(request.args.get('annee', date.today().year))
    mois_str = f"{annee}-{str(mois).zfill(2)}"
    init_paiements_mois(mois_str)
    paiements = get_paiements_mois(mois_str)
    factures_list = get_factures()
    total = sum(f['montant'] for f in factures_list)
    payees = sum(1 for p in paiements if p['statut'] == 'payee')
    return render_template('factures.html',
        paiements=paiements, factures=factures_list,
        total=total, payees=payees,
        mois=mois, annee=annee, mois_str=mois_str,
        mois_list=range(1, 13), annee_list=range(2024, date.today().year + 2),
    )

@app.route('/factures/payer/<int:facture_id>', methods=['POST'])
def facture_payer(facture_id):
    if not check_auth():
        return redirect(url_for('login'))
    mois_str = request.form['mois_str']
    marquer_payee(facture_id, mois_str)
    return redirect(url_for('factures',
        mois=request.form.get('mois'), annee=request.form.get('annee')))

@app.route('/factures/annuler/<int:facture_id>', methods=['POST'])
def facture_annuler(facture_id):
    if not check_auth():
        return redirect(url_for('login'))
    mois_str = request.form['mois_str']
    marquer_non_payee(facture_id, mois_str)
    return redirect(url_for('factures',
        mois=request.form.get('mois'), annee=request.form.get('annee')))

@app.route('/factures/ajouter', methods=['POST'])
def facture_ajouter():
    if not check_auth():
        return redirect(url_for('login'))
    ajouter_facture(
        request.form['nom'], request.form['montant'],
        request.form['jour_echeance'], request.form.get('categorie', 'autre')
    )
    flash('Facture ajoutée', 'success')
    return redirect(url_for('factures'))

@app.route('/factures/supprimer/<int:id_>')
def facture_supprimer(id_):
    if not check_auth():
        return redirect(url_for('login'))
    supprimer_facture(id_)
    flash('Facture archivée', 'info')
    return redirect(url_for('factures'))

# ── Épargne ───────────────────────────────────────────────────────────────────

@app.route('/epargne')
def epargne():
    if not check_auth():
        return redirect(url_for('login'))
    objectifs = get_objectifs()
    versements_par_obj = {o['id']: get_versements(o['id']) for o in objectifs}
    revenu = float(get_setting('revenu_mensuel', config.REVENU_MENSUEL_DEFAUT))
    total_factures = get_total_factures()
    return render_template('epargne.html',
        objectifs=objectifs, versements_par_obj=versements_par_obj,
        revenu=revenu, total_factures=total_factures,
        objectif_total=config.OBJECTIF_CUISINE,
    )

@app.route('/epargne/verser/<int:objectif_id>', methods=['POST'])
def epargne_verser(objectif_id):
    if not check_auth():
        return redirect(url_for('login'))
    ajouter_versement(
        objectif_id, request.form['montant'],
        request.form.get('note', ''),
        request.form.get('date') or None
    )
    flash('Versement enregistré', 'success')
    return redirect(url_for('epargne'))

@app.route('/epargne/supprimer_versement/<int:id_>')
def epargne_supprimer_versement(id_):
    if not check_auth():
        return redirect(url_for('login'))
    supprimer_versement(id_)
    flash('Versement supprimé', 'info')
    return redirect(url_for('epargne'))

@app.route('/epargne/ajouter_objectif', methods=['POST'])
def epargne_ajouter_objectif():
    if not check_auth():
        return redirect(url_for('login'))
    ajouter_objectif(
        request.form['nom'], request.form['montant_cible'],
        request.form.get('date_cible') or None,
        request.form.get('description', '')
    )
    flash('Objectif créé', 'success')
    return redirect(url_for('epargne'))

# ── Paramètres ────────────────────────────────────────────────────────────────

@app.route('/parametres', methods=['GET', 'POST'])
def parametres():
    if not check_auth():
        return redirect(url_for('login'))
    if request.method == 'POST':
        from werkzeug.security import generate_password_hash
        ancien = request.form.get('ancien_mdp', '')
        nouveau = request.form.get('nouveau_mdp', '')
        if ancien and nouveau:
            if verify_password(ancien):
                set_setting('password_hash', generate_password_hash(nouveau, method='pbkdf2:sha256'))
                flash('Mot de passe mis à jour', 'success')
            else:
                flash('Ancien mot de passe incorrect', 'danger')
        if request.form.get('revenu'):
            set_setting('revenu_mensuel', request.form['revenu'])
            flash('Revenu mis à jour', 'success')
        return redirect(url_for('parametres'))
    revenu = get_setting('revenu_mensuel', config.REVENU_MENSUEL_DEFAUT)
    return render_template('parametres.html', revenu=revenu)

# ── Run ───────────────────────────────────────────────────────────────────────

if __name__ == '__main__':
    init_db()
    port = config.APP['port']
    print(f"FinanceTracker → http://localhost:{port}")
    print("Mot de passe par défaut : jobtracker")
    app.run(host='127.0.0.1', port=port, debug=config.APP['debug'])
