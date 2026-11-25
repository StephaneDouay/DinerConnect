import os
import logging
from datetime import datetime

_BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
_file_logger = None


def _get_file_logger():
    global _file_logger
    if _file_logger is None:
        import config
        log_path = os.path.join(_BASE, config.APP.get('log_path', 'storage/logs/app.log'))
        os.makedirs(os.path.dirname(log_path), exist_ok=True)
        _file_logger = logging.getLogger('jobtracker')
        _file_logger.setLevel(logging.DEBUG)
        if not _file_logger.handlers:
            fh = logging.FileHandler(log_path, encoding='utf-8')
            fh.setFormatter(logging.Formatter('%(asctime)s [%(levelname)s] %(message)s'))
            _file_logger.addHandler(fh)
    return _file_logger


def log(level, job, message):
    try:
        from src.database import get_db
        db = get_db()
        db.execute(
            "INSERT INTO logs (level, job, message) VALUES (?,?,?)",
            (level.upper(), job, message)
        )
        db.commit()
    except Exception:
        pass
    try:
        fl = _get_file_logger()
        getattr(fl, level.lower(), fl.info)(f"[{job}] {message}")
    except Exception:
        pass
