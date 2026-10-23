"""Dev only: write a large maildir for big@x.test, to measure the mailbox on big folders.
Usage: python3 -I dev/bigmailbox/make_maildir.py <vmail dir> [count, default 20000]
Message 4321 contains the word "zebracactus" in its body only (for searches in the message text)."""
import sys, pathlib, random, time
from email.utils import formatdate
root = pathlib.Path(sys.argv[1]) / 'big@x.test' / 'mail'
count = int(sys.argv[2]) if len(sys.argv) > 2 else 20000
for d in ('cur', 'new', 'tmp'):
    (root / d).mkdir(parents=True, exist_ok=True)
random.seed(7)
words = "quote invoice delivery meter oxygen hospital schedule install contract renewal price discount order shipment warranty service visit report project budget approval meeting call".split()
people = [("Dana Rivera", "dana.rivera@acme.test"), ("Site Manager", "site@partner.test"), ("Newsletter", "news@list.test"), ("Accounts", "ap@vendor.test"), ("Chris Lee", "chris@client%d.test")]
now = time.time()
for i in range(count):
    name, addr = random.choice(people)
    if '%d' in addr:
        addr = addr % random.randint(1, 300)
    subject = ' '.join(random.sample(words, 3)).capitalize() + f' #{i}'
    body = '\n'.join(' '.join(random.choice(words) for _ in range(14)) for _ in range(random.randint(20, 80)))
    if i == 4321:
        body += '\nThe unique token is zebracactus.\n'
    ts = now - (count - i) * 900
    msg = (f"From: {name} <{addr}>\r\nTo: Big <big@x.test>\r\nSubject: {subject}\r\nDate: {formatdate(ts, localtime=True)}\r\n"
           f"Message-ID: <big{i}@x.test>\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{body}\r\n")
    (root / 'cur' / f"{int(ts)}.M{i}P1.big:2,S").write_text(msg)
print('written', count, 'messages to', root)
