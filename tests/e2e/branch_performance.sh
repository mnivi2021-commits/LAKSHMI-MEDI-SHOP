#!/usr/bin/env bash
# End-to-end test: Branch Performance first page, + ADD sheets (month start / daily), drill-down, permissions.
#
#   bash tests/e2e/branch_performance.sh [BASE_URL]        (FRESHLY seeded local DB)
set -u
BASE="${1:-http://localhost/marketing_crm}"
TMP="$(mktemp -d)"
PASS=0; FAIL=0
TODAY=$(date +%F); TODAY_DMY=$(date +%d-%m-%Y)

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

echo "== First page"
r=$(req A GET "/?view=performance"); expect "dashboard" "${r%% *}" 200
contains "Branch Performance title" "$TMP/body" "<h1>Branch Performance</h1>"
for s in "1. Sales performance" "2. Pending order · Enquiry · Lead" "3. Payment collection" "4. Open DC · Samples"; do contains "section: $s" "$TMP/body" "$s"; done
contains "email section kept at the end" "$TMP/body" 'id="sec-mail"'
lacks "no customer filter" "$TMP/body" 'name="customer"'
lacks "no product filter" "$TMP/body" 'name="product"'
lacks "no NOB column in the sales table" "$TMP/body" ">NOB<"
contains "branch rows" "$TMP/body" "Madurai Branch"
contains "+ ADD opens the sheet" "$TMP/body" 'href="/marketing_crm/entry"'
r=$(req A GET "/?view=performance&branch=3"); contains "Madurai: rep row" "$TMP/body" "MUTHUVEL - Muthuvel P"
lacks "Madurai: no Chennai rows" "$TMP/body" "Chennai Head Office</td>"
r=$(req A GET "/?view=bills"); expect "bill-wise view" "${r%% *}" 200
contains "bill-wise view keeps old cards" "$TMP/body" "Sales Performance"
contains "bill-wise view keeps customer filter" "$TMP/body" 'name="customer"'

echo "== Month start sheet"
r=$(req A GET "/entry?type=month&month=2026-11"); expect "month sheet" "${r%% *}" 200
contains "month headings" "$TMP/body" "Opening outstanding (₹)"
contains "sheet title" "$TMP/body" "NOV 26 MONTH SALES AND COLLECTION TARGET"
contains "80% column" "$TMP/body" "80%"
contains "60% column" "$TMP/body" "60%"
contains "one row per rep" "$TMP/body" 'name="rows\[6\]\[opening_outstanding\]"'
r=$(post A /entry/month "/entry?type=month&month=2026-11" --data "month=2026-11" --data-urlencode "rows[2][sales_target]=1,00,000" --data "rows[2][opening_outstanding]=250000")
case "$r" in "303 "*"type=day"*) ok "month saved -> daily sheet";; *) bad "month saved -> daily sheet" "$r";; esac
req A GET "/entry?type=month&month=2026-11" >/dev/null
contains "saved target shown" "$TMP/body" 'value="100000"'
contains "saved opening shown" "$TMP/body" 'value="250000"'
contains "60% collection target worked out" "$TMP/body" "₹1,50,000"
contains "80% of the target worked out" "$TMP/body" "₹80,000"
contains "branch total row" "$TMP/body" "Chennai Head Office total"
contains "grand total row" "$TMP/body" "Grand total"
post A /entry/month "/entry?type=month&month=2026-11" --data "month=2026-11&rows[3][sales_target]=lots" >/dev/null; req A GET "/entry?type=month&month=2026-11" >/dev/null
contains "bad amount refused" "$TMP/body" "need correcting"

echo "== Daily sheet"
r=$(req A GET "/entry?type=day&date=$TODAY&view=sheet"); expect "daily sheet" "${r%% *}" 200
for h in "Sales" "Collection" "Pending order" "Enquiry pending" "Lead created today" "Open DC" "Samples" "Outstanding"; do contains "heading: $h" "$TMP/body" ">$h<"; done
contains "grey hint from last figure" "$TMP/body" 'name="rows\[2\]\[po_non_stock\]" value=""'
post A /entry/day "/entry?type=day&date=$TODAY&view=sheet" --data "date=$TODAY&rows[2][sales_value]=12500&rows[2][sales_bills]=2&rows[2][sales_customers]=3" >/dev/null
req A GET "/entry?type=day&date=$TODAY&view=sheet" >/dev/null
contains "NOC > NOB refused" "$TMP/body" "need correcting"
post A /entry/day "/entry?type=day&date=$TODAY&view=sheet" --data "date=$TODAY&rows[2][sales_value]=12500" >/dev/null; req A GET "/entry?type=day&date=$TODAY&view=sheet" >/dev/null
contains "value without bills refused" "$TMP/body" "Enter the number of bills"
r=$(post A /entry/day "/entry?type=day&date=$TODAY&view=sheet" --data "date=$TODAY&rows[2][sales_bills]=2&rows[2][sales_customers]=2&rows[2][collection_value]=5000&rows[2][collection_bills]=1&rows[2][collection_customers]=1&rows[2][po_non_stock]=7000" --data-urlencode "rows[2][sales_value]=12,500")
req A GET "/entry?type=day&date=$TODAY&view=sheet" >/dev/null
contains "saved message" "$TMP/body" "Saved 1 row(s) for $TODAY_DMY"
contains "saved value stays in the sheet" "$TMP/body" 'name="rows\[2\]\[sales_value\]" value="12500"'
r=$(post A /entry/day /entry --data "date=2099-01-01&rows[2][sales_value]=1"); req A GET /entry?type=day >/dev/null
contains "future date refused" "$TMP/body" "not in the future"

