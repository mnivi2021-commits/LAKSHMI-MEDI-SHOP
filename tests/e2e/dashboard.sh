#!/usr/bin/env bash
# End-to-end test: dashboard page, filters, scoped lookups and quick ADD over HTTP.
#
#   bash tests/e2e/dashboard.sh [BASE_URL]
#
# Requires a FRESHLY SEEDED local database (php cli/install.php --fresh --seed).
set -u
BASE="${1:-http://localhost/marketing_crm}"
TMP="$(mktemp -d)"
PASS=0; FAIL=0
TODAY=$(date +%F)

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
add() { local jar="$1" type="$2"; shift 2; local t; t=$(csrf "$jar" /); req "$jar" POST "/dashboard/add/$type" -H 'Accept: application/json' --data-urlencode "_csrf=$t" "$@"; }
ids() { grep -o '"id":[0-9]*' "$TMP/body" | grep -o '[0-9]*' | sort -n | tr '\n' ' ' | sed 's/ $//'; }

echo "== Admin dashboard"
first_login A admin 'Admin@2026' 'Teal-Harbour-7391'
r=$(req A GET "/?view=bills");                     expect "dashboard loads" "${r%% *}" 200
contains "shows as-on date" "$TMP/body" "As on <strong>$(date +%d-%m-%Y)</strong>"
contains "live badge for today" "$TMP/body" ">Live<"
contains "financial year filter" "$TMP/body" 'name="fy"'
contains "branch filter lists all branches" "$TMP/body" "Coimbatore Branch (CBE)"
contains "Sales Performance card" "$TMP/body" "Sales Performance"
contains "Payment Collection card" "$TMP/body" "Payment Collection"
contains "Branch Pending Order card" "$TMP/body" "Branch Pending Order"
contains "ADD button for admin" "$TMP/body" "+ ADD"
contains "all six ADD tabs" "$TMP/body" 'data-tab="dc"'
contains "data freshness strip" "$TMP/body" "Latest entries in your view"
lacks "no inline scripts (CSP)" "$TMP/body" "<script>"

r=$(req A GET "/?view=bills&month=2026-08");     contains "past month as-on = month end" "$TMP/body" "As on <strong>31-08-2026</strong>"
contains "past month is a closed period" "$TMP/body" "Closed period"
r=$(req A GET "/?view=bills&branch=999");        contains "unknown branch explained" "$TMP/body" "do not have access to the selected branch"

echo "== Lookups (scoped)"
r=$(req A GET "/dashboard/lookup/customers?q=");   expect "customer lookup" "${r%% *}" 200
expect "admin sees all 12 customers" "$(grep -o '"id":' "$TMP/body" | wc -l | tr -d ' ')" 12
r=$(req A GET "/dashboard/lookup/customers?q=kongu"); contains "search by name" "$TMP/body" "Kongu Textiles"
r=$(req A GET "/dashboard/lookup/products?q=tape");  contains "product lookup with GST" "$TMP/body" '"gst":"18"'
r=$(req A GET "/dashboard/lookup/users");            expect "unknown lookup = 404" "${r%% *}" 404

echo "== Quick ADD (JSON)"
r=$(add A sale --data "invoice_no=E2E/INV/1&invoice_date=$TODAY&customer_id=7&product_id=2&quantity=5&taxable_amount=5750")
expect "sale saved (200)" "${r%% *}" 200
contains "sale message shows total incl. GST" "$TMP/body" "6,785.00"
r=$(add A sale --data "invoice_no=E2E/INV/1&invoice_date=$TODAY&customer_id=7&product_id=2&quantity=5&taxable_amount=5750")
expect "duplicate invoice = 422" "${r%% *}" 422
contains "duplicate explained" "$TMP/body" "already exists"
r=$(add A collection --data "receipt_no=E2E/RCP/1&receipt_date=$TODAY&customer_id=7&amount=6785&payment_mode=neft&auto_allocate=1")
expect "collection saved" "${r%% *}" 200
contains "collection adjusted against bills" "$TMP/body" "oldest bill"
r=$(add A target --data "employee_id=4&fy_id=2&month=all&sales_target=900000&collection_target=810000")
expect "yearly target saved" "${r%% *}" 200
r=$(add A pending_order --data "order_no=E2E/SO/1&order_date=$TODAY&customer_id=7&product_id=6&order_qty=10&order_value=14500")
expect "pending order saved" "${r%% *}" 200
r=$(add A sample --data "document_no=E2E/SMP/1&document_date=$TODAY&customer_id=7&product_id=8&quantity=1&sample_value=2100&supply_status=supplied")
expect "sample saved" "${r%% *}" 200
r=$(add A dc --data "dc_no=E2E/DC/1&dc_date=$TODAY&customer_id=7&product_id=8&quantity=1&dc_value=2100")
expect "DC saved" "${r%% *}" 200
r=$(add A sale --data "invoice_no=&invoice_date=2099-01-01&customer_id=abc&quantity=-1&taxable_amount=x")
expect "invalid sale = 422" "${r%% *}" 422
contains "field errors returned" "$TMP/body" '"invoice_no"'
r=$(req A POST /dashboard/add/sale -H 'Accept: application/json' --data "invoice_no=X")
expect "missing CSRF = 403" "${r%% *}" 403
contains "CSRF error is JSON" "$TMP/body" '"success":false'
r=$(req A GET "/?view=bills");                     contains "freshness reflects today's entries" "$TMP/body" "$(date +%d-%m-%Y)"

echo "== Coordinator (view only)"
first_login C coordinator 'Coord@2026' 'Saffron-Kite-4415'
r=$(req C GET "/?view=bills");                     expect "coordinator dashboard" "${r%% *}" 200
lacks "no ADD button without add rights" "$TMP/body" "+ ADD"
r=$(add C sale --data "invoice_no=C/1&invoice_date=$TODAY&customer_id=1&product_id=1&quantity=1&taxable_amount=1")
expect "server refuses coordinator ADD (403)" "${r%% *}" 403

echo "== Sales executive JANA (own data only)"
first_login J jana 'Sales@2026' 'Monsoon-Field-2087'
r=$(req J GET "/?view=bills");                     expect "JANA dashboard" "${r%% *}" 200
contains "branch filter = own branch only" "$TMP/body" "Chennai Head Office (CHN)"
lacks "no other branches offered" "$TMP/body" "Coimbatore Branch (CBE)"
lacks "no other reps offered" "$TMP/body" "MUKESH - "
r=$(req J GET "/dashboard/lookup/customers?q=");   expect "JANA's lookup = her customers 1, 2, 5" "$(ids)" "1 2 5"
r=$(req J GET "/?view=bills&branch=2&customer=6");            contains "URL tampering is refused and explained" "$TMP/body" "do not have access to the selected branch"
r=$(add J collection --data "receipt_no=J/1&receipt_date=$TODAY&customer_id=1&amount=1&payment_mode=upi")
expect "JANA has no collection add right (403)" "${r%% *}" 403

echo "== Not signed in"
r=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/dashboard/lookup/customers?q="); expect "lookup requires sign-in" "$r" 303

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
