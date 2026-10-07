#!/usr/bin/env bash
# End-to-end test: Follow up screen (per sales employee lists, payment follow up).
#
#   bash tests/e2e/followup.sh [BASE_URL]        (FRESHLY seeded local DB)
set -u
BASE="${1:-http://localhost/marketing_crm}"
TMP="$(mktemp -d)"
PASS=0; FAIL=0
TOMORROW=$(date -d tomorrow +%F 2>/dev/null || date -v+1d +%F)

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

echo "== Page"
r=$(req A GET /followup); expect "follow up page" "${r%% *}" 200
contains "menu item" "$TMP/body" 'href="/marketing_crm/followup"'
contains "asks for a sales employee" "$TMP/body" "Choose a sales employee"
for l in "Lead gen pending" "Sales pending" "Sample / O.P pending" "Dispatch details" "Payment follow up"; do contains "list: $l" "$TMP/body" "$l"; done
contains "employee drop box" "$TMP/body" "JANA - Janakiraman S"

echo "== Lists for JANA (employee 2)"
r=$(req A GET "/followup?employee=2&list=lead"); expect "lead list" "${r%% *}" 200
contains "lead columns" "$TMP/body" "Next follow-up"
lacks "no won leads" "$TMP/body" ">Won<"
r=$(req A GET "/followup?employee=2&list=sales"); expect "sales pending" "${r%% *}" 200
contains "delivery due column" "$TMP/body" "Delivery due"
r=$(req A GET "/followup?employee=2&list=sample"); expect "sample / OP pending" "${r%% *}" 200
contains "days out column" "$TMP/body" "Days out"
r=$(req A GET "/followup?employee=2&list=dispatch&from=2026-04-01&to=2026-10-07"); expect "dispatch" "${r%% *}" 200
contains "invoices listed" "$TMP/body" "Invoice</span>"
contains "date range shown" "$TMP/body" "01-04-2026 to 07-10-2026"

echo "== Payment follow up"
r=$(req A GET "/followup?employee=2&list=payment"); expect "customer list" "${r%% *}" 200
contains "customer drop box" "$TMP/body" 'name="customer"'
contains "only JANA's customers" "$TMP/body" "Sri Balaji Traders"
lacks "not MUKESH's customers" "$TMP/body" "Kaveri Foods"
r=$(req A GET "/followup?employee=2&list=payment&customer=1"); expect "statement" "${r%% *}" 200
for h in "S.No" "Invoice no" "Bill date" "P.O reference" "Invoice value" "Due balance" "Due date"; do contains "column: $h" "$TMP/body" ">$h<"; done
contains "total row" "$TMP/body" 'total-row'
contains "PO reference shown" "$TMP/body" "PO/001/"
contains "mail option" "$TMP/body" "mailto:"
contains "SMS option" "$TMP/body" "for=payment&amp;id=1"
contains "direct option" "$TMP/body" 'value="visit"'
r=$(req A GET "/followup?employee=2&list=payment&customer=3"); lacks "other rep's customer ignored" "$TMP/body" "Payment due statement"

r=$(post A /followup/payment/1 "/followup?employee=2&list=payment&customer=1" --data "mode=visit")
case "$r" in "303 "*"customer=1"*) ok "missing notes -> back";; *) bad "missing notes -> back" "$r";; esac
req A GET "/followup?employee=2&list=payment&customer=1" >/dev/null; contains "notes error" "$TMP/body" "Write what the customer said"
r=$(post A /followup/payment/1 "/followup?employee=2&list=payment&customer=1" --data "mode=visit&next_date=$TOMORROW" --data-urlencode "notes=Met accounts; will pay 50,000 on Friday")
case "$r" in "303 "*"customer=1"*) ok "follow-up saved";; *) bad "follow-up saved" "$r";; esac
req A GET "/followup?employee=2&list=payment&customer=1" >/dev/null
contains "saved message" "$TMP/body" "Follow-up recorded for Sri Balaji Traders"
contains "history shows it" "$TMP/body" "will pay 50,000 on Friday"
contains "next follow-up pending" "$TMP/body" "Next payment follow up"

echo "== Rep sees only their own"
r=$(req J GET /followup); expect "rep page" "${r%% *}" 200
contains "rep preselected" "$TMP/body" 'selected>JANA'
lacks "rep: no other reps" "$TMP/body" "MUKESH - "
r=$(req J GET "/followup?employee=3&list=sales"); lacks "rep cannot pick another rep" "$TMP/body" "MUKESH"
r=$(post J /followup/payment/3 "/followup" --data "mode=call&notes=x"); expect "rep cannot record for other's customer" "${r%% *}" 404
r=$(req A POST /followup/payment/1 --data "mode=call&notes=x"); expect "CSRF required" "${r%% *}" 403

echo
echo "followup e2e: $PASS passed, $FAIL failed"
rm -rf "$TMP"
[ "$FAIL" -eq 0 ]
