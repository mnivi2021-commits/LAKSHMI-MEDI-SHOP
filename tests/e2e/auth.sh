#!/usr/bin/env bash
# End-to-end authentication test (web + API) over real HTTP.
#
#   bash tests/e2e/auth.sh [BASE_URL]
#
# Requires a FRESHLY SEEDED local database (php cli/install.php --fresh --seed),
# because it changes the demo admin password and triggers lockouts.
set -u
BASE="${1:-http://localhost/marketing_crm}"
TMP="$(mktemp -d)"
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); echo "  PASS  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL  $1  ($2)"; }
expect() { [ "$2" = "$3" ] && ok "$1" || bad "$1" "expected '$3', got '$2'"; }
contains() { grep -q -- "$3" "$2" && ok "$1" || bad "$1" "'$3' not found"; }

# req JAR METHOD PATH [curl args...]  -> prints "STATUS LOCATION", body in $TMP/body
req() {
  local jar="$TMP/$1" method="$2" path="$3"; shift 3
  curl -s -o "$TMP/body" -b "$jar" -c "$jar" -X "$method" -w '%{http_code} %{redirect_url}' "$@" "$BASE$path"
}
csrf() { req "$1" GET "$2" >/dev/null; grep -o 'name="_csrf" value="[^"]*"' "$TMP/body" | head -1 | sed 's/.*value="//;s/"$//'; }
login() { local t; t=$(csrf "$1" /login); req "$1" POST /login --data-urlencode "_csrf=$t" --data-urlencode "identifier=$2" --data-urlencode "password=$3" "${@:4}"; }
sid() { awk '$6 ~ /mcrm_session/ {print $7}' "$TMP/$1" | tail -1; }
api() { curl -s -o "$TMP/body" -w '%{http_code}' "$@"; }

NEWPASS='Teal-Harbour-7391'
NEWPASS2='Copper-Lantern-5820'

echo "== Public pages"
r=$(req a GET /);                expect "intro page is public" "${r%% *}" 200
contains "intro has sign-in button" "$TMP/body" "Sign in to the CRM"
r=$(req a GET /password/change); expect "protected page redirects to login with next" "$r" "303 $BASE/login?next=%2Fpassword%2Fchange"

echo "== CSRF"
r=$(req a POST /login --data "identifier=admin&password=x"); expect "login without CSRF token rejected" "${r%% *}" 403

echo "== Wrong credentials (no user enumeration)"
r=$(login a admin wrong-password);     expect "wrong password redirects back" "$r" "303 $BASE/login"
req a GET /login >/dev/null;           contains "generic error for wrong password" "$TMP/body" "Incorrect username or password."
r=$(login a nobody-here wrong);        req a GET /login >/dev/null
contains "same error for unknown user" "$TMP/body" "Incorrect username or password."

echo "== First login forces password change"
req b GET /login >/dev/null; before=$(sid b)
r=$(login b admin 'Admin@2026');       expect "admin login redirects to password change" "$r" "303 $BASE/password/change"
after=$(sid b)
[ -n "$after" ] && [ "$before" != "$after" ] && ok "session id regenerated on login" || bad "session id regenerated on login" "$before -> $after"
r=$(req b GET /);                      expect "home blocked until password changed" "$r" "303 $BASE/password/change"

t=$(csrf b /password/change)
r=$(req b POST /password/change --data-urlencode "_csrf=$t" --data-urlencode "current_password=Admin@2026" --data-urlencode "new_password=short1" --data-urlencode "confirm_password=short1")
req b GET /password/change >/dev/null; contains "weak password rejected" "$TMP/body" "at least 10 characters"
t=$(csrf b /password/change)
r=$(req b POST /password/change --data-urlencode "_csrf=$t" --data-urlencode "current_password=wrong" --data-urlencode "new_password=$NEWPASS" --data-urlencode "confirm_password=$NEWPASS")
req b GET /password/change >/dev/null; contains "wrong current password rejected" "$TMP/body" "current password is incorrect"
t=$(csrf b /password/change)
r=$(req b POST /password/change --data-urlencode "_csrf=$t" --data-urlencode "current_password=Admin@2026" --data-urlencode "new_password=$NEWPASS" --data-urlencode "confirm_password=$NEWPASS")
expect "strong password accepted" "$r" "303 $BASE/"
r=$(req b GET /);                      expect "home now reachable" "${r%% *}" 200
contains "home shows signed-in user" "$TMP/body" "<span class=\"user-name\">Admin Head</span>"
r=$(req b GET /login);                 expect "signed-in user sent away from login" "$r" "303 $BASE/"

