#!/usr/bin/env bash
# End-to-end test: settings (validation, effect on the dashboard), financial year lock, audit log.
#
#   bash tests/e2e/settings.sh [BASE_URL]        (FRESHLY seeded local DB)
set -u
BASE="${1:-http://localhost/marketing_crm}"
TMP="$(mktemp -d)"
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); echo "  PASS  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL  $1  ($2)"; }
expect()   { [ "$2" = "$3" ] && ok "$1" || bad "$1" "expected '$3', got '$2'"; }
contains() { grep -q -- "$3" "$2" && ok "$1" || bad "$1" "'$3' not found"; }
lacks()    { grep -q -- "$3" "$2" && bad "$1" "'$3' present" || ok "$1"; }

req() { local jar="$TMP/$1" method="$2" path="$3"; shift 3
  curl -s -o "$TMP/body" -b "$jar" -c "$jar" -X "$method" -w '%{http_code} %{redirect_url}' "$@" "$BASE$path"; }
csrf() { req "$1" GET "$2" >/dev/null; grep -o 'name="_csrf" value="[^"]*"' "$TMP/body" | head -1 | sed 's/.*value="//;s/"$//'; }
post() { local jar="$1" path="$2" page="$3"; shift 3; local t; t=$(csrf "$jar" "$page"); req "$jar" POST "$path" --data-urlencode "_csrf=$t" "$@"; }
first_login() {
  post "$1" /login /login --data-urlencode "identifier=$2" --data-urlencode "password=$3" >/dev/null
  post "$1" /password/change /password/change --data-urlencode "current_password=$3" --data-urlencode "new_password=$4" --data-urlencode "confirm_password=$4" >/dev/null
}
salesTotal() { req A GET "/?view=bills" >/dev/null; tr -d '\n' < "$TMP/body" | grep -o 'Total sales FY [0-9-]*</span>[^₹]*₹[0-9,]*' | grep -o '₹[0-9,]*' | head -1; }
settings() { post A /settings /settings --data-urlencode "company__name=$1" --data "finance__sales_amount_basis=$2&outstanding__source=$3&outstanding__aging_basis=$4" --data-urlencode "outstanding__aging_buckets=$5"; }

first_login A admin 'Admin@2026' 'Teal-Harbour-7391'
first_login C coordinator 'Coord@2026' 'Saffron-Kite-4415'
first_login J jana 'Sales@2026' 'Monsoon-Field-2087'

echo "== Settings page"
r=$(req A GET /settings); expect "settings page" "${r%% *}" 200
contains "menu live (no more Soon items)" "$TMP/body" 'href="/marketing_crm/settings"'
lacks "no Soon badges left" "$TMP/body" ">Soon<"
contains "secrets not shown" "$TMP/body" "never shown here"
contains "buckets shown as list" "$TMP/body" 'value="30, 60, 90, 150"'

echo "== Sales basis changes the dashboard"
BEFORE=$(salesTotal)
r=$(settings 'LAKSHMI SAFETY EQUIPMENT PRIVATE LIMITED' total computed invoice_date '30, 60, 90, 150'); req A GET /settings >/dev/null
contains "saved message" "$TMP/body" "Sales figures use"
AFTER=$(salesTotal)
[ -n "$BEFORE" ] && [ -n "$AFTER" ] && [ "$BEFORE" != "$AFTER" ] && ok "dashboard sales changed $BEFORE -> $AFTER (incl. GST)" || bad "dashboard sales changed" "$BEFORE / $AFTER"
settings 'LAKSHMI SAFETY EQUIPMENT PRIVATE LIMITED' taxable computed invoice_date '30, 60, 90, 150' >/dev/null
expect "back to taxable restores the figure" "$(salesTotal)" "$BEFORE"

