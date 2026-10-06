#!/usr/bin/env bash
# End-to-end test: Excel Upload - template, upload, column matching, preview, import, permissions.
#
#   bash tests/e2e/imports.sh [BASE_URL]        (FRESHLY seeded local DB)
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
upload() { local jar="$1" type="$2" file="$3"; local t; t=$(csrf "$jar" "/imports/new/$type"); req "$jar" POST "/imports/new/$type" -F "_csrf=$t" -F "file=@$file"; }

first_login A admin 'Admin@2026' 'Teal-Harbour-7391'
first_login C coordinator 'Coord@2026' 'Saffron-Kite-4415'
first_login J jana 'Sales@2026' 'Monsoon-Field-2087'

echo "== Start page and templates"
r=$(req A GET /imports); expect "imports page" "${r%% *}" 200
contains "menu item" "$TMP/body" 'href="/marketing_crm/imports"'
contains "pending orders card" "$TMP/body" "imports/new/pending_orders"
contains "all 8 types for admin" "$TMP/body" "imports/new/leads"
curl -s -o "$TMP/tpl.xlsx" -b "$TMP/A" "$BASE/imports/template/pending_orders"
[ "$(head -c 2 "$TMP/tpl.xlsx")" = "PK" ] && ok "xlsx template is a zip" || bad "xlsx template is a zip" "$(head -c 20 "$TMP/tpl.xlsx" | od -c | head -1)"
r=$(req A GET "/imports/template/pending_orders?format=csv"); contains "csv template header" "$TMP/body" '"Order No","Order Date","Customer Code"'

echo "== Upload the server's own xlsx template (round trip)"
r=$(upload A pending_orders "$TMP/tpl.xlsx")
case "$r" in *"/imports/"*"/map") ok "xlsx upload -> match columns";; *) bad "xlsx upload -> match columns" "$r";; esac
BID=$(echo "$r" | grep -o 'imports/[0-9]*' | grep -o '[0-9]*')
req A GET "/imports/$BID/map" >/dev/null
contains "auto-matched Order No" "$TMP/body" '<option value="Order No" selected'
contains "sample value shown" "$TMP/body" "SO-1001"
r=$(post A "/imports/$BID/map" "/imports/$BID/map" --data-urlencode "map[order_no]=Order No" --data-urlencode "map[order_date]=Order Date" \
  --data-urlencode "map[customer_code]=Customer Code" --data-urlencode "map[product_code]=Product Code" --data-urlencode "map[order_qty]=Order Qty" \
  --data-urlencode "map[rate]=Rate" --data-urlencode "map[order_value]=Order Value" --data-urlencode "map[supplied_qty]=Supplied Qty")
expect "mapping saved -> preview" "$r" "303 $BASE/imports/$BID"
req A GET "/imports/$BID" >/dev/null
contains "template example rows are ready" "$TMP/body" "Import 1 document"

echo "== CSV with errors"
printf 'Order No,Date,Customer,Item Code,Qty,Rate\nE2E-1,01-09-2026,CUS-00001,P001,10,42\nE2E-1,01-09-2026,CUS-00001,P002,1,1150\nE2E-2,01-09-2026,CUS-99999,P001,1,1\n' > "$TMP/o.csv"
r=$(upload A pending_orders "$TMP/o.csv"); BID2=$(echo "$r" | grep -o 'imports/[0-9]*' | grep -o '[0-9]*')
r=$(post A "/imports/$BID2/map" "/imports/$BID2/map" --data-urlencode "map[order_no]=Order No" --data-urlencode "map[order_date]=Date" \
  --data-urlencode "map[customer_code]=Customer" --data-urlencode "map[product_code]=Item Code" --data-urlencode "map[order_qty]=Qty" --data-urlencode "map[rate]=Rate")
req A GET "/imports/$BID2" >/dev/null
contains "flash summary" "$TMP/body" "2 ready, 1 with errors, 0 duplicates"
contains "error row explained" "$TMP/body" "CUS-99999"
contains "one document from two rows" "$TMP/body" "Import 1 document"
r=$(req A GET "/imports/$BID2/errors"); expect "problem rows CSV" "${r%% *}" 200
contains "problem CSV row" "$TMP/body" '^4,Error'
r=$(post A "/imports/$BID2/run" "/imports/$BID2"); expect "import run" "$r" "303 $BASE/imports/$BID2"
req A GET "/imports/$BID2" >/dev/null
contains "imported message" "$TMP/body" "Imported 1 document(s) from 2 row(s)"
r=$(req A GET "/dashboard/pending"); contains "order visible on pending drill-down" "$TMP/body" "E2E-1"
r=$(post A "/imports/$BID2/run" "/imports/$BID2"); req A GET "/imports/$BID2" >/dev/null
contains "second run refused" "$TMP/body" "not ready"

echo "== Mapping validation and bad files"
r=$(upload A pending_orders "$TMP/o.csv"); BID3=$(echo "$r" | grep -o 'imports/[0-9]*' | grep -o '[0-9]*')
post A "/imports/$BID3/map" "/imports/$BID3/map" --data-urlencode "map[order_no]=Order No" >/dev/null; req A GET "/imports/$BID3/map" >/dev/null
contains "required field must be matched" "$TMP/body" "Order Date"
contains "required mapping error" "$TMP/body" "(required)"
r=$(post A "/imports/$BID3/cancel" "/imports/$BID3"); expect "cancel" "$r" "303 $BASE/imports"
printf 'not,a\x00binary' > "$TMP/bad.csv"
upload A pending_orders "$TMP/bad.csv" >/dev/null; req A GET /imports/new/pending_orders >/dev/null
contains "binary file refused" "$TMP/body" "does not look like a CSV"
printf 'x' > "$TMP/x.pdf"
upload A pending_orders "$TMP/x.pdf" >/dev/null; req A GET /imports/new/pending_orders >/dev/null
contains "wrong extension refused" "$TMP/body" "Upload an Excel"
r=$(upload A pending_orders "$TMP/o.csv"); req A GET "/imports/$(echo "$r" | grep -o 'imports/[0-9]*' | grep -o '[0-9]*')/map" >/dev/null
contains "same-file warning" "$TMP/body" "already imported"

echo "== Permissions"
r=$(req J GET /imports); expect "sales exec has no Excel Upload (403)" "${r%% *}" 403
r=$(req C GET /imports); expect "coordinator can open Excel Upload" "${r%% *}" 200
contains "coordinator sees pending orders" "$TMP/body" "imports/new/pending_orders"
lacks "coordinator cannot import sales" "$TMP/body" "imports/new/sales"
r=$(req C GET /imports/new/sales); expect "coordinator sales upload (403)" "${r%% *}" 403
r=$(req C GET "/imports/$BID2"); expect "coordinator cannot open admin's batch (404)" "${r%% *}" 404
r=$(req A GET /imports/new/bogus); expect "unknown type 404" "${r%% *}" 404

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
