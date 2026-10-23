"""Seed the GreenMail test inbox of sales@convomail.test with messages that exercise the mailbox."""
import base64
import smtplib
import sys
import time
from email.message import EmailMessage
from email.mime.application import MIMEApplication
from email.mime.image import MIMEImage
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from email.utils import formatdate, make_msgid

TO = "sales@convomail.test"
REPLY_TO_MSGID = sys.argv[1] if len(sys.argv) > 1 else ""

# 1x1 red PNG and a minimal PDF
PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==")
PDF = b"%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"

msgs = []

# 1. Hostile HTML + inline image + PDF, from a known contact
m = MIMEMultipart("mixed")
m["From"] = "Dana Rivera <dana.rivera@acme.test>"
m["To"] = TO
m["Subject"] = "Quote questions (HTML test)"
m["Date"] = formatdate(time.time() - 3600, localtime=True)
m["Message-ID"] = make_msgid(domain="acme.test")
rel = MIMEMultipart("related")
html = ('<p onclick="alert(1)">Hi, a few <b>questions</b> on the quote.</p>'
        '<script>alert("xss")</script>'
        '<img src="http://tracker.example/pixel.gif" onerror="alert(2)">'
        '<p>Logo: <img src="cid:logo1@acme.test" alt="logo"></p>'
        '<a href="javascript:alert(3)">bad link</a> <a href="https://acme.test/specs">specs</a>')
alt = MIMEMultipart("alternative")
alt.attach(MIMEText("Hi, a few questions on the quote.", "plain", "utf-8"))
alt.attach(MIMEText(html, "html", "utf-8"))
rel.attach(alt)
img = MIMEImage(PNG, "png")
img.add_header("Content-ID", "<logo1@acme.test>")
img.add_header("Content-Disposition", "inline", filename="logo.png")
rel.attach(img)
m.attach(rel)
pdf = MIMEApplication(PDF, "pdf")
pdf.add_header("Content-Disposition", "attachment", filename="specs.pdf")
m.attach(pdf)
msgs.append(m)

# 2. Plain text from an unknown sender
p = EmailMessage()
p["From"] = "Stranger <someone@unknown.test>"
p["To"] = TO
p["Subject"] = "Plain text hello"
p["Date"] = formatdate(time.time() - 3000, localtime=True)
p["Message-ID"] = make_msgid(domain="unknown.test")
p.set_content("Line one\nLine two with a link https://example.org/page\n\nRegards")
msgs.append(p)

# 3. ISO-8859-1
l = MIMEText("Café crème à Montréal, façade rénovée.", "plain", "iso-8859-1")
l["From"] = "Rene <rene@latin.test>"
l["To"] = TO
l["Subject"] = "Latin-1 message"
l["Date"] = formatdate(time.time() - 2400, localtime=True)
l["Message-ID"] = make_msgid(domain="latin.test")
msgs.append(l)

# 4. Reply to a message sent from Dolibarr
if REPLY_TO_MSGID:
    r = EmailMessage()
    r["From"] = "Dana Rivera <dana.rivera@acme.test>"
    r["To"] = TO
    r["Subject"] = "Re: Receipt test"
    r["Date"] = formatdate(time.time() - 1800, localtime=True)
    r["Message-ID"] = make_msgid(domain="acme.test")
    r["In-Reply-To"] = "<" + REPLY_TO_MSGID + ">"
    r["References"] = "<" + REPLY_TO_MSGID + ">"
    r.set_content("Thanks, received. Can we talk Tuesday?")
    msgs.append(r)

# 5. Fillers for paging
for i in range(26):
    f = EmailMessage()
    f["From"] = "Newsletter <news@list.test>"
    f["To"] = TO
    f["Subject"] = "Filler message %02d" % i
    f["Date"] = formatdate(time.time() - 86400 - i * 60, localtime=True)
    f["Message-ID"] = make_msgid(domain="list.test")
    f.set_content("Filler %d" % i)
    msgs.append(f)

with smtplib.SMTP("localhost", 3025) as s:
    for msg in msgs:
        s.send_message(msg, from_addr=msg["From"], to_addrs=[TO])
print("sent", len(msgs))
