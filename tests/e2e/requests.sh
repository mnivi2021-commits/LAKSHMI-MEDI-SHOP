#!/usr/bin/env bash
# End-to-end test: Requests screen (New lead / Enquiry / Order, DC and Sample requests).
#
#   bash tests/e2e/requests.sh [BASE_URL]        (FRESHLY seeded local DB)
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
redir()    { case "$2" in "303 "*"$3"*) ok "$1";; *) bad "$1" "got '$2'";; esac; }

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

echo "== Pages"
r=$(req A GET /requests); expect "requests page" "${r%% *}" 200
contains "menu item" "$TMP/body" 'href="/marketing_crm/requests"'
for t in "New lead" "Enquiry" "Order" "DC request" "Sample request"; do contains "tab: $t" "$TMP/body" "$t"; done
for t in lead enquiry order dc sample; do r=$(req A GET "/requests?type=$t"); expect "form: $t" "${r%% *}" 200; done
req A GET "/requests?type=lead" >/dev/null; contains "lead asks who informed" "$TMP/body" 'name="informed_by"'
req A GET "/requests?type=dc" >/dev/null; contains "DC asks for approval mail" "$TMP/body" 'name="approval_type"'
req A GET "/requests?type=sample" >/dev/null; contains "sample asks type" "$TMP/body" 'name="sample_type"'

echo "== New lead (new customer, product not in master)"
r=$(post A /requests/lead "/requests?type=lead" --data "informed_by=rep&informed_employee_id=3" \
     --data-urlencode "new_name=Lotus Packaging" --data "new_mobile=9876501234&branch_id=1&employee_id=3" \
     --data-urlencode "lines[0][description]=Custom printed carton" --data "lines[0][qty]=500&lines[0][price]=42.50")
