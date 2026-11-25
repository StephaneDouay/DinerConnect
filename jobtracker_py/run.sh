#!/bin/bash
cd "$(dirname "$0")"
if [ ! -f ".venv/bin/python" ]; then
    echo "Création de l'environnement virtuel..."
    python3 -m venv .venv
    .venv/bin/pip install flask requests -q
fi
echo "Démarrage de JobTracker → http://localhost:5000"
xdg-open http://localhost:5000 2>/dev/null || open http://localhost:5000 2>/dev/null &
.venv/bin/python app.py