echo "== Password change signs out other sessions"
r=$(login c admin "$NEWPASS");         expect "second session login" "$r" "303 $BASE/"
t=$(csrf b /password/change)
r=$(req b POST /password/change --data-urlencode "_csrf=$t" --data-urlencode "current_password=$NEWPASS" --data-urlencode "new_password=$NEWPASS2" --data-urlencode "confirm_password=$NEWPASS2")
expect "password changed again" "$r" "303 $BASE/"
r=$(req b GET /);                      expect "changing session stays signed in" "${r%% *}" 200
r=$(req c GET /);                      expect "other session is signed out" "${r%% *}" 200
contains "other session sees intro, not CRM" "$TMP/body" "Sign in to the CRM"

echo "== Logout"
t=$(csrf b /)
r=$(req b POST /logout --data "_csrf=bogus"); expect "logout with bad CSRF rejected" "${r%% *}" 403
r=$(req b POST /logout --data-urlencode "_csrf=$t"); expect "logout redirects to login" "$r" "303 $BASE/login"
req b GET /login >/dev/null; contains "signed-out message" "$TMP/body" "You have been signed out."
r=$(req b GET /password/change);       expect "after logout, protected page redirects" "${r%% *}" 303

echo "== Open redirect blocked"
t=$(csrf d /login)
# Pre-encoded: Git Bash on Windows rewrites arguments that start with // .
r=$(req d POST /login --data-urlencode "_csrf=$t" --data-urlencode "identifier=admin" --data-urlencode "password=$NEWPASS2" --data "next=%2F%2Fevil.example.com")
expect "next=//evil goes home instead" "$r" "303 $BASE/"

echo "== Brute-force lockout"
for i in 1 2 3 4 5; do login e coordinator "wrong-$i" >/dev/null; done
r=$(login e coordinator 'Coord@2026'); req e GET /login >/dev/null
contains "correct password refused while locked" "$TMP/body" "Too many failed attempts"
for i in 1 2 3 4 5; do login f ghost-user "wrong-$i" >/dev/null; done
login f ghost-user x >/dev/null; req f GET /login >/dev/null
contains "unknown user locks the same way (no enumeration)" "$TMP/body" "Too many failed attempts"

echo "== API (mobile)"
s=$(api -X POST -H 'Content-Type: application/json' -d '{"username":"admin","password":"nope"}' "$BASE/api/auth/login"); expect "API wrong password = 401" "$s" 401
s=$(api -X POST -H 'Content-Type: application/json' -d '{"username":"jana","password":"Sales@2026"}' "$BASE/api/auth/login"); expect "API blocks temporary password = 403" "$s" 403
contains "API says password_change_required" "$TMP/body" "password_change_required"
s=$(api -X POST -H 'Content-Type: application/json' -d "{\"username\":\"admin\",\"password\":\"$NEWPASS2\",\"device_name\":\"e2e\"}" "$BASE/api/auth/login"); expect "API login = 200" "$s" 200
TOKEN=$(grep -o '"token":"[^"]*"' "$TMP/body" | sed 's/"token":"//;s/"$//')
[ ${#TOKEN} -ge 40 ] && ok "API returns token" || bad "API returns token" "len ${#TOKEN}"
grep -q "password_hash" "$TMP/body" && bad "API leaks password hash" "found" || ok "API does not leak password hash"
s=$(api "$BASE/api/auth/me");                                       expect "API /me without token = 401" "$s" 401
s=$(api -H "Authorization: Bearer $TOKEN" "$BASE/api/auth/me");     expect "API /me with token = 200" "$s" 200
contains "API /me returns role" "$TMP/body" '"slug":"admin_head"'
s=$(api -H "Authorization: Bearer ${TOKEN}x" "$BASE/api/auth/me");  expect "API tampered token = 401" "$s" 401
s=$(api -X POST -H "Authorization: Bearer $TOKEN" "$BASE/api/auth/logout"); expect "API logout = 200" "$s" 200
s=$(api -H "Authorization: Bearer $TOKEN" "$BASE/api/auth/me");     expect "revoked token = 401" "$s" 401
s=$(curl -s -o /dev/null -D "$TMP/h" -X OPTIONS -H 'Origin: http://localhost:8081' "$BASE/api/auth/login" -w '%{http_code}'); expect "CORS preflight = 204" "$s" 204
grep -qi "access-control-allow-origin: http://localhost:8081" "$TMP/h" && ok "CORS allows configured origin" || bad "CORS allows configured origin" "header missing"
curl -s -o /dev/null -D "$TMP/h" -X OPTIONS -H 'Origin: http://evil.example.com' "$BASE/api/auth/login"
grep -qi "access-control-allow-origin" "$TMP/h" && bad "CORS rejects other origins" "header present" || ok "CORS rejects other origins"
curl -s -D "$TMP/h" -o /dev/null "$BASE/api/auth/me"; grep -qi "set-cookie" "$TMP/h" && bad "API sets no session cookie" "cookie set" || ok "API sets no session cookie"

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
