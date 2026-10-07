#!/usr/bin/env bash
# End-to-end test: SMS opened from a record (Screen 4) - enquiry offer, purchase order, payment due.
#
#   bash tests/e2e/sms_records.sh [BASE_URL]        (FRESHLY seeded local DB, SMS test mode)
set -u
BASE="${1:-http://localhost/marketing_crm}"
TMP="$(mktemp -d)"
PASS=0; FAIL=0
TODAY=$(date +%F)

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
# Read the compose form's fields and send it as-is (optionally with extra fields)
send_form() { local jar="$1" page="$2"; shift 2
  req "$jar" GET "$page" >/dev/null
  local t msg tid kind ref code
  t=$(grep -o 'name="_csrf" value="[^"]*"' "$TMP/body" | head -1 | sed 's/.*value="//;s/"$//')
  tid=$(grep -o 'name="template_id" value="[^"]*"' "$TMP/body" | sed 's/.*value="//;s/"$//')
  kind=$(grep -o 'name="for" value="[^"]*"' "$TMP/body" | sed 's/.*value="//;s/"$//')
  ref=$(grep -o 'name="ref_id" value="[^"]*"' "$TMP/body" | sed 's/.*value="//;s/"$//')
  code=$(grep -o 'name="customer_code" value="[^"]*"' "$TMP/body" | sed 's/.*value="//;s/"$//')
  msg=$(grep -o 'data-sms-counter>[^<]*' "$TMP/body" | sed 's/^data-sms-counter>//' | sed "s/&#039;/'/g;s/&amp;/\&/g")
  req "$jar" POST /sms/send --data-urlencode "_csrf=$t" --data "template_id=$tid&for=$kind&ref_id=$ref" --data-urlencode "customer_code=$code" --data-urlencode "message=$msg" "$@"
}

first_login A admin 'Admin@2026' 'Teal-Harbour-7391'
first_login J jana 'Sales@2026' 'Monsoon-Field-2087'

echo "== Templates added for selection"
req A GET /sms/templates >/dev/null
for t in "Enquiry Offer" "Purchase Order Received" "Payment Due" "Payment Reminder"; do contains "template: $t" "$TMP/body" "$t"; done

echo "== Enquiry SMS"
r=$(post A /requests/enquiry "/requests?type=enquiry" --data "informed_by=manager&enquiry_source=mail&lead_type=new_product&customer_id=1&lines[0][product_id]=2&lines[0][qty]=10&lines[0][price]=1250")
ENQ=${r##*/}
r=$(req A GET "/sms/send?for=enquiry&id=$ENQ"); expect "compose from enquiry" "${r%% *}" 200
contains "context banner" "$TMP/body" "For <strong>Enquiry ENQ-00001"
contains "enquiry template chosen" "$TMP/body" "thank you for your enquiry {enquiry_no}"
contains "customer filled" "$TMP/body" 'value="CUS-00001"'
r=$(send_form A "/sms/send?for=enquiry&id=$ENQ" --data "preview=1"); req A GET /sms/send >/dev/null
contains "preview fills enquiry no" "$TMP/body" "your enquiry ENQ-00001"
contains "preview fills products" "$TMP/body" "Our offer for Stretch Film 23 Micron: Rs 12,500.00"
r=$(send_form A "/sms/send?for=enquiry&id=$ENQ")
case "$r" in "303 "*"/sms/messages/"*) ok "enquiry SMS sent (test)";; *) bad "enquiry SMS sent (test)" "$r";; esac
req A GET "${r#*marketing_crm}" >/dev/null; contains "message text stored" "$TMP/body" "ENQ-00001"

echo "== Purchase order SMS"
r=$(post A /requests/order "/requests?type=order" --data "informed_by=rep&customer_id=2&reference_type=po&order_date=$TODAY&reference_detail=PO/AE/117&lines[0][product_id]=1&lines[0][qty]=100&lines[0][price]=55")
ORD=${r##*/}
req A GET "/requests/view/order/$ORD" >/dev/null; contains "order page SMS button" "$TMP/body" "for=order&amp;id=$ORD"
r=$(send_form A "/sms/send?for=order&id=$ORD" --data "preview=1"); req A GET /sms/send >/dev/null
contains "PO ref and order no filled" "$TMP/body" "purchase order PO/AE/117. Our order ref ORD-00001, value Rs 5,500.00"

echo "== Payment due SMS"
r=$(req A GET "/sms/send?for=payment&id=1"); expect "compose from payment" "${r%% *}" 200
contains "payment template chosen" "$TMP/body" "is due against {bill_count} bill(s)"
r=$(send_form A "/sms/send?for=payment&id=1" --data "preview=1"); req A GET /sms/send >/dev/null
contains "amount / bills / due date filled" "$TMP/body" "Rs 3,44,442.00 is due against 12 bill(s), oldest due on 11-02-2026"
r=$(req A GET "/sms/send?for=payment&id=1&template=1"); contains "other template keeps the record" "$TMP/body" 'name="for" value="payment"'

echo "== Scope"
r=$(req J GET "/sms/send?for=payment&id=3"); lacks "rep: other rep's customer not loaded" "$TMP/body" "Payment due - Kaveri"
r=$(req A GET "/sms/send?for=bogus&id=1"); expect "unknown record type ignored" "${r%% *}" 200
lacks "no banner for unknown type" "$TMP/body" "alert-info\">For"

echo
echo "sms_records e2e: $PASS passed, $FAIL failed"
rm -rf "$TMP"
[ "$FAIL" -eq 0 ]
