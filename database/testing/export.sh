#!/usr/bin/env bash
# Refresh the committed schema and reference data from the dev database.
#
# Run after a migration changes the schema, then `php artisan test:db`.
# Usage: database/testing/export.sh [source_db]   (default: mrmobile_db)
#
# reference.sql holds configuration only. Never add a table that stores
# credentials (payments, licences, oauth_*), customers or orders: this file is
# committed. Gateway settings are written with dummy keys by TestFixtureSeeder.
set -euo pipefail

SRC="${1:-mrmobile_db}"
DIR="$(cd "$(dirname "$0")" && pwd)"
DUMP="${MYSQLDUMP:-mysqldump}"
command -v "$DUMP" >/dev/null 2>&1 || DUMP=/c/xampp/mysql/bin/mysqldump.exe
AUTH=(-u"${DB_USERNAME:-root}")
[ -n "${DB_PASSWORD:-}" ] && AUTH+=(-p"$DB_PASSWORD")

REFERENCE_TABLES="languages permissions roles role_has_permissions
  tax_rules tax_rule_langs shipping_rules shipping_rule_langs shipping_places
  settings site_settings site_setting_langs site_features site_feature_langs
  pages page_langs header_links header_link_langs footer_links footer_image_links
  templates migrations"

"$DUMP" "${AUTH[@]}" --no-data --skip-comments --skip-dump-date --single-transaction "$SRC" \
  | sed -E 's/ AUTO_INCREMENT=[0-9]+//' > "$DIR/schema.sql"

# shellcheck disable=SC2086
"$DUMP" "${AUTH[@]}" --no-create-info --skip-comments --skip-dump-date --single-transaction \
  --complete-insert --skip-extended-insert --skip-triggers "$SRC" $REFERENCE_TABLES > "$DIR/reference.sql"

echo "schema.sql:    $(grep -c 'CREATE TABLE' "$DIR/schema.sql") tables"
echo "reference.sql: $(grep -c '^INSERT' "$DIR/reference.sql") rows"
