#!/usr/bin/env bash
# End-to-end test: Product master CRUD, validation, in-use guard, permissions, export.
#
#   bash tests/e2e/products.sh [BASE_URL]        (FRESHLY seeded local DB)
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
pid_of() { req A GET "/products?q=$1" >/dev/null; tr -d '\n' < "$TMP/body" | grep -o 'products/[0-9]*/edit' | head -1 | grep -o '[0-9]*'; }

first_login A admin 'Admin@2026' 'Teal-Harbour-7391'
first_login J jana 'Sales@2026' 'Monsoon-Field-2087'

echo "== List and create"
r=$(req A GET /products); expect "products list" "${r%% *}" 200
contains "8 seeded products" "$TMP/body" "8 products"
contains "12-month sales column" "$TMP/body" "Sales (12 months)"
r=$(post A /products /products/new --data "product_code=p009&name=Kraft Paper Roll&category=Packaging&unit=Roll&hsn_code=4804&rate=2450.50&gst_rate=12")
expect "product created" "$r" "303 $BASE/products"
req A GET /products >/dev/null
contains "code stored uppercase" "$TMP/body" "P009"
contains "rate shown exactly" "$TMP/body" "₹2,450.50"

echo "== Validation"
post A /products /products/new --data "product_code=P009&name=Dup" >/dev/null; req A GET /products/new >/dev/null
contains "duplicate code rejected" "$TMP/body" "already used"
post A /products /products/new --data "product_code=X1&name=Bad&rate=abc&hsn_code=12&gst_rate=7" >/dev/null; req A GET /products/new >/dev/null
contains "bad rate rejected" "$TMP/body" "Rate must be an amount"
contains "bad HSN rejected" "$TMP/body" "4 to 8 digits"
contains "bad GST rate rejected" "$TMP/body" "valid GST rate"

echo "== Edit, status, delete"
NEW=$(pid_of P009)
r=$(post A "/products/$NEW" "/products/$NEW/edit" --data "product_code=P009&name=Kraft Paper Roll 90gsm&unit=Roll&rate=2500&gst_rate=12")
expect "product updated" "$r" "303 $BASE/products"
req A GET /products >/dev/null; contains "updated name" "$TMP/body" "Kraft Paper Roll 90gsm"
post A "/products/$NEW/status" /products >/dev/null; req A GET "/products?status=inactive" >/dev/null
contains "marked inactive" "$TMP/body" "Kraft Paper Roll 90gsm"
USED=$(pid_of P003)
post A "/products/$USED/delete" /products >/dev/null; req A GET /products >/dev/null
contains "in-use product cannot be deleted" "$TMP/body" "cannot be deleted"
r=$(post A "/products/$NEW/delete" /products); expect "unused product deleted" "$r" "303 $BASE/products"
req A GET /products >/dev/null; req A GET /products >/dev/null
lacks "deleted product gone" "$TMP/body" "products/$NEW/edit"
post A /products /products/new --data "product_code=P009&name=Reuse Code" >/dev/null; req A GET /products/new >/dev/null
contains "deleted product's code stays reserved (no 500)" "$TMP/body" "possibly by a deleted product"

echo "== Permissions and export"
r=$(req J GET /products); expect "JANA can view products" "${r%% *}" 200
lacks "JANA has no add button" "$TMP/body" "Add product"
r=$(req J GET /products/new); expect "JANA cannot add (403)" "${r%% *}" 403
r=$(req A GET /products/export); expect "export CSV" "${r%% *}" 200
contains "CSV header" "$TMP/body" "Code,Name,Category"

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
