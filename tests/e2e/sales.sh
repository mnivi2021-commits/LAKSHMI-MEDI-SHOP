#!/usr/bin/env bash
# End-to-end test: Step A1 Sales Performance card, drill-down, CSV export, mobile API.
#
#   bash tests/e2e/sales.sh [BASE_URL]
#
# Requires a FRESHLY SEEDED local database and the real date 06-10-2026 for the
# seeded figures (the date-independent checks still run on other days).
set -u
BASE="${1:-http://localhost/marketing_crm}"
TMP="$(mktemp -d)"
PASS=0; FAIL=0
SEED_DAY=$([ "$(date +%F)" = "2026-10-06" ] && echo 1 || echo 0)

ok()   { PASS=$((PASS+1)); echo "  PASS  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL  $1  ($2)"; }
expect()   { [ "$2" = "$3" ] && ok "$1" || bad "$1" "expected '$3', got '$2'"; }
contains() { grep -q -- "$3" "$2" && ok "$1" || bad "$1" "'$3' not found"; }
lacks()    { grep -q -- "$3" "$2" && bad "$1" "'$3' present" || ok "$1"; }
seeded()   { [ "$SEED_DAY" = 1 ] && contains "$@" || echo "  SKIP  $1 (not 06-10-2026)"; }

req() { local jar="$TMP/$1" method="$2" path="$3"; shift 3
  curl -s -o "$TMP/body" -D "$TMP/headers" -b "$jar" -c "$jar" -X "$method" -w '%{http_code} %{redirect_url}' "$@" "$BASE$path"; }
csrf() { req "$1" GET "$2" >/dev/null; grep -o 'name="_csrf" value="[^"]*"' "$TMP/body" | head -1 | sed 's/.*value="//;s/"$//'; }
post() { local jar="$1" path="$2" page="$3"; shift 3; local t; t=$(csrf "$jar" "$page"); req "$jar" POST "$path" --data-urlencode "_csrf=$t" "$@"; }
first_login() {
  post "$1" /login /login --data-urlencode "identifier=$2" --data-urlencode "password=$3" >/dev/null
  post "$1" /password/change /password/change --data-urlencode "current_password=$3" --data-urlencode "new_password=$4" --data-urlencode "confirm_password=$4" >/dev/null
}

echo "== Admin: card"
first_login A admin 'Admin@2026' 'Teal-Harbour-7391'
r=$(req A GET "/?view=bills");  expect "dashboard loads" "${r%% *}" 200
contains "card is live (no placeholder)" "$TMP/body" 'kpi-live'
seeded "total sales FY to date" "$TMP/body" "₹38,23,200"
seeded "annual target" "$TMP/body" "₹45,60,000"
seeded "achieved %" "$TMP/body" "83.8%"
contains "previous-day period label" "$TMP/body" "Sales as on previous day"
contains "average monthly line" "$TMP/body" "Average monthly sales"
contains "required monthly line" "$TMP/body" "Required monthly sales"
contains "drill-down link" "$TMP/body" 'dashboard/sales?fy='
contains "progress bar" "$TMP/body" 'class="progress"'

echo "== Admin: drill-down"
r=$(req A GET "/dashboard/sales?fy=2");  expect "detail page" "${r%% *}" 200
contains "month-wise chart" "$TMP/body" '<svg viewBox'
contains "chart has hover tooltips" "$TMP/body" '<title>Apr 2026'
contains "month table" "$TMP/body" "Cumulative sales"
contains "branch breakdown" "$TMP/body" "By branch"
contains "employee breakdown" "$TMP/body" "By sales employee"
contains "document list reconciles" "$TMP/body" "matches dashboard"
lacks "never a mismatch" "$TMP/body" "does not match dashboard"
contains "credit note shown" "$TMP/body" "credit note"
for w in prev month today; do
  req A GET "/dashboard/sales?fy=2&w=$w" >/dev/null
  if grep -q "There are no days in this period yet" "$TMP/body"; then ok "window $w (empty period handled)"
  else contains "window $w reconciles" "$TMP/body" "matches dashboard"; fi
done
r=$(req A GET "/dashboard/sales?fy=2&month=2026-08"); contains "past month detail" "$TMP/body" "31-08-2026"
contains "past month reconciles" "$TMP/body" "matches dashboard"
r=$(req A GET "/dashboard/sales?fy=2&customer=4");   contains "customer filter: target n/a" "$TMP/body" "do not apply to a customer"

echo "== Admin: CSV export"
r=$(req A GET "/dashboard/sales/export?fy=2&w=fy"); expect "export 200" "${r%% *}" 200
grep -qi 'content-type: text/csv' "$TMP/headers" && ok "CSV content type" || bad "CSV content type" "missing"
grep -qi 'attachment; filename="sales_fy_' "$TMP/headers" && ok "download filename" || bad "download filename" "missing"
contains "CSV header row" "$TMP/body" "Date,Document,Type"
contains "CSV total row" "$TMP/body" "TOTAL (taxable)"
seeded "CSV total equals card" "$TMP/body" "3823200.00"
ROWS=$(($(wc -l < "$TMP/body") - 2))
[ "$ROWS" -gt 100 ] && ok "CSV has all $ROWS documents (not just one page)" || bad "CSV row count" "$ROWS"

echo "== Coordinator: view but no export"
first_login C coordinator 'Coord@2026' 'Saffron-Kite-4415'
r=$(req C GET "/dashboard/sales"); expect "coordinator sees drill-down (sales.view)" "${r%% *}" 200
lacks "no export button without sales.export" "$TMP/body" "Export CSV"
r=$(req C GET "/dashboard/sales/export?w=fy"); expect "export refused (403)" "${r%% *}" 403

echo "== JANA: own figures only"
first_login J jana 'Sales@2026' 'Monsoon-Field-2087'
r=$(req J GET "/?view=bills");               seeded "JANA total" "$TMP/body" "₹9,53,300"
seeded "JANA target" "$TMP/body" "₹10,80,000"
r=$(req J GET "/dashboard/sales?employee=3&branch=2"); contains "tampered filters refused" "$TMP/body" "do not have access"
seeded "JANA detail still JANA only" "$TMP/body" "₹9,53,300"
lacks "no other reps in breakdown" "$TMP/body" "MUKESH - "

echo "== Mobile API"
curl -s -o "$TMP/body" -X POST -H 'Content-Type: application/json' -d '{"username":"jana","password":"Monsoon-Field-2087","device_name":"e2e"}' "$BASE/api/auth/login"
TOKEN=$(grep -o '"token":"[^"]*"' "$TMP/body" | sed 's/"token":"//;s/"$//')
s=$(curl -s -o "$TMP/body" -w '%{http_code}' -H "Authorization: Bearer $TOKEN" "$BASE/api/dashboard/sales"); expect "API 200" "$s" 200
seeded "API: JANA total as decimal" "$TMP/body" '"sales_total":"953300.00"'
contains "API: monthly series" "$TMP/body" '"monthly":\['
s=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/dashboard/sales"); expect "API without token = 401" "$s" 401

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
