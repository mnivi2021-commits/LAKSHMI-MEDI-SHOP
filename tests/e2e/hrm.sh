#!/usr/bin/env bash
# End-to-end test: HRM employees, departments, designations, resignation, permissions.
#
#   bash tests/e2e/hrm.sh [BASE_URL]        (FRESHLY seeded local DB)
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
emp_id() { tr -d '\n' < "$TMP/body" | grep -o "$1.\{0,1500\}" | grep -o 'hrm/[0-9]*/edit' | head -1 | grep -o '[0-9]*'; }

first_login A admin 'Admin@2026' 'Teal-Harbour-7391'
first_login C coordinator 'Coord@2026' 'Saffron-Kite-4415'
first_login J jana 'Sales@2026' 'Monsoon-Field-2087'

echo "== List"
r=$(req A GET /hrm); expect "employee list" "${r%% *}" 200
contains "10 seeded employees" "$TMP/body" "10 employees"
contains "login column shows username" "$TMP/body" "coordinator"
r=$(req A GET "/hrm?reps=1"); contains "sales-rep filter" "$TMP/body" "5 employees"
r=$(req A GET "/hrm?branch=2"); contains "branch filter" "$TMP/body" "3 employees"

echo "== Create and validate"
post A /hrm /hrm/new --data "name=Rep Without Short&branch_id=1&is_sales_rep=1&status=active" >/dev/null; req A GET "/hrm?q=Rep+Without" >/dev/null
contains "sales rep gets a short name from the first name" "$TMP/body" "REP"
post A /hrm /hrm/new --data "name=Dup Short&short_name=jana&branch_id=1&status=active" >/dev/null; req A GET /hrm/new >/dev/null
contains "duplicate active short name refused" "$TMP/body" "already uses this short name"
post A /hrm /hrm/new --data "name=Bad Dates&branch_id=1&joining_date=2026-05-01&relieving_date=2026-04-01&status=active" >/dev/null; req A GET /hrm/new >/dev/null
contains "relieving before joining refused" "$TMP/body" "cannot be before the joining date"
post A /hrm /hrm/new --data "name=No Date Resign&branch_id=1&status=resigned" >/dev/null; req A GET /hrm/new >/dev/null
contains "resigned needs relieving date" "$TMP/body" "Enter the relieving date"
r=$(post A /hrm /hrm/new --data "name=Deepa Raman&short_name=deepa&mobile=9000000010&branch_id=3&department_id=1&designation_id=3&reporting_manager_id=9&joining_date=2026-09-01&is_sales_rep=1&status=active")
expect "employee created" "$r" "303 $BASE/hrm"
req A GET "/hrm?q=Deepa" >/dev/null
contains "auto code EMP012" "$TMP/body" "EMP012"
contains "short name uppercased" "$TMP/body" "DEEPA"
contains "reports to Karthik" "$TMP/body" "Karthik V"
NEW=$(emp_id "Deepa Raman")
[ -n "$NEW" ] && ok "new employee id found" || bad "new employee id found" "none"

echo "== Reporting loop guard"
post A /hrm/1 /hrm/1/edit --data "name=Rajesh Kumar&short_name=RAJESH&branch_id=1&department_id=1&designation_id=2&reporting_manager_id=2&status=active" >/dev/null
req A GET /hrm/1/edit >/dev/null
contains "loop refused (Rajesh cannot report to JANA)" "$TMP/body" "would create a loop"
post A /hrm/1 /hrm/1/edit --data "name=Rajesh Kumar&short_name=RAJESH&branch_id=1&reporting_manager_id=1&status=active" >/dev/null
req A GET /hrm/1/edit >/dev/null
contains "self manager refused" "$TMP/body" "cannot report to themselves"

