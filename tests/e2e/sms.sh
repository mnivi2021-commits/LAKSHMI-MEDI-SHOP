#!/usr/bin/env bash
# End-to-end test: SMS history, single send with preview, campaigns, templates, permissions (test gateway).
#
#   bash tests/e2e/sms.sh [BASE_URL]        (FRESHLY seeded local DB)
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

echo "== History page"
r=$(req A GET /sms); expect "sms page" "${r%% *}" 200
contains "menu item live" "$TMP/body" 'href="/marketing_crm/sms"'
contains "test mode banner" "$TMP/body" "TEST MODE"
contains "no messages yet" "$TMP/body" "No messages yet"

echo "== Single send"
r=$(req A GET "/sms/send?template=1"); contains "template loaded" "$TMP/body" "an amount of Rs {amount}"
r=$(post A /sms/send /sms/send --data "customer_code=CUS-00001&template_id=1&preview=1" --data-urlencode "message=Dear {customer_name}, an amount of Rs {amount} against invoice {invoice_no} is overdue. Kindly arrange payment. - {company}")
req A GET /sms/send >/dev/null
contains "preview rendered with customer name" "$TMP/body" "Dear Sri Balaji Traders"
contains "preview shows overdue invoice" "$TMP/body" "against invoice INV"
contains "preview shows SMS parts" "$TMP/body" "SMS part(s)"
r=$(post A /sms/send /sms/send --data "customer_code=CUS-00001&template_id=1" --data-urlencode "message=Dear {customer_name}, your balance is Rs {amount}. - {company}")
case "$r" in "303 $BASE/sms/messages/"*) ok "sent -> message page";; *) bad "sent -> message page" "$r";; esac
req A GET "${r#* $BASE}" >/dev/null
contains "test message notice" "$TMP/body" "not sent"
contains "delivered (test)" "$TMP/body" "Delivered"
contains "gateway log masked" "$TMP/body" '91xxxxx001'
r=$(post A /sms/send /sms/send --data "mobile=9876500000" --data-urlencode "message=Hello there"); req A GET "${r#* $BASE}" >/dev/null
contains "failure recorded" "$TMP/body" "Number not reachable"
post A /sms/send /sms/send --data "mobile=9876543210" --data-urlencode "message=Price {price}" >/dev/null; req A GET /sms/send >/dev/null
contains "unknown placeholder refused" "$TMP/body" "Unknown placeholder"
post A /sms/send /sms/send --data "mobile=12345" --data-urlencode "message=Hi" >/dev/null; req A GET /sms/send >/dev/null
contains "bad mobile refused" "$TMP/body" "valid 10-digit"
post A /sms/send /sms/send --data "mobile=9876543210" --data-urlencode "message=Dear {name}" >/dev/null; req A GET /sms/send >/dev/null
contains "empty placeholder refused" "$TMP/body" "No value for {name}"
r=$(req A GET /sms); contains "history lists 2 messages" "$TMP/body" ">2 messages<"

echo "== Campaign"
r=$(post A /sms/campaigns /sms/campaigns/new --data "name=Coimbatore follow-up&template_id=3&target_type=customers&branch_id=2&overdue_only=0")
case "$r" in "303 $BASE/sms/campaigns/"*) ok "campaign draft created";; *) bad "campaign draft created" "$r";; esac
CP="${r#* $BASE}"; req A GET "$CP" >/dev/null
contains "4 Coimbatore recipients" "$TMP/body" "4 recipient(s)"
contains "sample message rendered" "$TMP/body" "Dear Kongu Textiles"
r=$(post A "$CP/start" "$CP"); req A GET "$CP" >/dev/null
contains "sent 4" "$TMP/body" "Sent 4, failed 0"
contains "campaign completed" "$TMP/body" "Completed"
r=$(post A "$CP/start" "$CP"); req A GET "$CP" >/dev/null
contains "cannot start twice" "$TMP/body" "already been started"
r=$(post A /sms/campaigns /sms/campaigns/new --data "name=Reminders&template_id=1&target_type=customers&overdue_only=1"); CP2="${r#* $BASE}"; req A GET "$CP2" >/dev/null
contains "overdue reminder renders amount" "$TMP/body" "an amount of Rs [0-9,]*.[0-9][0-9]"
post A /sms/campaigns /sms/campaigns/new --data "name=Late promo&target_type=leads&scheduled_at=2027-01-01T23:00" --data-urlencode "message=Big sale" >/dev/null; req A GET /sms/campaigns/new >/dev/null
contains "promotional outside 9-21 refused" "$TMP/body" "between 09:00 and 21:00"
r=$(post A /sms/campaigns /sms/campaigns/new --data "name=Future promo&target_type=leads&scheduled_at=2027-01-01T11:00" --data-urlencode "message=Hello {name}, new stock arrived. - {company}"); CP3="${r#* $BASE}"
post A "$CP3/start" "$CP3" >/dev/null; req A GET "$CP3" >/dev/null
contains "scheduled campaign waits" "$TMP/body" "Scheduled"
post A "$CP3/cancel" "$CP3" >/dev/null; req A GET "$CP3" >/dev/null
contains "scheduled campaign cancelled" "$TMP/body" "Cancelled"

echo "== Templates"
r=$(req A GET /sms/templates); expect "templates page" "${r%% *}" 200
post A /sms/templates /sms/templates --data "name=Bad&category=service" --data-urlencode "body=Hi {nme}" >/dev/null; req A GET /sms/templates >/dev/null
contains "template placeholder check" "$TMP/body" "Unknown placeholder(s): {nme}"
post A /sms/templates /sms/templates --data "name=Dispatch note&category=transactional&sender_id=lksmed" --data-urlencode "body=Dear {customer_name}, order {order_no} dispatched. - {company}" >/dev/null; req A GET /sms/templates >/dev/null
contains "template saved" "$TMP/body" "Dispatch note"

echo "== Permissions"
r=$(req J GET /sms); expect "sales exec has no SMS (403)" "${r%% *}" 403
r=$(req C GET /sms/send); expect "coordinator can send" "${r%% *}" 200
r=$(req C GET /sms/campaigns); expect "coordinator cannot run campaigns (403)" "${r%% *}" 403
r=$(req C GET /sms/templates); expect "coordinator cannot edit templates (403)" "${r%% *}" 403
r=$(req C GET /sms); contains "coordinator (company-wide scope) sees the history" "$TMP/body" "Sri Balaji Traders"

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
