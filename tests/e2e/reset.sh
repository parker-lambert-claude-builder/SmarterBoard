#!/usr/bin/env bash
# A fresh test database, seeded, with the plan limits switched off (the checks aren't about them), the fake Brevo
# in place of the real one, and the fixtures directory empty. Run before each check: they leave things changed.
set -euo pipefail
SITE="${MASHPAD_SITE:-/srv/site}"
DB="${MASHPAD_E2E_DB_NAME:-mashpad_test_e2e}"
HERE="$(cd "$(dirname "$0")" && pwd)"
MYSQL=(mysql ${MASHPAD_E2E_ROOT_ARGS:--uroot})            # an account that can create databases
rm -f "$SITE/data/config.php" "$SITE/data/schema-version" "$SITE"/data/boards/*.json "${FAKEBREVO_LOG:-/tmp/fakebrevo.log}"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
MASHPAD_SITE="$SITE" php "$HERE/seed.php" >/dev/null
"${MYSQL[@]}" "$DB" -e "UPDATE plans SET max_human_seats = NULL, max_agents = NULL"
MASHPAD_SITE="$SITE" php -r 'require getenv("MASHPAD_SITE") . "/src/mail.php"; $p = mb_db(); mb_set_setting($p, "brevo_api_base", "http://127.0.0.1:" . (getenv("FAKEBREVO_PORT") ?: "8099") . "/v3"); mb_set_setting($p, "brevo_api_key", "xkeysib-GOODKEY-9aZq"); mb_set_setting($p, "mail_from_email", "hello@mashpad.ai");'
rm -rf "${MASHPAD_TEST_CIMD_DIR:-/tmp/cimd}"; mkdir -p "${MASHPAD_TEST_CIMD_DIR:-/tmp/cimd}"; chmod 777 "${MASHPAD_TEST_CIMD_DIR:-/tmp/cimd}"
chmod -R a+rwX "$SITE/data" 2>/dev/null || true