echo "== Validation"
settings 'X' taxable computed invoice_date '30, 20, 90, 150' >/dev/null; req A GET /settings >/dev/null
contains "decreasing buckets refused" "$TMP/body" "keep increasing"
settings 'X' taxable imported invoice_date '30, 60, 90, 150' >/dev/null; req A GET /settings >/dev/null
contains "imported source needs a statement" "$TMP/body" "No outstanding statement has been uploaded"
contains "nothing changed after error" "$TMP/body" "Nothing was changed"
settings '' taxable computed invoice_date '30, 60, 90, 150' >/dev/null; req A GET /settings >/dev/null
contains "empty company name refused" "$TMP/body" "Enter the company name"
settings 'Lakshmi Medi Shop' taxable computed due_date '15, 45, 90, 180' >/dev/null
r=$(req A GET /reports/outstanding-ageing); contains "new buckets used by reports" "$TMP/body" "91-180 days"
settings 'LAKSHMI SAFETY EQUIPMENT PRIVATE LIMITED' taxable computed invoice_date '30, 60, 90, 150' >/dev/null

echo "== Financial years"
r=$(post A /settings/years /settings); req A GET /settings >/dev/null
contains "next FY added" "$TMP/body" "FY 2028-29"
FY1=$(tr -d '\n' < "$TMP/body" | grep -o 'settings/years/[0-9]*/lock' | tail -1 | grep -o '[0-9]\+')
post A "/settings/years/$FY1/lock" /settings >/dev/null; req A GET /settings >/dev/null
contains "finished year locked" "$TMP/body" "FY 2025-26 is locked"
CUR=$(tr -d '\n' < "$TMP/body" | grep -o 'FY 2026-27' | head -1)
r=$(req A GET /imports/new/collections)
printf 'Receipt No,Receipt Date,Customer Code,Amount,Payment Mode\nLOCK-1,15-03-2026,CUS-00001,100,NEFT\n' > "$TMP/l.csv"
t=$(csrf A /imports/new/collections); r=$(req A POST /imports/new/collections -F "_csrf=$t" -F "file=@$TMP/l.csv"); B=$(echo "$r" | grep -o 'imports/[0-9]*' | grep -o '[0-9]*')
post A "/imports/$B/map" "/imports/$B/map" --data-urlencode "map[receipt_no]=Receipt No" --data-urlencode "map[receipt_date]=Receipt Date" --data-urlencode "map[customer_code]=Customer Code" --data-urlencode "map[amount]=Amount" --data-urlencode "map[payment_mode]=Payment Mode" >/dev/null
req A GET "/imports/$B" >/dev/null
contains "import into locked year refused" "$TMP/body" "FY 2025-26 is locked"
post A "/settings/years/$FY1/lock" /settings >/dev/null; req A GET /settings >/dev/null
contains "year unlocked" "$TMP/body" "FY 2025-26 is unlocked"
CID=$(tr -d '\n' < "$TMP/body" | grep -o 'FY 2026-27</strong>.\{0,600\}' | grep -o 'settings/years/[0-9]*/lock' | head -1)
[ -z "$CID" ] && ok "current year has no Lock button" || bad "current year has no Lock button" "$CID"

echo "== Audit log"
r=$(req A GET /settings/audit); expect "audit page" "${r%% *}" 200
contains "setting change logged" "$TMP/body" "setting.changed"
contains "before -> after shown" "$TMP/body" "finance.sales_amount_basis: taxable → total"
contains "FY lock logged" "$TMP/body" "financial_year.locked"
r=$(req A GET "/settings/audit?module=settings&action=locked"); contains "filters work" "$TMP/body" "financial_year.locked"
lacks "filter excludes others" "$TMP/body" "setting.changed"
r=$(req A GET /settings/audit/export); expect "audit CSV" "${r%% *}" 200
contains "CSV header" "$TMP/body" "Time,User,Role,IP,Action"

echo "== Permissions"
r=$(req C GET /settings); expect "coordinator has no settings (403)" "${r%% *}" 403
r=$(req C GET /settings/audit); expect "coordinator has no audit (403)" "${r%% *}" 403
t=$(csrf C /reports)
r=$(req C POST /settings --data-urlencode "_csrf=$t" --data "company__name=Hacked"); expect "coordinator cannot save settings (403)" "${r%% *}" 403
r=$(req J GET /settings); expect "sales exec has no settings (403)" "${r%% *}" 403

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
