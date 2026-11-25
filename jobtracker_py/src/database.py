import sqlite3
import threading
import os

_local = threading.local()
_BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def get_db():
    if not hasattr(_local, 'conn') or _local.conn is None:
        import config
        db_path = os.path.join(_BASE, config.DB_PATH)
        conn = sqlite3.connect(db_path, check_same_thread=False)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA journal_mode=WAL")
        conn.execute("PRAGMA foreign_keys=ON")
        _local.conn = conn
    return _local.conn


def close_db():
    if hasattr(_local, 'conn') and _local.conn:
        _local.conn.close()
        _local.conn = None


def init_db():
    schema = os.path.join(_BASE, 'db', 'schema.sql')
    conn = get_db()
    with open(schema, 'r', encoding='utf-8') as f:
        conn.executescript(f.read())
    conn.commit()
