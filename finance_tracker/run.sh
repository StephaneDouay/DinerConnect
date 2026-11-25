#!/bin/bash
cd "$(dirname "$0")"
if [ ! -f ".venv/bin/python" ]; then
    echo "Création de l'environnement virtuel..."
    python3 -m venv .venv
    .venv/bin/pip install flask python-dateutil -q
fi
echo "Démarrage de FinanceTracker → http://localhost:5001"
echo "Mot de passe par défaut : jobtracker"
xdg-open http://localhost:5001 2>/dev/null || open http://localhost:5001 2>/dev/null &
.venv/bin/python app.py
