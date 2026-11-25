import imaplib
import email
from email.header import decode_header
from datetime import datetime, timedelta
import re


class ImapClient:
    def __init__(self, host, port, user, password):
        self.host = host
        self.port = port
        self.user = user
        self.password = password
        self.conn = None

    def connect(self):
        self.conn = imaplib.IMAP4_SSL(self.host, self.port)
        self.conn.login(self.user, self.password)
        return self

    def disconnect(self):
        if self.conn:
            try:
                self.conn.logout()
            except Exception:
                pass
            self.conn = None

    def search_recent(self, days=7):
        since = (datetime.now() - timedelta(days=days)).strftime("%d-%b-%Y")
        self.conn.select("INBOX")
        _, data = self.conn.search(None, f'(SINCE {since})')
        uids = data[0].split() if data[0] else []
        return [uid.decode() for uid in uids]

    def fetch_message(self, uid):
        _, data = self.conn.fetch(uid, "(RFC822)")
        if not data or not data[0]:
            return None
        raw = data[0][1]
        return email.message_from_bytes(raw)

    def get_body(self, msg):
        body = ""
        if msg.is_multipart():
            for part in msg.walk():
                ct = part.get_content_type()
                disp = str(part.get("Content-Disposition", ""))
                if ct == "text/plain" and "attachment" not in disp:
                    try:
                        charset = part.get_content_charset() or "utf-8"
                        body += part.get_payload(decode=True).decode(charset, errors="replace")
                    except Exception:
                        pass
        else:
            try:
                charset = msg.get_content_charset() or "utf-8"
                body = msg.get_payload(decode=True).decode(charset, errors="replace")
            except Exception:
                body = ""
        return body

    @staticmethod
    def decode_header_value(value):
        if not value:
            return ""
        parts = decode_header(value)
        result = ""
        for text, charset in parts:
            if isinstance(text, bytes):
                result += text.decode(charset or "utf-8", errors="replace")
            else:
                result += text
        return result

    @staticmethod
    def extract_email_address(header_value):
        match = re.search(r'[\w.+-]+@[\w.-]+\.\w+', header_value or "")
        return match.group(0).lower() if match else ""
