#!/usr/bin/env bash
# End-to-end test: Mail inbox, dashboard Email cards, manual entry, correction, assignment, lead, settings, scope.
#
#   bash tests/e2e/mail.sh [BASE_URL]        (FRESHLY seeded local DB)
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

echo "== Dashboard Email cards"
r=$(req A GET "/?view=performance"); expect "dashboard" "${r%% *}" 200
contains "email section" "$TMP/body" 'id="sec-mail"'
contains "NEW ENQUIRY card" "$TMP/body" "NEW ENQUIRY"
contains "PAYMENT ADVICE card" "$TMP/body" "PAYMENT ADVICE"
CARD=$(grep -o 'mail?category=order&amp;from=[0-9-]*&amp;to=[0-9-]*' "$TMP/body" | head -1 | sed 's/&amp;/\&/g')
N=$(tr -d '\n' < "$TMP/body" | grep -o 'aria-label="Order emails in [A-Za-z]*">[^<]*<span[^>]*>[^<]*</span>[^<]*<strong class="mail-card-count">[0-9]*' | grep -o '[0-9]*$')
r=$(req A GET "/$CARD"); expect "Order card opens Mail list" "${r%% *}" 200
contains "list total equals the card ($N)" "$TMP/body" ">$N email"

echo "== Inbox"
r=$(req A GET /mail); expect "inbox" "${r%% *}" 200
contains "menu item live" "$TMP/body" 'href="/marketing_crm/mail"'
contains "12 seeded emails" "$TMP/body" ">12 emails<"
r=$(req A GET "/mail?category=payment_advice"); contains "category filter" "$TMP/body" "Payment advice - UPI0000902002"
lacks "category filter excludes others" "$TMP/body" "Purchase Order PO-KNG-131"
r=$(req A GET "/mail?q=pallets"); contains "search" "$TMP/body" "Requirement for wooden pallets"
r=$(req A GET "/mail?review=1"); expect "needs-review filter" "${r%% *}" 200

echo "== Manual entry is classified and matched"
r=$(post A /mail /mail/new --data "account_id=1&from_email=purchase@annai.example.com&from_name=Annai+Purchase&subject=Purchase+Order+PO-AN-77&body=Please+supply+as+per+attached+PO&received_at=2026-10-06T09:15")
case "$r" in "303 $BASE/mail/"*) ok "email added";; *) bad "email added" "$r";; esac
EID=$(echo "$r" | grep -o 'mail/[0-9]*' | grep -o '[0-9]*')
req A GET "/mail/$EID" >/dev/null
contains "classified as Order" "$TMP/body" "classified as Order"
contains "matched customer Annai" "$TMP/body" "Annai Exports"
post A /mail /mail/new --data "account_id=1&from_email=bad&subject=x&received_at=2026-10-06T09:15" >/dev/null; req A GET /mail/new >/dev/null
contains "bad sender refused" "$TMP/body" "sender"
post A /mail /mail/new --data "account_id=1&from_email=a@b.com&subject=x&received_at=2030-01-01T09:15" >/dev/null; req A GET /mail/new >/dev/null
contains "future time refused" "$TMP/body" "cannot be in the future"

echo "== Correct, assign, status, lead"
r=$(post A "/mail/$EID/category" "/mail/$EID" --data "category_id=1"); req A GET "/mail/$EID" >/dev/null
contains "corrected by hand" "$TMP/body" "corrected by hand (rules said Order)"
contains "history shows correction" "$TMP/body" "Recategorised: order"
JUID=$(tr -d '\n' < "$TMP/body" | grep -o '<option value="[0-9]*"[^>]*>Janakiraman S' | grep -o 'value="[0-9]*"' | grep -o '[0-9]*')
post A "/mail/$EID/assign" "/mail/$EID" --data "user_id=$JUID&note=Call+them" >/dev/null; req A GET "/mail/$EID" >/dev/null
contains "assigned" "$TMP/body" "Assigned to Janakiraman S"
post A "/mail/$EID/status" "/mail/$EID" --data "status=closed&followup_status=done" >/dev/null; req A GET "/mail/$EID" >/dev/null
contains "status history" "$TMP/body" "Status changed"
NEWID=$(post A /mail /mail/new --data "account_id=1&from_email=newbuyer@hosur-plastics.example.com&from_name=Hosur+Plastics&subject=Enquiry+for+stretch+film&received_at=2026-10-06T10:00" | grep -o 'mail/[0-9]*' | grep -o '[0-9]*')
r=$(post A "/mail/$NEWID/lead" "/mail/$NEWID" --data "branch_id=1")
case "$r" in "303 $BASE/leads/"*) ok "lead created -> lead page";; *) bad "lead created -> lead page" "$r";; esac
req A GET "${r#* $BASE}" >/dev/null
contains "lead has the sender" "$TMP/body" "newbuyer@hosur-plastics.example.com"
r=$(post A "/mail/$NEWID/lead" "/mail/$NEWID" --data "branch_id=1"); req A GET "/mail/$NEWID" >/dev/null
contains "second lead refused" "$TMP/body" "already linked"

echo "== Settings"
r=$(req A GET /mail/settings); expect "settings page" "${r%% *}" 200
contains "imap explanation" "$TMP/body" "MAIL_IMAP_HOST"
r=$(post A /mail/categories/2 /mail/settings --data-urlencode $'keywords=purchase order : 4\npo no : 3\norder : 2\nsupply order : 3'); req A GET /mail/settings >/dev/null
contains "keywords saved" "$TMP/body" "supply order : 3"
post A /mail/categories/2 /mail/settings --data-urlencode "keywords=x : 99" >/dev/null; req A GET /mail/settings >/dev/null
contains "bad weight refused" "$TMP/body" "Not understood"
r=$(post A /mail/reclassify /mail/settings); req A GET /mail/settings >/dev/null
contains "reclassify ran" "$TMP/body" "changed category"
post A /mail/accounts /mail/settings --data "email_address=orders@example.com&provider=imap&branch_id=2" >/dev/null; req A GET /mail/settings >/dev/null
contains "imap mailbox added" "$TMP/body" "orders@example.com"
AID=$(tr -d '\n' < "$TMP/body" | grep -o 'mail/accounts/[0-9]*/sync' | head -1 | grep -o '[0-9]*')
post A "/mail/accounts/$AID/sync" /mail/settings >/dev/null; req A GET /mail/settings >/dev/null
contains "fetch explains missing setup" "$TMP/body" "imap\|MAIL_IMAP"

echo "== Scope and permissions"
r=$(req J GET /mail); expect "JANA inbox" "${r%% *}" 200
lacks "JANA cannot see Kongu PO" "$TMP/body" "Purchase Order PO-KNG-131"
contains "JANA sees the email assigned to her" "$TMP/body" "Purchase Order PO-AN-77"
KID=$(curl -s -b "$TMP/A" "$BASE/mail?q=PO-KNG-131" | grep -o 'mail/[0-9]*"' | head -1 | grep -o '[0-9]*')
r=$(req J GET "/mail/$KID"); expect "JANA cannot open Kongu email (404)" "${r%% *}" 404
r=$(req J GET /mail/new); expect "JANA cannot add email (403)" "${r%% *}" 403
r=$(req C GET /mail/settings); expect "coordinator has no mail settings (403)" "${r%% *}" 403
t=$(csrf C /mail)
r=$(req C POST "/mail/$KID/category" --data-urlencode "_csrf=$t" --data "category_id=5"); expect "coordinator cannot recategorise (403)" "${r%% *}" 403

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
