#!/usr/bin/env bash
# End-to-end test: report centre, filters, totals, CSV and Excel export, permissions.
#
#   bash tests/e2e/reports.sh [BASE_URL]        (FRESHLY seeded local DB)
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

first_login A admin 'Admin@2026' 'Teal-Harbour-7391'
first_login C coordinator 'Coord@2026' 'Saffron-Kite-4415'
first_login J jana 'Sales@2026' 'Monsoon-Field-2087'

echo "== Report centre"
r=$(req A GET /reports); expect "reports page" "${r%% *}" 200
contains "menu live" "$TMP/body" 'href="/marketing_crm/reports"'
for k in sales-register sales-by-customer sales-by-product target-achievement collection-register outstanding-ageing pending-orders pending-samples-dc lead-conversion followups-due sms-usage email-summary; do
  r=$(req A GET "/reports/$k"); expect "report $k opens" "${r%% *}" 200
done

echo "== Totals match the dashboard"
req A GET "/?view=bills" >/dev/null
DASH=$(tr -d '\n' < "$TMP/body" | grep -o 'Total sales FY 2026-27</span>[^₹]*₹[0-9,]*' | grep -o '₹[0-9,]*' | head -1)
req A GET /reports/sales-register >/dev/null
TOT=$(tr -d '\n' < "$TMP/body" | grep -o '<tr class="total-row">.*</tr>' | grep -o '₹[0-9,]*' | head -1)
[ -n "$DASH" ] && [ "$DASH" = "$TOT" ] && ok "sales register total $TOT = dashboard $DASH" || bad "sales register total = dashboard" "report '$TOT' vs dashboard '$DASH'"
r=$(req A GET "/reports/sales-register?from=2026-10-01&to=2026-10-06"); contains "period shown" "$TMP/body" "01-10-2026 to 06-10-2026"
r=$(req A GET "/reports/sales-register?customer=CUS-00001"); lacks "customer filter" "$TMP/body" "Kongu Textiles"
r=$(req A GET "/reports/sales-register?customer=CUS-99999"); contains "bad customer notice" "$TMP/body" "was not found"
r=$(req A GET "/reports/outstanding-ageing?as_on=2026-06-30"); contains "as-on shown" "$TMP/body" "as on 30-06-2026"
contains "ageing buckets" "$TMP/body" "150+ days"

echo "== Export"
curl -s -o "$TMP/r.xlsx" -b "$TMP/A" "$BASE/reports/sales-register/export/xlsx"
[ "$(head -c 2 "$TMP/r.xlsx")" = "PK" ] && ok "Excel export is an xlsx file" || bad "Excel export is an xlsx file" "$(head -c 40 "$TMP/r.xlsx")"
r=$(req A GET "/reports/collection-register/export/csv"); expect "CSV export" "${r%% *}" 200
contains "CSV header" "$TMP/body" "Date,Receipt,Customer,Mode"
contains "CSV total line" "$TMP/body" "^TOTAL,"
r=$(req A GET "/reports/bogus"); expect "unknown report 404" "${r%% *}" 404

echo "== Permissions"
r=$(req J GET /reports); expect "sales exec has no reports (403)" "${r%% *}" 403
r=$(req J GET /reports/sales-register); expect "sales exec cannot open a report (403)" "${r%% *}" 403
r=$(req C GET /reports); expect "coordinator: no Reports menu (403)" "${r%% *}" 403
r=$(req C GET /reports/sales-register/export/xlsx); expect "coordinator cannot export (403)" "${r%% *}" 403

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
