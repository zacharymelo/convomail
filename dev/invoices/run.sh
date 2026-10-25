#!/bin/bash
# Generates the invoice fixtures and runs the invoice-reading checks in the dev Dolibarr container.
# Usage: dev/invoices/run.sh   (fixtures are copied back to dev/invoices/out/ for inspection)
set -eu
cd "$(dirname "$0")"
C=convomail-dolibarr-1
docker exec "$C" rm -rf /tmp/cm-invoices
docker exec "$C" mkdir -p /tmp/cm-invoices
docker cp generate.php "$C":/tmp/cm-invoices/generate.php
docker cp test.php "$C":/tmp/cm-invoices/test.php
docker cp suppliers.php "$C":/tmp/cm-invoices/suppliers.php
docker exec "$C" chown -R www-data /tmp/cm-invoices
docker exec -u www-data "$C" php /tmp/cm-invoices/generate.php /tmp/cm-invoices/out
rm -rf out
docker cp "$C":/tmp/cm-invoices/out out
docker exec -u www-data "$C" php /tmp/cm-invoices/test.php /tmp/cm-invoices/out