echo "== Departments and designations"
r=$(req A GET /hrm/lists/departments); expect "departments page" "${r%% *}" 200
contains "Sales department count link" "$TMP/body" "hrm?department=1"
r=$(post A /hrm/lists/departments /hrm/lists/departments --data "name=Marketing"); expect "department added" "$r" "303 $BASE/hrm/lists/departments"
req A GET /hrm/lists/departments >/dev/null; contains "Marketing listed" "$TMP/body" 'value="Marketing"'
post A /hrm/lists/departments /hrm/lists/departments --data "name=Accounts" >/dev/null; req A GET /hrm/lists/departments >/dev/null
contains "duplicate department refused" "$TMP/body" "already exists"
DID=$(tr -d '\n' < "$TMP/body" | grep -o 'hrm/lists/departments/[0-9]*"[^<]*<input[^>]*name="_csrf"[^>]*>[^<]*<input type="text" name="name" value="Marketing"' | grep -o 'departments/[0-9]*' | grep -o '[0-9]*')
[ -n "$DID" ] && ok "department id found" || bad "department id found" "none"
post A "/hrm/lists/departments/$DID" /hrm/lists/departments --data "name=Digital Marketing" >/dev/null; req A GET /hrm/lists/departments >/dev/null
contains "department renamed" "$TMP/body" 'value="Digital Marketing"'
post A "/hrm/lists/departments/$DID" /hrm/lists/departments --data "action=toggle" >/dev/null
req A GET /hrm/new >/dev/null; req A GET /hrm/new >/dev/null; lacks "inactive department not offered on form" "$TMP/body" "Digital Marketing"
r=$(req A GET /hrm/lists/designations); expect "designations page" "${r%% *}" 200
contains "designations listed" "$TMP/body" 'value="Sales Executive"'
r=$(req A GET /hrm/lists/bogus); expect "unknown list 404" "${r%% *}" 404

echo "== Permissions"
r=$(req J GET /hrm); expect "sales exec has no HRM (403)" "${r%% *}" 403
r=$(req C GET /hrm); expect "coordinator: no HRM menu (403)" "${r%% *}" 403
lacks "coordinator sees no Add button" "$TMP/body" "Add employee"
r=$(req C GET /hrm/new); expect "coordinator cannot add (403)" "${r%% *}" 403
t=$(csrf C /hrm)
r=$(req C POST /hrm/lists/departments --data-urlencode "_csrf=$t" --data "name=Hack"); expect "coordinator cannot add department (403)" "${r%% *}" 403

echo "== Resignation disables login"
r=$(post A /hrm/2 /hrm/2/edit --data "name=Janakiraman S&short_name=JANA&mobile=9000000002&email=jana@example.com&branch_id=1&department_id=1&designation_id=3&reporting_manager_id=1&joining_date=2021-04-12&relieving_date=2026-10-05&is_sales_rep=1&status=resigned")
expect "JANA marked resigned" "$r" "303 $BASE/hrm"
req A GET "/hrm?q=Janakiraman" >/dev/null
contains "flash says login disabled" "$TMP/body" "login has been disabled"
contains "login column shows disabled" "$TMP/body" "(disabled)"
r=$(req J GET /leads); expect "JANA's open session ends" "${r%% *}" 303
post J2 /login /login --data-urlencode "identifier=jana" --data-urlencode "password=Monsoon-Field-2087" >/dev/null; req J2 GET /login >/dev/null
lacks "JANA cannot log in again" "$TMP/body" "Sign out"

echo "== Delete guard"
r=$(post A /hrm/3/delete /hrm); req A GET /hrm >/dev/null
contains "employee with sales cannot be deleted" "$TMP/body" "cannot be deleted"
r=$(post A "/hrm/$NEW/delete" /hrm); expect "unused employee deleted" "$r" "303 $BASE/hrm"
req A GET /hrm >/dev/null; req A GET /hrm >/dev/null
lacks "deleted employee gone" "$TMP/body" "hrm/$NEW/edit"

echo "== Export"
r=$(req A GET /hrm/export); expect "export CSV" "${r%% *}" 200
contains "CSV header" "$TMP/body" 'Code,Name,"Short name"'

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
