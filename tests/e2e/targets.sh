#!/usr/bin/env bash
# End-to-end test: Targets tab (annual targets by division / area / employee), Follow up area & division
# filters, Branch Details sales team.
#
#   bash tests/e2e/targets.sh [BASE_URL]        (FRESHLY seeded local DB)
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
first_login J jana 'Sales@2026' 'Monsoon-Field-2087'

echo "== Targets tab"
r=$(req A GET /); contains "dashboard shows the company name" "$TMP/body" "LAKSHMI SAFETY EQUIPMENT PRIVATE LIMITED</h1>"
lacks "no Year box" "$TMP/body" "aria-label=\"Year\""
lacks "no Branch performance tab" "$TMP/body" ">Branch performance<"
contains "+ ADD on dashboard" "$TMP/body" 'href="/marketing_crm/entry"'
r=$(req A GET /targets); expect "targets page" "${r%% *}" 200
for h in "ALL BRANCHES ANNUAL TARGET 2026-27" "Area-wise target" "Sales employee target" "Sales coordinators" "Admin Head · Sales Manager"; do contains "box: $h" "$TMP/body" "$h"; done
lacks "division box removed" "$TMP/body" "Division target"
lacks "branch total box removed" "$TMP/body" "Branch total target"
for d in PPE MAAP TRAINING; do contains "division $d" "$TMP/body" ">$d<"; done
for a in Madurai Trichy TTN TVL; do contains "area box $a" "$TMP/body" "<h3>$a</h3>"; done
contains "Chennai annual 80 lakh" "$TMP/body" "₹80,00,000"
contains "month = annual / 12 (80 lakh / 12)" "$TMP/body" "₹6,66,667"
contains "all branches total" "$TMP/body" "₹1,94,00,000"
r=$(req A GET "/?branch=3"); contains "Madurai title" "$TMP/body" "MADURAI BRANCH ANNUAL TARGET 2026-27"
contains "Madurai annual" "$TMP/body" "₹54,00,000"
contains "Madurai this month" "$TMP/body" "₹4,50,000"
lacks "only Madurai row" "$TMP/body" "<b>Chennai Head Office</b>"
for a in Madurai Trichy TTN TVL; do contains "Madurai branch shows area $a" "$TMP/body" "<h3>$a</h3>"; done
lacks "Madurai branch: no Jana in employee list" "$TMP/body" "<b>JANA</b>"
r=$(req A GET "/?branch=1"); lacks "Chennai: no Madurai areas" "$TMP/body" "<h3>Trichy</h3>"
contains "Chennai: no-areas message" "$TMP/body" "No sales areas for this branch yet"
contains "Chennai: Jana listed" "$TMP/body" "<b>JANA</b>"
contains "Apr - Mar shown" "$TMP/body" "Apr 2026 – Mar 2027"
req A GET / >/dev/null; contains "coordinator with reps" "$TMP/body" "Divya R"
contains "admin head listed" "$TMP/body" "Admin Head"

echo "== Set annual targets"
r=$(req A GET /targets/edit?fy=2); expect "edit sheet" "${r%% *}" 200
contains "branch box" "$TMP/body" 'name="t\[b3\]"'
contains "area x division box" "$TMP/body" 'name="t\[a2d1\]"'
contains "employee x division box" "$TMP/body" 'name="t\[e2d1\]"'
r=$(post A /targets /targets/edit?fy=2 --data "fy=2" --data-urlencode "t[b3]=1,20,00,000" --data "t[a1d1]=3000000&t[e2d1]=")
case "$r" in "303 "*"/?fy=2"*) ok "saved";; *) bad "saved" "$r";; esac
req A GET /targets?fy=2 >/dev/null
contains "new Madurai annual" "$TMP/body" "₹1,20,00,000"
contains "new Madurai month (÷12)" "$TMP/body" "₹10,00,000"
post A /targets /targets/edit?fy=2 --data "fy=2&t[b1]=lots" >/dev/null; req A GET /targets/edit?fy=2 >/dev/null
contains "bad amount refused" "$TMP/body" "need correcting"
r=$(req J GET /targets); expect "rep can view targets" "${r%% *}" 200
r=$(req J GET /targets/edit); expect "rep cannot set targets" "${r%% *}" 403

echo "== HRM lists: divisions and sales areas"
r=$(req A GET /hrm/lists/divisions); expect "divisions list" "${r%% *}" 200
contains "PPE in list" "$TMP/body" "PPE"
r=$(req A GET /hrm/lists/sales_areas); expect "sales areas list" "${r%% *}" 200
contains "TTN in list" "$TMP/body" "TTN"

echo "== Follow up: area and division boxes"
r=$(req A GET "/followup"); contains "area box" "$TMP/body" 'name="area"'
contains "division box" "$TMP/body" 'name="division"'
r=$(req A GET "/followup?area=Madurai"); contains "Madurai area: Muthuvel offered" "$TMP/body" "MUTHUVEL - Muthuvel P"
lacks "Madurai area: Jana not offered" "$TMP/body" "JANA - Janakiraman"
r=$(req A GET "/followup?division=3"); contains "TRAINING division: Prakash" "$TMP/body" "PRAKASH - Prakash M"
lacks "TRAINING division: no Jana" "$TMP/body" "JANA - Janakiraman"

echo "== Branch Details: sales team"
r=$(req A GET "/branches"); contains "sales name box" "$TMP/body" 'name="rep"'
contains "columns" "$TMP/body" "OP outstanding"
lacks "branch box removed" "$TMP/body" 'name="team_branch"'
contains "division shown" "$TMP/body" "PPE, MAAP"
contains "80% of 65,000 target" "$TMP/body" "₹52,000"
r=$(req A GET "/branches?rep=6"); expect "one rep" "${r%% *}" 200
for h in "<h3>Sales</h3>" "<h3>Pending order</h3>" "<h3>Payment</h3>" "<h3>Sample &amp; DC</h3>"; do contains "box $h" "$TMP/body" "$h"; done
contains "annual target (15 + 6 lakh)" "$TMP/body" "₹21,00,000"
contains "OP O/S" "$TMP/body" "₹3,75,000"
lacks "only this rep (no team table)" "$TMP/body" "<b>JANA</b>"
lacks "branch list cleared" "$TMP/body" 'name="q"'
contains "manage branches button" "$TMP/body" "Manage branches"
r=$(req A GET "/branches?rep=2"); contains "pending orders customer-wise" "$TMP/body" "Pending order details · customer-wise"
contains "customer heading row" "$TMP/body" 'class="cust-row"'
contains "reason drop box" "$TMP/body" 'name="reason"'
contains "open DC customer-wise" "$TMP/body" "Open DC · customer-wise"
OID=$(grep -o 'branches/order-reason/[0-9]*' "$TMP/body" | head -1 | grep -o '[0-9]*$')
r=$(post A "/branches/order-reason/$OID" "/branches?rep=2" --data "reason=payment_pending")
case "$r" in "303 "*"rep=2"*) ok "reason saved";; *) bad "reason saved" "$r";; esac
req A GET "/branches?rep=2" >/dev/null; contains "reason shown as chosen" "$TMP/body" 'value="payment_pending" selected'
r=$(req A GET "/branches?view=list"); contains "branch list on Manage branches" "$TMP/body" 'name="q"'

echo
echo "targets e2e: $PASS passed, $FAIL failed"
rm -rf "$TMP"
[ "$FAIL" -eq 0 ]
