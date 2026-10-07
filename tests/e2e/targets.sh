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
r=$(req A GET /); contains "Targets tab link on dashboard" "$TMP/body" 'href="/marketing_crm/targets"'
r=$(req A GET /targets); expect "targets page" "${r%% *}" 200
for h in "1. Division target" "2. Area-wise target" "3. Sales employee target" "4. Sales coordinators" "5. Admin Head · Sales Manager"; do contains "box: $h" "$TMP/body" "$h"; done
for d in PPE MAAP TRAINING; do contains "division $d" "$TMP/body" ">$d<"; done
for a in Madurai Trichy TTN TVL; do contains "area box $a" "$TMP/body" "<h3>$a</h3>"; done
contains "division annual PPE 1 crore" "$TMP/body" "₹1,00,00,000"
contains "month = annual / 12 (1 crore / 12)" "$TMP/body" "₹8,33,333"
contains "all divisions total" "$TMP/body" "₹1,84,00,000"
contains "Apr - Mar shown" "$TMP/body" "Apr 2026 – Mar 2027"
contains "coordinator with reps" "$TMP/body" "Divya R"
contains "admin head listed" "$TMP/body" "Admin Head"

echo "== Set annual targets"
r=$(req A GET /targets/edit?fy=2); expect "edit sheet" "${r%% *}" 200
contains "division box" "$TMP/body" 'name="t\[d1\]"'
contains "area x division box" "$TMP/body" 'name="t\[a2d1\]"'
contains "employee x division box" "$TMP/body" 'name="t\[e2d1\]"'
r=$(post A /targets /targets/edit?fy=2 --data "fy=2" --data-urlencode "t[d1]=1,20,00,000" --data "t[d2]=6000000&t[d3]=2400000&t[a1d1]=3000000&t[e2d1]=")
case "$r" in "303 "*"/targets?fy=2"*) ok "saved";; *) bad "saved" "$r";; esac
req A GET /targets?fy=2 >/dev/null
contains "new PPE annual" "$TMP/body" "₹1,20,00,000"
contains "new PPE month (÷12)" "$TMP/body" "₹10,00,000"
post A /targets /targets/edit?fy=2 --data "fy=2&t[d1]=lots" >/dev/null; req A GET /targets/edit?fy=2 >/dev/null
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
r=$(req A GET "/branches"); contains "branch select" "$TMP/body" 'name="team_branch"'
contains "columns" "$TMP/body" "OP outstanding"
r=$(req A GET "/branches?team_branch=3"); contains "Madurai team: Muthuvel" "$TMP/body" "MUTHUVEL"
lacks "Madurai team: no Jana" "$TMP/body" "<b>JANA</b>"
contains "division shown" "$TMP/body" "PPE, MAAP"
contains "80% of 65,000 target" "$TMP/body" "₹52,000"

echo
echo "targets e2e: $PASS passed, $FAIL failed"
rm -rf "$TMP"
[ "$FAIL" -eq 0 ]
