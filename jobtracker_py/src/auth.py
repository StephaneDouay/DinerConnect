from flask import session, redirect, url_for
from werkzeug.security import check_password_hash, generate_password_hash
from functools import wraps


def login(password):
    import config
    h = config.APP.get('admin_password_hash', '')
    if not h:
        return False
    return check_password_hash(h, password)


def logout():
    session.clear()


def is_logged_in():
    return session.get('logged_in') is True


def require_login(f):
    @wraps(f)
    def decorated(*args, **kwargs):
        if not is_logged_in():
            return redirect(url_for('login_page'))
        return f(*args, **kwargs)
    return decorated


def make_hash(password):
    return generate_password_hash(password)
