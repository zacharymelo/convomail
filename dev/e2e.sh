#!/bin/bash
# End-to-end checks for convomail against the local dev stack (http://localhost:8114).
# Usage: CM_TEST_PASS=<salesrep password> dev/e2e.sh <scratch dir for cookies and temp files>
# Needs: the dev stack up, dev/fixtures.php run once, a GreenMail account on the admin user (README "Development").
set -u
BASE=http://localhost:8114
DIR="$1"
cd "$(dirname "$0")/.." || exit 1
SQL() { docker compose exec -T db mariadb -udolibarr -pdolibarr dolibarr -N -e "$1" 2>/dev/null; }

login() { # $1 jar, $2 user, $3 pass
	rm -f "$1"
	local t
	t=$(curl -s -c "$1" -b "$1" "$BASE/index.php" | grep -o 'name="token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
	curl -s -c "$1" -b "$1" -o /dev/null -X POST "$BASE/index.php" --data-urlencode "token=$t" --data-urlencode "actionlogin=login" --data-urlencode "loginfunction=loginfunction" --data-urlencode "username=$2" --data-urlencode "password=$3"
}
token() { # $1 jar, $2 url
	curl -s -c "$1" -b "$1" "$2" | grep -o 'name="token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'
}

A="$DIR/admin.jar"
login "$A" admin admin

echo "== 1. Send with attachment"
printf 'Quote line 1\nQuote line 2\n' > "$DIR/quote.txt"
T=$(token "$A" "$BASE/custom/convomail/compose.php?socid=1")
BEFORE=$(SQL "SELECT COALESCE(MAX(id),0) FROM llx_actioncomm")
curl -s -c "$A" -b "$A" -o /dev/null -w "HTTP %{http_code} -> %{redirect_url}\n" -X POST "$BASE/custom/convomail/compose.php" \
	-F "token=$T" -F "action=send" -F "accountid=1" -F "sendto=Dana Rivera <dana.rivera@acme.test>" \
	-F "subject=Quote attached" -F "message=Please find the quote attached." -F "socid=1" -F "projectid=1" \
	-F "attachments[]=@$DIR/quote.txt;type=text/plain"
EID=$(SQL "SELECT MAX(id) FROM llx_actioncomm")
echo "new event id: $EID (before: $BEFORE)"
SQL "SELECT id, code, fk_soc, fk_project, email_subject FROM llx_actioncomm WHERE id=$EID"
docker exec convomail-dolibarr-1 ls -la "/var/www/documents/agenda/$EID/" 2>&1 | tail -2
UID_IN=$(curl -s "imap://localhost:3143/INBOX" -X "SEARCH SUBJECT \"Quote attached\"" -u "dana.rivera@acme.test:x" | grep -o '[0-9]*$' | tail -1)
curl -s "imap://localhost:3143/INBOX;UID=$UID_IN" -u "dana.rivera@acme.test:x" | grep -i "filename\|Content-Disposition" | head -3
ls "$DIR" | grep -c jar >/dev/null
docker exec convomail-dolibarr-1 sh -c 'ls /var/www/documents/convomail/temp 2>/dev/null | wc -l' | sed 's/^/temp dirs left: /'

echo "== 2. Host outside the allowlist is refused on save"
T=$(token "$A" "$BASE/custom/convomail/user_accounts.php?id=1&accountid=1&action=edit")
curl -s -c "$A" -b "$A" -L -X POST "$BASE/custom/convomail/user_accounts.php?id=1&accountid=1" \
	--data-urlencode "token=$T" --data-urlencode "action=update" --data-urlencode "label=Sales Team" \
	--data-urlencode "email=sales@convomail.test" --data-urlencode "login=sales@convomail.test" --data-urlencode "password=" \
	--data-urlencode "imap_host=greenmail" --data-urlencode "imap_port=3143" --data-urlencode "imap_encryption=none" \
	--data-urlencode "smtp_host=smtp.evil.example" --data-urlencode "smtp_port=25" --data-urlencode "smtp_encryption=none" \
	--data-urlencode "sent_folder=Sent" --data-urlencode "is_default=1" --data-urlencode "status=1" | grep -o "The server [^<]* is not allowed[^<]*" | head -1
SQL "SELECT smtp_host FROM llx_convomail_account WHERE rowid=1"

echo "== 3. SMTP failure: clear error, no event"
SQL "UPDATE llx_convomail_account SET smtp_port=3999 WHERE rowid=1"
BEFORE=$(SQL "SELECT MAX(id) FROM llx_actioncomm")
T=$(token "$A" "$BASE/custom/convomail/compose.php?socid=1")
curl -s -c "$A" -b "$A" -X POST "$BASE/custom/convomail/compose.php" \
	-F "token=$T" -F "action=send" -F "accountid=1" -F "sendto=dana.rivera@acme.test" -F "subject=Should fail" -F "message=x" -F "socid=1" \
	| grep -o 'Failed to send[^"<]*' | head -1
AFTER=$(SQL "SELECT MAX(id) FROM llx_actioncomm")
echo "events before=$BEFORE after=$AFTER"
SQL "UPDATE llx_convomail_account SET smtp_port=3025 WHERE rowid=1"

echo "== 4. Second user without the admin right"
docker cp dev/user2.php convomail-dolibarr-1:/tmp/user2.php && docker exec -u www-data -e CM_TEST_PASS="$CM_TEST_PASS" convomail-dolibarr-1 php /tmp/user2.php
B="$DIR/user2.jar"
login "$B" salesrep "$CM_TEST_PASS"
curl -s -c "$B" -b "$B" -o /dev/null -w "user2 -> admin's mail accounts tab: HTTP %{http_code}\n" "$BASE/custom/convomail/user_accounts.php?id=1"
curl -s -c "$B" -b "$B" "$BASE/custom/convomail/user_accounts.php?id=1" | grep -o "Access denied\|Access forbidden\|not allowed" | head -1
curl -s -c "$B" -b "$B" -o /dev/null -w "user2 -> own tab: HTTP %{http_code}\n" "$BASE/custom/convomail/user_accounts.php?id=$(SQL "SELECT rowid FROM llx_user WHERE login='salesrep'")"
T=$(token "$B" "$BASE/custom/convomail/compose.php")
BEFORE=$(SQL "SELECT MAX(id) FROM llx_actioncomm")
curl -s -c "$B" -b "$B" -o /dev/null -X POST "$BASE/custom/convomail/compose.php" \
	-F "token=$T" -F "action=send" -F "accountid=1" -F "sendto=dana.rivera@acme.test" -F "subject=Hijack" -F "message=x"
AFTER=$(SQL "SELECT MAX(id) FROM llx_actioncomm")
echo "user2 sending with admin's account: events before=$BEFORE after=$AFTER"
