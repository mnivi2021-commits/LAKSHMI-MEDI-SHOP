#!/usr/bin/env bash
# End-to-end test: mobile app API (token sign-in, dashboard summary, customers, leads, follow-ups, scope).
#
#   bash tests/e2e/mobile_api.sh [BASE_URL]        (FRESHLY seeded local DB)
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
token() { curl -s -X POST -H 'Content-Type: application/json' -d "{\"username\":\"$1\",\"password\":\"$2\",\"device_name\":\"e2e\"}" "$BASE/api/auth/login" | grep -o '"token":"[^"]*"' | cut -d'"' -f4; }
api() { curl -s -o "$TMP/body" -w '%{http_code}' -H "Authorization: Bearer $1" "$BASE$2"; }

first_login A admin 'Admin@2026' 'Teal-Harbour-7391'
first_login J jana 'Sales@2026' 'Monsoon-Field-2087'
TA=$(token admin 'Teal-Harbour-7391'); TJ=$(token jana 'Monsoon-Field-2087')
[ ${#TA} -ge 40 ] && [ ${#TJ} -ge 40 ] && ok "tokens issued" || bad "tokens issued" "${#TA}/${#TJ}"

echo "== Dashboard summary = web dashboard"
s=$(api "$TA" /api/dashboard/summary); expect "summary" "$s" 200
API_SALES=$(grep -o '"sales":{"total":"[0-9.]*"' "$TMP/body" | grep -o '[0-9.]*"$' | tr -d '"')
req A GET "/?view=bills" >/dev/null
WEB=$(tr -d '\n' < "$TMP/body" | grep -o 'Total sales FY [0-9-]*</span>[^₹]*₹[0-9,]*' | grep -o '₹[0-9,]*' | tr -d '₹,')
[ -n "$API_SALES" ] && [ "${API_SALES%.*}" = "$WEB" ] && ok "app sales $API_SALES = web ₹$WEB" || bad "app sales = web" "$API_SALES vs $WEB"
api "$TA" /api/dashboard/summary >/dev/null
contains "outstanding block" "$TMP/body" '"outstanding":{"upto90"'
contains "email block" "$TMP/body" '"email":\[{"code":"new_enquiry"'
contains "amounts are decimal strings" "$TMP/body" '"total":"[0-9]*\.[0-9][0-9]"'

echo "== Customers and scope"
s=$(api "$TA" "/api/customers?q=kongu"); expect "customer search" "$s" 200
contains "admin finds Kongu" "$TMP/body" "Kongu Textiles"
s=$(api "$TJ" "/api/customers?q="); lacks "JANA does not get Kongu" "$TMP/body" "Kongu Textiles"
contains "JANA gets her customer" "$TMP/body" "Sri Balaji Traders"
s=$(api "$TJ" /api/customers/6); expect "JANA cannot open Kongu (404)" "$s" 404
s=$(api "$TJ" /api/customers/1); expect "JANA opens her customer" "$s" 200
contains "open bills listed" "$TMP/body" '"open_bills":\[{"invoice_no"'
BILLS=$(grep -o '"balance":"[0-9.]*"' "$TMP/body" | grep -o '[0-9.]*' | awk '{s+=$1} END {printf "%.2f", s}')
TOTAL=$(grep -o '"outstanding_total":"[0-9.]*"' "$TMP/body" | grep -o '[0-9.]*')
expect "customer total = sum of open bills" "$BILLS" "$TOTAL"
s=$(api "$TA" /api/customers/abc); expect "bad id 404" "$s" 404

echo "== Leads and follow-ups"
s=$(api "$TJ" /api/leads); expect "leads" "$s" 200
lacks "JANA does not see MUKESH's lead" "$TMP/body" "Faizal M"
s=$(api "$TA" "/api/leads?status=won"); contains "won filter" "$TMP/body" '"status":"won"'
s=$(api "$TA" /api/followups/due); expect "follow-ups due" "$s" 200
contains "follow-up has a phone" "$TMP/body" '"mobile":"9'

echo "== Security"
s=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/dashboard/summary"); expect "no token 401" "$s" 401
s=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer nottherealtoken" "$BASE/api/customers"); expect "bad token 401" "$s" 401
curl -s -o /dev/null -X POST -H "Authorization: Bearer $TJ" "$BASE/api/auth/logout"
s=$(api "$TJ" /api/leads); expect "token revoked after sign-out" "$s" 401

echo "== Web build of the app (if built)"
if [ -f "$(dirname "$0")/../../public/app/index.html" ]; then
  s=$(curl -s -o "$TMP/body" -w '%{http_code}' "$BASE/app/customers"); expect "app deep link served" "$s" 200
  contains "app shell" "$TMP/body" "<title>Marketing CRM</title>"
else
  echo "  SKIP  web build not present (npm run build:web in mobile/)"
fi

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
