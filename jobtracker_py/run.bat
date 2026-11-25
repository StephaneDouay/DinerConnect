@echo off
cd /d "%~dp0"
if not exist ".venv\Scripts\python.exe" (
    echo Creation de l'environnement virtuel...
    python -m venv .venv
    .venv\Scripts\pip install flask requests -q
)
echo Demarrage de JobTracker...
start "" http://localhost:5000
.venv\Scripts\python app.py
pause
