#!/usr/bin/env bash
# End-to-end test: Leads pipeline, follow-ups, status rules, conversion, scope.
#
#   bash tests/e2e/leads.sh [BASE_URL]        (FRESHLY seeded local DB)
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
id_from() { echo "$1" | grep -o 'leads/[0-9]*' | head -1 | grep -o '[0-9]*'; }

first_login A admin 'Admin@2026' 'Teal-Harbour-7391'
first_login C coordinator 'Coord@2026' 'Saffron-Kite-4415'
first_login J jana 'Sales@2026' 'Monsoon-Field-2087'

echo "== List and pipeline"
r=$(req A GET /leads); expect "leads list" "${r%% *}" 200
contains "12 seeded leads" "$TMP/body" "12 leads"
contains "pipeline strip" "$TMP/body" "pipeline-negotiation"
r=$(req A GET "/leads?status=won"); contains "status filter via pipeline" "$TMP/body" "1 lead · Won"

echo "== Create and validate"
r=$(post A /leads /leads/new --data "name=Ravi Kumar&company_name=Ravi Plastics&mobile=9300000001&source_id=2&product_id=1&branch_id=1&employee_id=2&priority=high&expected_value=250000")
expect "lead created" "${r%% *}" 303
NEW=$(id_from "$r")
req A GET "/leads/$NEW" >/dev/null
contains "auto number LD-00013" "$TMP/body" "LD-00013"
contains "expected value exact" "$TMP/body" "₹2,50,000"
post A /leads /leads/new --data "name=No Contact&branch_id=1" >/dev/null; req A GET /leads/new >/dev/null
contains "mobile or email required" "$TMP/body" "mobile number or an email"
post A /leads /leads/new --data "name=Bad&mobile=9300000002&branch_id=1&expected_value=lots" >/dev/null; req A GET /leads/new >/dev/null
contains "bad expected value rejected" "$TMP/body" "Expected value must be"

echo "== Follow-ups move the pipeline"
r=$(post A "/leads/$NEW/followups" "/leads/$NEW" --data "followup_at=2026-12-01T10:30&followup_type=call&notes=Discuss trial order")
expect "follow-up scheduled" "$r" "303 $BASE/leads/$NEW"
req A GET "/leads/$NEW" >/dev/null
contains "new lead moved to Follow-up" "$TMP/body" ">Follow-up</span>"
contains "next follow-up set" "$TMP/body" "01-12-2026 10:30"
FID=$(tr -d '\n' < "$TMP/body" | grep -o "leads/$NEW/followups/[0-9]*/complete" | head -1 | grep -o 'followups/[0-9]*' | grep -o '[0-9]*')
r=$(post A "/leads/$NEW/followups/$FID/complete" "/leads/$NEW" --data "result=completed&outcome=Asked for quotation")
req A GET "/leads/$NEW" >/dev/null
contains "follow-up completed with outcome" "$TMP/body" "Asked for quotation"
contains "history records it" "$TMP/body" "Follow-up completed"

echo "== Status rules"
post A "/leads/$NEW/status" "/leads/$NEW" --data "status=lost&lost_reason=" >/dev/null; req A GET "/leads/$NEW" >/dev/null
contains "lost needs a reason" "$TMP/body" "Give a reason"
post A "/leads/$NEW/status" "/leads/$NEW" --data "status=lost&lost_reason=Chose competitor" >/dev/null; req A GET "/leads/$NEW" >/dev/null
contains "marked lost with reason" "$TMP/body" "Chose competitor"
post A "/leads/$NEW/followups" "/leads/$NEW" --data "followup_at=2026-12-05T10:00&followup_type=call" >/dev/null; req A GET "/leads/$NEW" >/dev/null
contains "closed lead refuses new follow-ups" "$TMP/body" "reopen it"

echo "== Conversion to customer"
r=$(post A /leads/6/convert /leads/6)
CUST=$(echo "$r" | grep -o 'customers/[0-9]*' | grep -o '[0-9]*')
[ -n "$CUST" ] && ok "converted, redirected to new customer" || bad "converted, redirected to new customer" "$r"
req A GET "/customers/$CUST" >/dev/null
contains "customer created with next code" "$TMP/body" "CUS-00013"
req A GET /leads/6 >/dev/null
contains "lead shows converted link" "$TMP/body" "Converted to customer"
contains "lead is Won" "$TMP/body" ">Won</span>"
r=$(post A /leads/6/delete /leads/6); req A GET /leads/6 >/dev/null
contains "converted lead cannot be deleted" "$TMP/body" "cannot be deleted"
r=$(post A /leads /leads/new --data "name=Dup Mobile Lead&mobile=9100000001&branch_id=1&employee_id=2")
DUP=$(id_from "$r")
post A "/leads/$DUP/convert" "/leads/$DUP" >/dev/null; req A GET "/leads/$DUP" >/dev/null
contains "convert refuses duplicate customer mobile" "$TMP/body" "already exists"

echo "== Scope and permissions"
r=$(req J GET /leads); expect "JANA leads list" "${r%% *}" 200
contains "JANA sees her lead" "$TMP/body" "Arun Prasad"
lacks "JANA cannot see MUKESH's lead" "$TMP/body" "Faizal M"
r=$(req J GET /leads/3); expect "JANA cannot open MUKESH's lead (404)" "${r%% *}" 404
r=$(post J /leads /leads --data "name=Jana Own Lead&mobile=9300000009&branch_id=1&employee_id=2"); expect "JANA (view only) cannot add a lead (403)" "${r%% *}" 403
r=$(post A /leads /leads/new --data "name=Jana Own Lead&mobile=9300000009&branch_id=1&employee_id=2")
JL=$(id_from "$r"); req J GET "/leads/$JL" >/dev/null
contains "JANA sees the lead given to her" "$TMP/body" "Jana Own Lead"
r=$(req C GET /leads); expect "coordinator: no Leads menu (403)" "${r%% *}" 403
t=$(csrf C /leads)
r=$(req C POST "/leads/$JL/delete" --data-urlencode "_csrf=$t"); expect "coordinator cannot delete (403)" "${r%% *}" 403
r=$(req A GET /leads/export); expect "export CSV" "${r%% *}" 200
contains "CSV header" "$TMP/body" '"Lead No",Name,Company'

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