redir "lead saved" "$r" "/requests/view/lead/"
LEAD=${r##*/}
req A GET "/requests/view/lead/$LEAD" >/dev/null
contains "lead number" "$TMP/body" "LD-"
contains "internal only" "$TMP/body" "not sent to the customer"
contains "free-text product" "$TMP/body" "Custom printed carton"
contains "amount 500 x 42.50" "$TMP/body" "21,250.00"
contains "informed by rep" "$TMP/body" "Rep - "
lacks "no SMS for a lead" "$TMP/body" "Send enquiry SMS"
r=$(post A /requests/lead "/requests?type=lead" --data "lines[0][qty]=1")
redir "lead without details refused" "$r" "/requests?type=lead"
req A GET "/requests?type=lead" >/dev/null
contains "errors shown" "$TMP/body" "Nothing was saved"
contains "informed by required" "$TMP/body" "Informed by (Manager / Rep)"

echo "== Enquiry (existing customer -> offer)"
r=$(post A /requests/enquiry "/requests?type=enquiry" --data "informed_by=manager&enquiry_source=mail&lead_type=new_product&customer_id=1" \
     --data-urlencode "contact_person=Mr. Ravi" --data "lines[0][product_id]=2&lines[0][qty]=10" --data-urlencode "lines[0][price]=1,250")
redir "enquiry saved" "$r" "/requests/view/enquiry/"
ENQ=${r##*/}
req A GET "/requests/view/enquiry/$ENQ" >/dev/null
contains "ENQ number" "$TMP/body" "ENQ-00001"
contains "offer title" "$TMP/body" "Offer"
contains "customer from master" "$TMP/body" "Sri Balaji Traders"
contains "attn" "$TMP/body" "Attn: Mr. Ravi"
contains "source" "$TMP/body" "Customer request mail"
contains "offer total" "$TMP/body" "12,500.00"
contains "SMS button" "$TMP/body" "Send enquiry SMS"
r=$(req A GET "/requests/view/lead/$ENQ"); expect "enquiry not shown as lead" "${r%% *}" 404

echo "== Order"
r=$(post A /requests/order "/requests?type=order" --data "informed_by=rep&customer_id=2&reference_type=po&order_date=$TODAY" \
     --data-urlencode "reference_detail=PO/AE/2026/117" --data "lines[0][product_id]=1&lines[0][qty]=100&lines[0][price]=55")
redir "order saved" "$r" "/requests/view/order/"
ORD=${r##*/}
req A GET "/requests/view/order/$ORD" >/dev/null
contains "ORD number" "$TMP/body" "ORD-00001"
contains "PO reference" "$TMP/body" "PO/AE/2026/117"
contains "came by PO" "$TMP/body" "Customer PO reference"
r=$(post A /requests/order "/requests?type=order" --data "informed_by=rep&customer_id=2&reference_type=mail&order_date=$TODAY&lines[0][product_id]=1&lines[0][qty]=1&lines[0][price]=5")
redir "mail order without mail details refused" "$r" "/requests?type=order"
r=$(post A /requests/order "/requests?type=order" --data "informed_by=manager&customer_id=2&reference_type=advance&order_date=$TODAY&lines[0][product_id]=3&lines[0][qty]=2&lines[0][price]=80")
redir "advance order without amount refused" "$r" "/requests?type=order"
r=$(post A /requests/order "/requests?type=order" --data "informed_by=manager&customer_id=2&reference_type=advance&advance_amount=5000&order_date=$TODAY&lines[0][product_id]=3&lines[0][qty]=2&lines[0][price]=80")
redir "advance order saved" "$r" "/requests/view/order/"
req A GET "/requests/view/order/${r##*/}" >/dev/null
contains "advance shown" "$TMP/body" "5,000"

echo "== New customer goes into the customer master"
r=$(post A /requests/order "/requests?type=order" --data "informed_by=rep&reference_type=phone&order_date=$TODAY&branch_id=1&employee_id=2" \
     --data-urlencode "reference_detail=Called by owner, 10 am" --data-urlencode "new_name=Ganga Agencies" --data "new_mobile=9876509999&new_city=Chennai" \
     --data "lines[0][product_id]=1&lines[0][qty]=10&lines[0][price]=60")
redir "order for new customer saved" "$r" "/requests/view/order/"
req A GET "/customers?q=Ganga" >/dev/null
contains "customer added to master" "$TMP/body" "Ganga Agencies"
r=$(post A /requests/order "/requests?type=order" --data "informed_by=rep&reference_type=phone&order_date=$TODAY&branch_id=1" \
     --data-urlencode "reference_detail=x" --data-urlencode "new_name=Ganga Copy" --data "new_mobile=9876509999&lines[0][product_id]=1&lines[0][qty]=1&lines[0][price]=1")
redir "duplicate mobile refused" "$r" "/requests?type=order"
req A GET "/requests?type=order" >/dev/null; contains "duplicate message" "$TMP/body" "already belongs to Ganga Agencies"

echo "== DC request"
r=$(post A /requests/dc "/requests?type=dc" --data "customer_id=2&dc_date=$TODAY&lines[0][product_id]=1&lines[0][qty]=20&lines[0][price]=1100")
redir "DC without approval refused" "$r" "/requests?type=dc"
req A GET "/requests?type=dc" >/dev/null; contains "approval required" "$TMP/body" "Choose: Approval"
r=$(post A /requests/dc "/requests?type=dc" --data "customer_id=2&dc_date=$TODAY&approval_type=md_approval&order_no=ORD-00001" \
     --data-urlencode "approval_reference=MD mail 07-10-2026 Send 20 boxes" --data "lines[0][product_id]=1&lines[0][qty]=20&lines[0][price]=1100")
redir "DC with M.D approval saved" "$r" "/requests/view/dc/"
req A GET "/requests/view/dc/${r##*/}" >/dev/null
contains "DCR number" "$TMP/body" "DCR-00001"
contains "M.D approval" "$TMP/body" "M.D approval mail"
r=$(post A /requests/dc "/requests?type=dc" --data "customer_id=1&dc_date=$TODAY&approval_type=customer_mail&approval_reference=mail&order_no=ORD-00001&lines[0][product_id]=1&lines[0][qty]=1&lines[0][price]=1")
redir "DC linked to another customer's order refused" "$r" "/requests?type=dc"

echo "== Sample request and manager approval"
r=$(req J GET /requests); expect "rep sees requests" "${r%% *}" 200
r=$(post J /requests/sample "/requests?type=sample" --data "customer_id=1&sample_type=returnable&document_date=$TODAY&lines[0][product_id]=2&lines[0][qty]=2&lines[0][price]=900")
redir "rep sample saved" "$r" "/requests/view/sample/"
SMP=${r##*/}
req J GET "/requests/view/sample/$SMP" >/dev/null
contains "waits for approval" "$TMP/body" "Waiting for a manager"
lacks "rep has no approve button" "$TMP/body" 'value="approve"'
r=$(post J "/requests/sample/$SMP/decide" "/requests/view/sample/$SMP" --data "decision=approve"); expect "rep cannot approve" "${r%% *}" 403
r=$(post A "/requests/sample/$SMP/decide" "/requests/view/sample/$SMP" --data "decision=approve&note=OK"); redir "manager approves" "$r" "/requests?type=sample"
req A GET "/requests/view/sample/$SMP" >/dev/null
contains "approved shown" "$TMP/body" "Approved by"
post A "/requests/sample/$SMP/decide" "/requests/view/sample/$SMP" --data "decision=reject" >/dev/null; req A GET "/requests?type=sample" >/dev/null
contains "second decision refused" "$TMP/body" "already approved"
r=$(post A /requests/sample "/requests?type=sample" --data "customer_id=3&sample_type=non_returnable&document_date=$TODAY&lines[0][product_id]=3&lines[0][qty]=1&lines[0][price]=50")
SMP2=${r##*/}
req A GET "/requests/view/sample/$SMP2" >/dev/null
contains "manager's own sample approved" "$TMP/body" "Approved by"

echo "== Scope and permissions"
r=$(post J /requests/order "/requests?type=order" --data "informed_by=rep&customer_id=6&reference_type=phone&reference_detail=x&order_date=$TODAY&lines[0][product_id]=1&lines[0][qty]=1&lines[0][price]=1")
redir "rep cannot use another rep's customer" "$r" "/requests?type=order"
req J GET "/requests?type=order" >/dev/null; contains "customer scope message" "$TMP/body" "Choose one of your customers"
r=$(post J /requests/order "/requests?type=order" --data "informed_by=rep&reference_type=phone&reference_detail=x&order_date=$TODAY&branch_id=1" \
     --data-urlencode "new_name=Rep New Co" --data "new_mobile=9876501111&lines[0][product_id]=1&lines[0][qty]=1&lines[0][price]=1")
redir "rep cannot add to customer master" "$r" "/requests?type=order"
req J GET "/requests?type=order" >/dev/null; contains "ask to update customer master" "$TMP/body" "not in the customer master"
r=$(req J GET "/requests/view/order/$ORD"); expect "rep sees own customer's order" "${r%% *}" 200
r=$(req J GET "/requests/view/sample/$SMP2"); expect "rep cannot see another rep's sample" "${r%% *}" 404
r=$(req C GET /requests); expect "coordinator sees requests" "${r%% *}" 200
contains "coordinator: DC tab" "$TMP/body" "type=dc"
r=$(post C /requests/sample "/requests?type=sample" --data "customer_id=4&sample_type=returnable&document_date=$TODAY&lines[0][product_id]=1&lines[0][qty]=1&lines[0][price]=100")
req C GET "/requests/view/sample/${r##*/}" >/dev/null; contains "coordinator's sample waits for a manager" "$TMP/body" "Waiting for a manager"
r=$(post C "/requests/sample/${r##*/}/decide" "/requests?type=sample" --data "decision=approve"); expect "coordinator cannot approve" "${r%% *}" 403
r=$(post J /requests/sample/bogus "/requests?type=sample" --data "x=1"); expect "unknown request type 404" "${r%% *}" 404
r=$(req A GET /requests/view/bogus/1); expect "unknown type 404" "${r%% *}" 404
r=$(req A POST /requests/lead --data "informed_by=rep"); expect "CSRF required" "${r%% *}" 403

echo
echo "requests e2e: $PASS passed, $FAIL failed"
rm -rf "$TMP"
[ "$FAIL" -eq 0 ]
