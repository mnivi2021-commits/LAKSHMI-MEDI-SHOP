#!/usr/bin/env bash
# End-to-end test: HRM -> Sales person details (manager / sales executives / support admin / coordinator).
#
#   bash tests/e2e/sales_team.sh [BASE_URL]        (FRESHLY seeded local DB)
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
age() { local y m d; IFS=- read -r y m d <<< "$1"; local a=$(( $(date +%Y) - 10#$y )); [ "$(date +%m%d)" \< "$m$d" ] && a=$((a-1)); echo "$a"; }

first_login A admin 'Admin@2026' 'Teal-Harbour-7391'
first_login J jana 'Sales@2026' 'Monsoon-Field-2087'

echo "== Page"
r=$(req A GET /hrm); contains "link from Employees" "$TMP/body" 'href="/marketing_crm/hrm/sales-team"'
r=$(req A GET /hrm/sales-team); expect "sales person details" "${r%% *}" 200
for h in "Manager" "Sales Executives" "Sales Support Admin" "Sales Coordinator"; do contains "section: $h" "$TMP/body" ">$h <"; done
contains "branch select" "$TMP/body" 'name="branch"'
contains "+ ADD target sheet" "$TMP/body" 'href="/marketing_crm/entry?type=month"'

echo "== Madurai branch"
r=$(req A GET "/hrm/sales-team?branch=3"); expect "Madurai" "${r%% *}" 200
contains "manager Karthik" "$TMP/body" "Karthik V"
contains "exec name links to dashboard" "$TMP/body" 'href="/marketing_crm/?branch=3&amp;employee=6"'
contains "exec age" "$TMP/body" ">$(age 1991-06-09)<"
contains "exec DOB" "$TMP/body" "09-06-1991"
contains "exec area" "$TMP/body" ">Madurai<"
contains "coordinator" "$TMP/body" "Divya R"
contains "coordinator's area sales persons" "$TMP/body" "MUTHUVEL (Madurai)"
contains "coordinator areas" "$TMP/body" "Trichy, Viralimalai, Tuticorin, Tirunelveli"
lacks "no Chennai people" "$TMP/body" "Janakiraman"
contains "target link for branch" "$TMP/body" 'entry?type=month&amp;branch=3'

echo "== Edit employee: date of birth, role, area, coordinator"
r=$(req A GET /hrm/4/edit); contains "form has DOB" "$TMP/body" 'name="date_of_birth"'
contains "form has sales role" "$TMP/body" 'name="sales_role"'
contains "area suggestions" "$TMP/body" '<option value="Coimbatore">'
FORM="name=Prakash M&short_name=PRAKASH&mobile=9000000004&email=prakash@example.com&branch_id=2&department_id=1&designation_id=3&reporting_manager_id=8&joining_date=2020-11-02&is_sales_rep=1&status=active"
r=$(post A /hrm/4 /hrm/4/edit --data "$FORM&date_of_birth=1990-11-05&sales_role=sales_executive&area=Tiruppur&coordinator_id=10")
expect "saved" "$r" "303 $BASE/hrm"
req A GET "/hrm/sales-team?branch=2" >/dev/null
contains "new area shown" "$TMP/body" ">Tiruppur<"
contains "coordinator shown" "$TMP/body" "Divya R"
r=$(post A /hrm/4 /hrm/4/edit --data "$FORM&date_of_birth=2020-01-01&sales_role=sales_executive")
req A GET /hrm/4/edit >/dev/null; contains "child DOB refused" "$TMP/body" "age must be 16 to 80"
r=$(post A /hrm/4 /hrm/4/edit --data "$FORM&sales_role=boss")
req A GET /hrm/4/edit >/dev/null; contains "bad role refused" "$TMP/body" "Choose a valid sales role"
r=$(post A /hrm/4 /hrm/4/edit --data "$FORM&sales_role=sales_executive&coordinator_id=1")
req A GET /hrm/4/edit >/dev/null; contains "non-coordinator refused" "$TMP/body" "Choose a sales coordinator"
r=$(req A GET /hrm/export); contains "export has DOB / age / area" "$TMP/body" '"Date of birth",Age,"Sales role",Area,"Sales coordinator"'

echo "== Access"
r=$(req J GET /hrm/sales-team); expect "rep without hrm.view refused" "${r%% *}" 403

echo
echo "sales_team e2e: $PASS passed, $FAIL failed"
rm -rf "$TMP"
[ "$FAIL" -eq 0 ]