echo "== Dashboard reflects the sheet"
req A GET "/?view=performance&employee=2" >/dev/null
TODAYSALES=$(tr -d '\n' < "$TMP/body" | grep -o 'metric=sales_today[^>]*>[^<]*' | head -1 | sed 's/.*>//')
expect "today sales on dashboard" "$TODAYSALES" "₹12,500"
req A GET "/dashboard/entries?metric=sales_today&employee=2" >/dev/null
contains "drill-down lists the sheet row" "$TMP/body" "Janakiraman S"
contains "drill-down shows who entered it" "$TMP/body" "Admin Head"
contains "drill-down total" "$TMP/body" "₹12,500"
req A GET "/dashboard/entries?metric=po_non_stock&employee=2" >/dev/null
contains "position drill-down uses today's figure" "$TMP/body" "₹7,000"
r=$(req A GET "/dashboard/entries?metric=bogus"); expect "unknown figure 404" "${r%% *}" 404

echo "== Daily entry: one sales person"
r=$(req A GET "/entry?type=day&date=$TODAY"); expect "person view opens" "${r%% *}" 200
contains "asks for a sales employee" "$TMP/body" "Choose a <b>sales employee</b>"
contains "sales area drop box" "$TMP/body" 'name="area"'
r=$(req A GET "/entry?type=day&date=$TODAY&area=Madurai"); contains "area with one person opens them" "$TMP/body" "Muthuvel P"
lacks "area filter hides others" "$TMP/body" "Janakiraman S <span"
r=$(req A GET "/entry?type=day&date=$TODAY&employee=2"); contains "left: sales box" "$TMP/body" "<legend>Sales</legend>"
for h in "Pending order" "Enquiry pending" "Lead pending" "Sample / DC" "Payment collection" "Outstanding"; do contains "box: $h" "$TMP/body" "<legend>$h"; done
contains "90 DAYS field" "$TMP/body" "90 DAYS (₹)"
contains "150 DAYS field" "$TMP/body" "150 DAYS (₹)"
contains "month to date shown" "$TMP/body" "This month:"
r=$(post A /entry/day "/entry?type=day&date=$TODAY&employee=3" --data "date=$TODAY&employee=3&rows[3][sales_value]=4000&rows[3][sales_bills]=1&rows[3][sales_customers]=1&rows[3][os_90]=11000")
case "$r" in "303 "*"employee=3"*) ok "person save returns to the same person";; *) bad "person save returns to the same person" "$r";; esac
req A GET "/entry?type=day&date=$TODAY&employee=3" >/dev/null; contains "saved value shown" "$TMP/body" 'value="4000"'
contains "saved 90 days shown" "$TMP/body" 'value="11000"'

echo "== Permissions and scope"
r=$(req J GET "/?view=performance"); contains "JANA sees her own row" "$TMP/body" "JANA - Janakiraman S"
lacks "JANA does not see MUKESH" "$TMP/body" "MUKESH - Mukesh R"
lacks "JANA (view only) has no + ADD" "$TMP/body" 'href="/marketing_crm/entry"'
r=$(req J GET "/entry?type=day&date=$TODAY&view=sheet"); expect "JANA (view only) cannot open the + ADD sheet (403)" "${r%% *}" 403
t=$(csrf J /)
r=$(req J POST /entry/day --data-urlencode "_csrf=$t" --data "date=$TODAY&rows[3][sales_value]=999&rows[3][sales_bills]=1&rows[3][sales_customers]=1"); expect "JANA cannot save entries (403)" "${r%% *}" 403
req A GET "/dashboard/entries?metric=sales_today&employee=3" >/dev/null
lacks "JANA cannot enter for MUKESH" "$TMP/body" "₹999"
r=$(req C GET /entry); expect "coordinator can open the daily sheet" "${r%% *}" 200
t=$(csrf C /entry)
r=$(req C POST /entry/month --data-urlencode "_csrf=$t" --data "month=2026-11&rows[2][sales_target]=1"); expect "coordinator (does the input) saves month targets" "${r%% *}" 303

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
