import smtplib
import os
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from email.mime.base import MIMEBase
from email import encoders
from email.utils import formatdate, make_msgid


class Mailer:
    def __init__(self, host, port, user, password, from_name=None):
        self.host = host
        self.port = port
        self.user = user
        self.password = password
        self.from_name = from_name or user

    def _build_message(self, to, subject, html_body, attachments=None,
                       in_reply_to=None, references=None):
        msg = MIMEMultipart("alternative")
        msg["From"] = f"{self.from_name} <{self.user}>"
        msg["To"] = to
        msg["Subject"] = subject
        msg["Date"] = formatdate(localtime=True)
        msg["Message-ID"] = make_msgid()
        if in_reply_to:
            msg["In-Reply-To"] = in_reply_to
        if references:
            msg["References"] = references

        plain = html_body.replace("<br>", "\n").replace("<br/>", "\n")
        import re
        plain = re.sub(r'<[^>]+>', '', plain)
        msg.attach(MIMEText(plain, "plain", "utf-8"))
        msg.attach(MIMEText(html_body, "html", "utf-8"))

        for path in (attachments or []):
            if os.path.exists(path):
                with open(path, "rb") as f:
                    part = MIMEBase("application", "octet-stream")
                    part.set_payload(f.read())
                encoders.encode_base64(part)
                part.add_header(
                    "Content-Disposition",
                    f'attachment; filename="{os.path.basename(path)}"'
                )
                msg.attach(part)

        return msg

    def send(self, to, subject, html_body, attachments=None):
        msg = self._build_message(to, subject, html_body, attachments)
        return self._send(msg)

    def reply(self, to, subject, html_body, in_reply_to, references, attachments=None):
        msg = self._build_message(to, subject, html_body, attachments,
                                  in_reply_to=in_reply_to, references=references)
        return self._send(msg)

    def _send(self, msg):
        with smtplib.SMTP(self.host, self.port, timeout=30) as s:
            s.ehlo()
            s.starttls()
            s.login(self.user, self.password)
            s.sendmail(self.user, msg["To"], msg.as_bytes())
        return msg["Message-ID"]
