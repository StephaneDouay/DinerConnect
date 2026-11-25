#!/bin/bash
echo "Installation des dependances..."
pip install flask requests -q
echo "Demarrage de JobTracker..."
python3 app.py
