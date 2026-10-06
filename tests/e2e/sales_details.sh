#!/usr/bin/env bash
# End-to-end test: Sales Details grid, drill-down links, export, API, scope.
#
#   bash tests/e2e/sales_details.sh [BASE_URL]        (FRESHLY seeded local DB)
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

echo "== Grid by employee"
r=$(req A GET /sales); expect "sales details page" "${r%% *}" 200
contains "menu item is live" "$TMP/body" 'href="/marketing_crm/sales"'
contains "employee rows" "$TMP/body" "JANA - Janakiraman S"
contains "target column" "$TMP/body" ">Target<"
contains "150 days column" "$TMP/body" "150+ days"
contains "totals row" "$TMP/body" "total-row"
contains "sales cell drills to dashboard" "$TMP/body" "dashboard/sales?fy=2&amp;employee=2&amp;w=fy"
contains "90-day cell drills to outstanding" "$TMP/body" "dashboard/outstanding?fy=2&amp;employee=[0-9]*&amp;cat=d90"
contains "samples cell drills to documents" "$TMP/body" "sales/documents/samples?fy=2&amp;employee="

echo "== Grid by branch and month"
r=$(req A GET "/sales?by=branch"); expect "by branch" "${r%% *}" 200
contains "branch rows" "$TMP/body" "Coimbatore Branch (CBE)"
contains "branch drill link" "$TMP/body" "dashboard/pending?fy=2&amp;branch="
r=$(req A GET "/sales?by=month"); expect "by month" "${r%% *}" 200
contains "April row" "$TMP/body" "Apr 2026"
contains "March row (target only)" "$TMP/body" "Mar 2027"
lacks "no outstanding column by month" "$TMP/body" "150+ days"
contains "month sales drills to month-to-date" "$TMP/body" "dashboard/sales?fy=2&amp;month=2026-04&amp;w=mtd"
r=$(req A GET "/dashboard/sales?fy=2&month=2026-04&w=mtd"); expect "month-to-date drill page works" "${r%% *}" 200
contains "month-to-date tab" "$TMP/body" "Month to date"
contains "month drill matches dashboard" "$TMP/body" "matches dashboard"

echo "== Filters"
r=$(req A GET "/sales?branch=2"); lacks "branch filter removes Chennai reps" "$TMP/body" "employee=2&amp;w=fy"
contains "branch filter keeps Coimbatore reps" "$TMP/body" "PRAKASH - Prakash M"
r=$(req A GET "/sales?month=2026-06"); contains "past month as-on" "$TMP/body" "as on 30-06-2026"
r=$(req A GET "/sales?branch=999"); contains "bad branch notice" "$TMP/body" "do not have access to the selected branch"

echo "== Samples / DC drill"
r=$(req A GET /sales/documents/samples); expect "pending samples list" "${r%% *}" 200
contains "samples heading" "$TMP/body" "Pending samples"
r=$(req A GET /sales/documents/dc); expect "pending DC list" "${r%% *}" 200
contains "DC total row" "$TMP/body" "total-row"

echo "== Export and API"
r=$(req A GET "/sales/export?by=branch"); expect "export CSV" "${r%% *}" 200
contains "CSV header" "$TMP/body" 'Branch,Target,Sales,Collection'
contains "CSV total line" "$TMP/body" "^TOTAL,"
curl -s -o "$TMP/body" -X POST -H 'Content-Type: application/json' -d '{"username":"admin","password":"Teal-Harbour-7391","device_name":"e2e"}' "$BASE/api/auth/login"
TOKEN=$(grep -o '"token":"[^"]*"' "$TMP/body" | sed 's/"token":"//;s/"$//')
s=$(curl -s -o "$TMP/body" -w '%{http_code}' -H "Authorization: Bearer $TOKEN" "$BASE/api/sales/grid?by=employee"); expect "API grid" "$s" 200
contains "API has totals" "$TMP/body" '"totals":{'
contains "API amounts in rupees" "$TMP/body" '"sales":"[0-9]*\.[0-9][0-9]"'
s=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/sales/grid"); expect "API needs token" "$s" 401

echo "== Scope and permissions"
r=$(req J GET /sales); expect "JANA can open sales details" "${r%% *}" 200
contains "JANA sees herself" "$TMP/body" "JANA - Janakiraman S"
lacks "JANA cannot see MUKESH" "$TMP/body" "MUKESH - Mukesh R"
r=$(req J GET /sales/export); expect "JANA cannot export (403)" "${r%% *}" 403
r=$(req C GET /sales); expect "coordinator can view" "${r%% *}" 200
lacks "coordinator has no export button" "$TMP/body" "sales/export"

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
