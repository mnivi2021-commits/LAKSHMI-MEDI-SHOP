#!/usr/bin/env bash
# End-to-end test: Customer master CRUD, validation, number sequence, scope, export.
#
#   bash tests/e2e/customers.sh [BASE_URL]        (FRESHLY seeded local DB)
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

first_login A admin 'Admin@2026' 'Teal-Harbour-7391'
first_login C coordinator 'Coord@2026' 'Saffron-Kite-4415'
first_login J jana 'Sales@2026' 'Monsoon-Field-2087'

echo "== Admin: list and create"
r=$(req A GET /customers); expect "customers list loads" "${r%% *}" 200
contains "shows seeded customers" "$TMP/body" "Sri Balaji Traders"
contains "12 customers shown" "$TMP/body" "12 customers"

r=$(post A /customers /customers/new --data "name=Acme Traders&company_name=Acme Pvt Ltd&mobile=9876543210&email=acme@example.com&city=Chennai&state=Tamil Nadu&pincode=600001&branch_id=1&credit_days=45&credit_limit=100000")
expect "customer created, redirected to profile" "${r%% *}" 303
req A GET /customers >/dev/null
contains "new customer listed" "$TMP/body" "Acme Traders"
contains "auto code shown" "$TMP/body" "CUS-00013"

echo "== Validation"
r=$(post A /customers /customers/new --data "name=&branch_id=")
req A GET /customers/new >/dev/null
contains "required name" "$TMP/body" "required"
contains "required branch" "$TMP/body" "Choose a branch"
r=$(post A /customers /customers/new --data "name=No Contact&branch_id=1")
req A GET /customers/new >/dev/null
contains "mobile or email required" "$TMP/body" "mobile number or an email"
r=$(post A /customers /customers/new --data "name=Bad GST&branch_id=1&mobile=9876543211&gstin=INVALID")
req A GET /customers/new >/dev/null
contains "invalid GSTIN rejected" "$TMP/body" "standard format"
r=$(post A /customers /customers/new --data "name=Dup Mobile&branch_id=1&mobile=9876543210")
req A GET /customers/new >/dev/null
contains "duplicate mobile rejected" "$TMP/body" "already uses this mobile"
r=$(post A /customers /customers/new --data "name=Bad Credit&branch_id=1&mobile=9876543212&credit_limit=abc")
req A GET /customers/new >/dev/null
contains "invalid credit limit rejected" "$TMP/body" "positive amount"

echo "== View, edit, status"
req A GET /customers >/dev/null
CID=$(tr -d '\n' < "$TMP/body" | grep -o 'customers/[0-9]*"><strong>Acme Traders' | grep -o '[0-9]*' | head -1)
echo "  (Acme Traders id = $CID)"
[ -n "$CID" ] && ok "found Acme Traders id" || bad "found Acme Traders id" "empty"
r=$(req A GET "/customers/$CID"); expect "profile page loads" "${r%% *}" 200
contains "profile shows code" "$TMP/body" "CUS-00013"
contains "profile shows drill-down links" "$TMP/body" "dashboard/sales"
r=$(post A "/customers/$CID" "/customers/$CID/edit" --data "name=Acme Traders Ltd&branch_id=1&mobile=9876543210&credit_days=60")
expect "customer updated" "$r" "303 $BASE/customers/$CID"
req A GET "/customers/$CID" >/dev/null
contains "updated name shown" "$TMP/body" "Acme Traders Ltd"
r=$(post A "/customers/$CID/status" /customers --data "status=blocked")
req A GET /customers >/dev/null
contains "status changed to blocked" "$TMP/body" "Blocked"

echo "== Delete guard and deletion"
JANACUST=1   # Sri Balaji Traders - has invoices
r=$(post A "/customers/$JANACUST/delete" /customers); req A GET /customers >/dev/null
contains "cannot delete customer with transactions" "$TMP/body" "cannot be deleted"
r=$(post A "/customers/$CID/delete" /customers)
expect "customer without transactions can be deleted" "$r" "303 $BASE/customers"
req A GET /customers >/dev/null
req A GET /customers >/dev/null
lacks "deleted customer no longer listed" "$TMP/body" "customers/$CID/edit"

echo "== Permissions and scope"
r=$(req C GET /customers); expect "coordinator: no Customers menu (403)" "${r%% *}" 403
r=$(req C GET /customers/new); expect "coordinator can add a customer (input role)" "${r%% *}" 200
r=$(req J GET /customers/new); expect "JANA (view only) cannot add (403)" "${r%% *}" 403
r=$(req J GET /customers); expect "JANA can view her customers" "${r%% *}" 200
contains "JANA sees her own customer" "$TMP/body" "Sri Balaji Traders"
lacks "JANA cannot see MUKESH's customer" "$TMP/body" "Kongu Textiles"
r=$(req J GET "/customers/6"); expect "JANA cannot open another rep's customer (404)" "${r%% *}" 404

echo "== Export and lookup"
r=$(req A GET /customers/export); expect "export CSV" "${r%% *}" 200
contains "CSV header" "$TMP/body" 'Code,Name,Company'
r=$(req A GET "/customers/lookup?q=Sri"); expect "lookup works" "${r%% *}" 200
contains "lookup finds customer" "$TMP/body" "Sri Balaji Traders"

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
