@echo off
cd /d "%~dp0"
if not exist ".venv\Scripts\python.exe" (
    echo Creation de l'environnement virtuel...
    python -m venv .venv
    .venv\Scripts\pip install flask python-dateutil -q
)
echo Demarrage de FinanceTracker...
start "" http://localhost:5001
.venv\Scripts\python app.py
pause
