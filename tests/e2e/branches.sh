#!/usr/bin/env bash
# End-to-end test: Branch Details CRUD, validation, dependents guard, scope, export.
#
#   bash tests/e2e/branches.sh [BASE_URL]        (FRESHLY seeded local DB)
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

echo "== Admin Head: list and create"
r=$(req A GET /branches); expect "branches list loads" "${r%% *}" 200
contains "shows seeded branches" "$TMP/body" "Chennai Head Office"
contains "shows employee/customer counts" "$TMP/body" "CHN"

r=$(post A /branches /branches/new --data "branch_code=TST&name=Test Branch&city=Testville&state=TS&pincode=600001&contact_number=9000011111&email=test@example.com")
expect "branch created" "$r" "303 $BASE/branches"
req A GET /branches >/dev/null
contains "new branch listed" "$TMP/body" "Test Branch"

echo "== Validation"
r=$(post A /branches /branches/new --data "branch_code=&name=&pincode=abc&contact_number=123")
req A GET /branches/new >/dev/null
contains "required branch_code" "$TMP/body" "required"
contains "invalid pincode" "$TMP/body" "6 digits"
r=$(post A /branches /branches/new --data "branch_code=TST&name=Dup Code Branch&city=X")
req A GET /branches/new >/dev/null
contains "duplicate code rejected" "$TMP/body" "already used"
r=$(post A /branches /branches/new --data "branch_code=NEWCODE&name=Chennai Head Office")
req A GET /branches/new >/dev/null
contains "duplicate name rejected" "$TMP/body" "already exists"

echo "== Edit and manager assignment"
req A GET /branches >/dev/null
TSTID=$(tr -d '\n' < "$TMP/body" | grep -o 'Test Branch</strong><div class="muted small">TST</div></td>.\{0,1000\}branches/[0-9]*/edit' | grep -o '[0-9]*/edit' | grep -o '[0-9]*')
echo "  (Test Branch id = $TSTID)"
[ -n "$TSTID" ] && ok "found Test Branch id" || bad "found Test Branch id" "empty"
r=$(req A GET "/branches/$TSTID/edit"); expect "edit page loads" "${r%% *}" 200
contains "no employees note shown" "$TMP/body" "No active employees"
r=$(post A "/branches/$TSTID" "/branches/$TSTID/edit" --data "branch_code=TST&name=Test Branch Updated&city=Testville&state=TS&pincode=600001&contact_number=9000011111&email=test@example.com")
expect "branch updated" "$r" "303 $BASE/branches"
req A GET /branches >/dev/null
contains "updated name shown" "$TMP/body" "Test Branch Updated"

echo "== Disable / delete guards (dependents)"
CHNID=1
r=$(post A "/branches/$CHNID/status" /branches); req A GET /branches >/dev/null
contains "cannot disable branch with employees/customers" "$TMP/body" "active employees or customers"
r=$(post A "/branches/$CHNID/delete" /branches); req A GET /branches >/dev/null
contains "cannot delete branch with dependents" "$TMP/body" "cannot be deleted"
r=$(post A "/branches/$TSTID/status" /branches); expect "empty branch can be disabled" "$r" "303 $BASE/branches"
req A GET /branches >/dev/null
r=$(post A "/branches/$TSTID/delete" /branches); expect "empty disabled branch can be deleted" "$r" "303 $BASE/branches"
req A GET /branches >/dev/null   # this load shows the "... deleted." flash message, which names the branch
req A GET /branches >/dev/null   # second load: flash consumed, so only an actual table row would match
lacks "deleted branch no longer listed" "$TMP/body" "branches/$TSTID/edit"

echo "== Permissions and scope"
r=$(req C GET /branches); expect "coordinator can view (branches.view)" "${r%% *}" 200
r=$(req C GET /branches/new); expect "coordinator cannot add (403)" "${r%% *}" 403
t=$(csrf C /branches)
r=$(req C POST /branches --data-urlencode "_csrf=$t" --data "branch_code=X&name=Y"); expect "coordinator POST add refused (403)" "${r%% *}" 403
r=$(req J GET /branches); expect "JANA (own scope) has no branches.view (403)" "${r%% *}" 403

echo "== Export"
r=$(req A GET /branches/export); expect "export CSV" "${r%% *}" 200
contains "CSV header" "$TMP/body" '"Branch Code",Name,Address'
contains "CSV has data" "$TMP/body" "CHN"

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
