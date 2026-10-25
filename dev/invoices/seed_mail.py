"""Send the "Fill from the invoice" test emails to sales@convomail.test (GreenMail SMTP on 3025).

Usage: python3 dev/invoices/seed_mail.py   (after dev/invoices/run.sh, which writes dev/invoices/out/)
"""
import pathlib
import smtplib
import time
from email.mime.application import MIMEApplication
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from email.utils import formatdate, make_msgid

OUT = pathlib.Path(__file__).parent / "out"
TO = "sales@convomail.test"

CASES = [
    ("Fournitures Dupont <factures@dupont.test>", "Facture FA-2026-0042", [("Facture FA-2026-0042.pdf", "facturx-pdfa3.pdf", "pdf")]),
    ("Medisupply <billing@medisupply.test>", "Invoice INV-2026-1001", [("INV-2026-1001.xml", "ubl.xml", "xml"), ("INV-2026-1001.pdf", "plain.pdf", "pdf")]),
    ("New Supplier <accounts@newsupplier.test>", "Your invoice", [("invoice.pdf", "facturx-flate.pdf", "pdf")]),
    ("Fournitures Dupont <factures@dupont.test>", "Facture FA-2026-0043 (scan)", [("scan.pdf", "plain.pdf", "pdf")]),
    ("Fournitures Dupont <factures@dupont.test>", "Avoir AV-2026-0007", [("avoir.xml", "cii-creditnote.xml", "xml")]),
]

with smtplib.SMTP("127.0.0.1", 3025) as smtp:
    for i, (sender, subject, files) in enumerate(CASES):
        m = MIMEMultipart("mixed")
        m["From"] = sender
        m["To"] = TO
        m["Subject"] = subject
        m["Date"] = formatdate(time.time() - 60 * (len(CASES) - i), localtime=True)
        m["Message-ID"] = make_msgid(domain="invoices.test")
        m.attach(MIMEText("Please find our invoice attached.\n", "plain", "utf-8"))
        for name, fixture, sub in files:
            part = MIMEApplication((OUT / fixture).read_bytes(), sub)
            part.add_header("Content-Disposition", "attachment", filename=name)
            m.attach(part)
        smtp.send_message(m)
        print("sent:", subject)
