#!/bin/bash
# A 20,000-message mailbox on a Dovecot without full-text index, to measure listing and search (see dev/README.md).
# Usage: dev/bigmailbox/start.sh <empty scratch dir for the maildir>    Stop with: docker rm -f cm-dovecot
set -e
cd "$(dirname "$0")/../.."
VMAIL="$1"; [ -n "$VMAIL" ] || { echo "usage: $0 <scratch dir>"; exit 1; }
[ -d "$VMAIL/big@x.test" ] || python3 -I dev/bigmailbox/make_maildir.py "$VMAIL"
docker rm -f cm-dovecot >/dev/null 2>&1 || true
docker run -d --rm --name cm-dovecot --network convomail_default -e USER_PASSWORD=pass \
	-v "$PWD/dev/bigmailbox/dovecot.conf:/etc/dovecot/dovecot.conf:ro" -v "$VMAIL:/srv/vmail" dovecot/dovecot:latest >/dev/null
echo "Dovecot up. Account: big@x.test / pass, IMAP cm-dovecot:31143, no encryption (add cm-dovecot to Allowed mail servers)."
