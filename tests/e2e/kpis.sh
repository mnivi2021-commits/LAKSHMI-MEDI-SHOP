#!/usr/bin/env bash
# End-to-end test: KPI detail pages (Collection, Pending Order, Representative,
# Outstanding, Customer / Product) - pages load, every list reconciles with the
# dashboard figure, exports and APIs work, permissions and data scope hold.
#
#   bash tests/e2e/kpis.sh [BASE_URL]        (FRESHLY seeded local DB)
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
  curl -s -o "$TMP/body" -D "$TMP/headers" -b "$jar" -c "$jar" -X "$method" -w '%{http_code} %{redirect_url}' "$@" "$BASE$path"; }
csrf() { req "$1" GET "$2" >/dev/null; grep -o 'name="_csrf" value="[^"]*"' "$TMP/body" | head -1 | sed 's/.*value="//;s/"$//'; }
post() { local jar="$1" path="$2" page="$3"; shift 3; local t; t=$(csrf "$jar" "$page"); req "$jar" POST "$path" --data-urlencode "_csrf=$t" "$@"; }
first_login() {
  post "$1" /login /login --data-urlencode "identifier=$2" --data-urlencode "password=$3" >/dev/null
  post "$1" /password/change /password/change --data-urlencode "current_password=$3" --data-urlencode "new_password=$4" --data-urlencode "confirm_password=$4" >/dev/null
}
# page_ok JAR PATH NAME : 200, no server error, no "does not match dashboard"
page_ok() {
  local r; r=$(req "$1" GET "$2")
  expect "$3 loads" "${r%% *}" 200
  lacks "$3 reconciles" "$TMP/body" "does not match dashboard"
}
token() { curl -s -X POST -H 'Content-Type: application/json' -d "{\"username\":\"$1\",\"password\":\"$2\"}" "$BASE/api/auth/login" | grep -o '"token":"[^"]*"' | sed 's/"token":"//;s/"$//'; }

first_login A admin 'Admin@2026' 'Teal-Harbour-7391'
first_login C coordinator 'Coord@2026' 'Saffron-Kite-4415'
first_login J jana 'Sales@2026' 'Monsoon-Field-2087'
TA=$(token admin 'Teal-Harbour-7391'); TJ=$(token jana 'Monsoon-Field-2087')

echo "== A2 Payment Collection"
r=$(req A GET "/?view=bills"); contains "collection card live" "$TMP/body" 'data-kpi="collection"'
contains "collection card shows overdue" "$TMP/body" "Overdue collection"
for w in month prev today fy; do page_ok A "/dashboard/collection?w=$w" "collection window $w"; done
contains "overdue bill list" "$TMP/body" "Overdue bills (oldest first)"
page_ok A "/dashboard/collection?month=2026-08" "collection past month"
r=$(req A GET "/dashboard/collection?product=2"); contains "product filter explained" "$TMP/body" "not per product"
r=$(req A GET "/dashboard/collection/export?w=fy"); expect "collection CSV" "${r%% *}" 200
contains "CSV columns" "$TMP/body" 'Date,Receipt,"Customer code"'
lacks "bounced cheque not exported" "$TMP/body" "RCP/26-27/0904"
r=$(req C GET "/dashboard/collection"); expect "coordinator: no collection drill-down (403)" "${r%% *}" 403
r=$(req C GET "/dashboard/collection/export?w=fy"); expect "coordinator cannot export (403)" "${r%% *}" 403
r=$(req J GET "/dashboard/collection"); expect "JANA collection page" "${r%% *}" 200
lacks "JANA sees no other rep" "$TMP/body" "MUKESH - "
s=$(curl -s -o "$TMP/body" -w '%{http_code}' -H "Authorization: Bearer $TJ" "$BASE/api/dashboard/collection"); expect "collection API" "$s" 200
contains "API has overdue" "$TMP/body" '"overdue":"'

echo "== A3 Branch Pending Order"
r=$(req A GET "/?view=bills"); contains "pending card live" "$TMP/body" 'data-kpi="pending"'
contains "aging rows on card" "$TMP/body" "150+ days"
page_ok A "/dashboard/pending" "pending detail"
contains "oldest order shown" "$TMP/body" "SO/25-26/0412"
for b in 0-30 31-60 61-90 91-150 150%2B; do page_ok A "/dashboard/pending?bucket=$b" "pending bucket $b"; done
page_ok A "/dashboard/pending?month=2026-08" "pending as on 31-08"
r=$(req A GET "/dashboard/pending/export?bucket=150%2B"); expect "pending CSV" "${r%% *}" 200
contains "CSV has pending columns" "$TMP/body" "Pending value"
lacks "closed order not exported" "$TMP/body" "SO/26-27/0030"
r=$(req C GET "/dashboard/pending/export"); expect "coordinator cannot export pending (403)" "${r%% *}" 403
r=$(req J GET "/dashboard/pending"); expect "JANA pending page" "${r%% *}" 200
lacks "JANA sees no MUKESH orders" "$TMP/body" "SO/26-27/0085"
s=$(curl -s -o "$TMP/body" -w '%{http_code}' -H "Authorization: Bearer $TA" "$BASE/api/dashboard/pending"); expect "pending API" "$s" 200
contains "API has aging" "$TMP/body" '"aging":\['

