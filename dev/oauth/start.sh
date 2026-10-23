#!/bin/bash
# Test Convomail's OAuth sign-in against a local Dovecot that accepts one test token (see dev/README.md).
# Starts: Dovecot (IMAP 31143, submission 31587, relays to GreenMail) and the token check endpoint in the
# dolibarr container (port 8000), then stores a valid token for the test account oauthuser@x.test.
# Stop with: docker rm -f cm-dovecot
set -e
cd "$(dirname "$0")/../.."
docker compose exec -T dolibarr mkdir -p /tmp/cm-oauth
docker cp dev/oauth/introspect.php convomail-dolibarr-1:/tmp/cm-oauth/introspect.php
docker cp dev/oauth/seedtoken.php convomail-dolibarr-1:/tmp/seedtoken.php
docker compose exec -d -T dolibarr php -S 0.0.0.0:8000 -t /tmp/cm-oauth
docker rm -f cm-dovecot >/dev/null 2>&1 || true
docker run -d --rm --name cm-dovecot --network convomail_default -v "$PWD/dev/oauth/dovecot.conf:/etc/dovecot/dovecot.conf:ro" dovecot/dovecot:latest >/dev/null
sleep 4
docker compose exec -T dolibarr php /tmp/seedtoken.php convomail-test-access-token 3600
echo "Add cm-dovecot to Allowed mail servers in Convomail setup, and set CONVOMAIL_OAUTH_MICROSOFT_APP to an app named MICROSOFT3-Mail."
