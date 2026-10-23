"""Dev fixture: messages that exercise project detection. Usage: python3 -I dev/seed_projects.py <Message-ID of an email logged on a project>"""
import smtplib, sys, time
from email.message import EmailMessage
from email.utils import formatdate, make_msgid
TO = "sales@convomail.test"
parent = sys.argv[1]
msgs = []
a = EmailMessage(); a["From"] = "Dana Rivera <dana.rivera@acme.test>"; a["To"] = TO
a["Subject"] = "Re: Re: Quote questions (HTML test)"; a["Date"] = formatdate(time.time() - 60, localtime=True)
a["Message-ID"] = make_msgid(domain="acme.test"); a["In-Reply-To"] = "<" + parent + ">"; a["References"] = "<" + parent + ">"
a.set_content("Thanks for the answers. Approved on our side."); msgs.append(a)
b = EmailMessage(); b["From"] = "Site Manager <site@partner.test>"; b["To"] = TO
b["Subject"] = "Re: Oxygen meters rollout schedule"; b["Date"] = formatdate(time.time() - 50, localtime=True)
b["Message-ID"] = make_msgid(domain="partner.test"); b["References"] = "<1700000000.SMTPs-dolibarr-proj1@deadbeef>"
b.set_content("Install crew confirmed for next Monday."); msgs.append(b)
c = EmailMessage(); c["From"] = "Dana Rivera <dana.rivera@acme.test>"; c["To"] = TO
c["Subject"] = "New site visit request"; c["Date"] = formatdate(time.time() - 40, localtime=True)
c["Message-ID"] = make_msgid(domain="acme.test"); c.set_content("Could someone visit the north wing next week?"); msgs.append(c)
with smtplib.SMTP("localhost", 3025) as s:
    for m in msgs:
        s.send_message(m, from_addr=m["From"], to_addrs=[TO])
print("sent", len(msgs))