echo "== Step B: Sales Representative panel"
r=$(req A GET "/?view=bills&employee=2"); expect "admin selects JANA" "${r%% *}" 200
contains "panel shows JANA's short name" "$TMP/body" 'rep-name">JANA<'
contains "panel has sales box" "$TMP/body" "rep-box"
contains "panel has overdue grid" "$TMP/body" "overdue-grid"
contains "JANA chip marked selected" "$TMP/body" 'aria-current="true"'
r=$(req A GET "/?view=bills&employee=999"); contains "unknown employee filter explained" "$TMP/body" "do not have access to the selected employee"
r=$(req J GET "/?view=bills"); contains "JANA auto-selected on her own dashboard" "$TMP/body" 'rep-name">JANA<'
contains "JANA has no Clear-selection link (only option)" "$TMP/body" "rep-panel"
lacks "JANA cannot select another rep via URL" "$TMP/body" "MUKESH R"
r=$(req J GET "/?view=bills&employee=3"); lacks "JANA cannot view MUKESH's panel" "$TMP/body" "MUKESH R"

echo "== 90 / 150 Day Outstanding"
r=$(req A GET /dashboard/collection); contains "collection links to outstanding" "$TMP/body" "dashboard/outstanding"
page_ok A "/dashboard/outstanding" "outstanding detail (all)"
contains "90 DAYS category shown" "$TMP/body" "90 days (91-150)"
contains "150 DAYS category shown" "$TMP/body" "150 days (over 150)"
for c in upto90 d90 d150; do page_ok A "/dashboard/outstanding?cat=$c" "outstanding category $c"; done
page_ok A "/dashboard/outstanding?month=2026-08" "outstanding as on 31-08"
r=$(req A GET "/dashboard/outstanding/export?cat=d150"); expect "outstanding CSV" "${r%% *}" 200
contains "CSV has outstanding columns" "$TMP/body" "Balance"
r=$(req C GET "/dashboard/outstanding"); expect "coordinator: no outstanding drill-down (403)" "${r%% *}" 403
r=$(req C GET "/dashboard/outstanding/export"); expect "coordinator cannot export outstanding (403)" "${r%% *}" 403
r=$(req J GET "/dashboard/outstanding"); expect "JANA outstanding page" "${r%% *}" 200
s=$(curl -s -o "$TMP/body" -w '%{http_code}' -H "Authorization: Bearer $TA" "$BASE/api/dashboard/outstanding"); expect "outstanding API" "$s" 200
contains "API has d90 category" "$TMP/body" '"d90"'

echo "== Section C: Customer / Product drill-down"
r=$(req A GET "/?view=bills&customer=1"); expect "admin selects a customer" "${r%% *}" 200
contains "customer panel shows name" "$TMP/body" "Sri Balaji Traders"
contains "customer panel has sales box" "$TMP/body" 'id="customer"'
contains "customer panel has overdue grid" "$TMP/body" "Overdue payment"
contains "customer panel shows last order/payment" "$TMP/body" "Last order"
r=$(req A GET "/?view=bills&product=3"); expect "admin selects a product" "${r%% *}" 200
contains "product panel shows name" "$TMP/body" "BOPP Tape"
contains "product panel has quantity sold" "$TMP/body" "Quantity sold"
contains "product panel explains collection n/a" "$TMP/body" "not apply here"
r=$(req A GET "/?view=bills&customer=1&product=3"); contains "both panels shown together" "$TMP/body" "Sri Balaji Traders"
contains "both panels shown together (product)" "$TMP/body" "BOPP Tape"
r=$(req J GET "/?view=bills&customer=1"); expect "JANA selects her own customer" "${r%% *}" 200
contains "JANA sees her customer's panel" "$TMP/body" "Sri Balaji Traders"
r=$(req J GET "/?view=bills&customer=6"); lacks "JANA cannot select another rep's customer" "$TMP/body" "Kongu Textiles"
contains "...and is told why" "$TMP/body" "do not have access to the selected customer"
r=$(req A GET "/?view=bills"); lacks "no customer panel without a selection" "$TMP/body" 'id="customer"'
lacks "no product panel without a selection" "$TMP/body" 'id="product"'

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
