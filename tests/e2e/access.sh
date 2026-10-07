#!/usr/bin/env bash
# End-to-end test: users, roles, permission matrix and server-side enforcement.
#
#   bash tests/e2e/access.sh [BASE_URL]
#
# Requires a FRESHLY SEEDED local database (php cli/install.php --fresh --seed).
set -u
BASE="${1:-http://localhost/marketing_crm}"
TMP="$(mktemp -d)"
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); echo "  PASS  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL  $1  ($2)"; }
expect()   { [ "$2" = "$3" ] && ok "$1" || bad "$1" "expected '$3', got '$2'"; }
contains() { grep -q -- "$3" "$2" && ok "$1" || bad "$1" "'$3' not found"; }
lacks()    { grep -q -- "$3" "$2" && bad "$1" "'$3' present" || ok "$1"; }

req() {   # req JAR METHOD PATH [curl args] -> "STATUS LOCATION"; body in $TMP/body
  local jar="$TMP/$1" method="$2" path="$3"; shift 3
  curl -s -o "$TMP/body" -b "$jar" -c "$jar" -X "$method" -w '%{http_code} %{redirect_url}' "$@" "$BASE$path"
}
csrf() { req "$1" GET "$2" >/dev/null; grep -o 'name="_csrf" value="[^"]*"' "$TMP/body" | head -1 | sed 's/.*value="//;s/"$//'; }
post() { local jar="$1" path="$2" page="$3"; shift 3; local t; t=$(csrf "$jar" "$page"); req "$jar" POST "$path" --data-urlencode "_csrf=$t" "$@"; }
login() { post "$1" /login /login --data-urlencode "identifier=$2" --data-urlencode "password=$3" >/dev/null; }
first_login() {  # jar user temp new
  login "$1" "$2" "$3"
  post "$1" /password/change /password/change --data-urlencode "current_password=$3" \
       --data-urlencode "new_password=$4" --data-urlencode "confirm_password=$4" >/dev/null
}
temp_password() { tr -d '\n' < "$TMP/body" | grep -o "password for $1: [A-Za-z0-9-]*" | head -1 | sed 's/.*: //'; }
perm_id() {   # perm_id SLUG  (from the role form currently in $TMP/body)
  tr -d '\n' < "$TMP/body" | grep -oE "title=\"$1( \\(critical\\))?\"[^<]*<input[^>]*value=\"[0-9]*\"" | head -1 | grep -o 'value="[0-9]*"' | grep -o '[0-9]*'
}

ADMIN_PW='Teal-Harbour-7391'; COORD_PW='Saffron-Kite-4415'; JANA_PW='Monsoon-Field-2087'

echo "== Admin Head: users list and menu"
first_login A admin 'Admin@2026' "$ADMIN_PW"
r=$(req A GET /access/users);        expect "admin opens Users" "${r%% *}" 200
contains "lists the 3 seeded users" "$TMP/body" "3 users"
contains "sidebar shows Access" "$TMP/body" 'href="/marketing_crm/access/users"'
lacks "all modules built (no Soon badges)" "$TMP/body" ">Soon<"

echo "== Create user (validation, temp password)"
r=$(post A /access/users /access/users/new --data "name=Senthil Nathan&username=senthil&email=senthil@example.com&role_id=5&employee_id=8")
expect "branch role without branches is rejected" "$r" "303 $BASE/access/users/new"
req A GET /access/users/new >/dev/null; contains "explains missing branch" "$TMP/body" "Tick at least one branch"
r=$(post A /access/users /access/users/new --data "name=Senthil Nathan&username=senthil&email=senthil@example.com&mobile=9000000008&role_id=5&employee_id=8&branch_ids[]=2")
expect "valid user created" "$r" "303 $BASE/access/users"
req A GET /access/users >/dev/null
SENTHIL_TMP=$(temp_password senthil)
[ ${#SENTHIL_TMP} -eq 14 ] && ok "temporary password shown once" || bad "temporary password shown once" "got '$SENTHIL_TMP'"
contains "list now shows 4 users" "$TMP/body" "4 users"
SID=$(tr -d '\n' < "$TMP/body" | grep -o "senthil@example.com.*" | grep -o 'access/users/[0-9]*/edit' | head -1 | grep -o '[0-9]*')
req A GET /access/users >/dev/null; lacks "temporary password not shown again" "$TMP/body" "password for senthil:"
r=$(post A /access/users /access/users/new --data "name=Dup&username=senthil&email=dup@example.com&role_id=9&branch_ids[]=1")
req A GET /access/users/new >/dev/null; contains "duplicate username rejected" "$TMP/body" "already taken"
r=$(post A /access/users /access/users/new --data "name=X&username=xuser&email=x@example.com&role_id=4")
req A GET /access/users/new >/dev/null; contains "own-scope role needs employee" "$TMP/body" "link an employee"

echo "== Self-protection"
post A /access/users/1/status /access/users >/dev/null; req A GET /access/users >/dev/null
contains "cannot disable yourself" "$TMP/body" "cannot disable your own account"
post A /access/users/1/delete /access/users >/dev/null; req A GET /access/users >/dev/null
contains "cannot delete yourself" "$TMP/body" "cannot delete your own account"
req A GET /access/users/1/edit >/dev/null; contains "own role is locked" "$TMP/body" "cannot change your own role"
post A /access/roles/1 /access/roles/1 --data "name=Admin Head&data_scope=own" >/dev/null; req A GET /access/roles >/dev/null
contains "Admin Head role cannot be edited" "$TMP/body" "cannot be changed"

echo "== Coordinator: partial access enforced on the server"
first_login C coordinator 'Coord@2026' "$COORD_PW"
r=$(req C GET /);                    expect "coordinator reaches home" "${r%% *}" 200
lacks "coordinator menu hides Access" "$TMP/body" 'access/users"'
contains "coordinator menu shows Dashboard" "$TMP/body" ">Dashboard<"
r=$(req C GET /access/users);        expect "coordinator blocked from Users (403)" "${r%% *}" 403
r=$(req C GET /access/roles);        expect "coordinator blocked from Roles (403)" "${r%% *}" 403
t=$(csrf C /)
r=$(req C POST /access/users/1/status --data-urlencode "_csrf=$t"); expect "direct POST also blocked (403)" "${r%% *}" 403

echo "== Admin grants Coordinator users.view + users.edit (and JANA's view rights) via the matrix"
req A GET /access/roles/2 >/dev/null
CHECKED=$(tr -d '\n' < "$TMP/body" | grep -o '<input type="checkbox" name="permissions\[\]" value="[0-9]*" data-action="[a-z]*" *checked' | grep -o 'value="[0-9]*"' | grep -o '[0-9]*')
# A user may only manage users whose rights they also hold, so the coordinator also needs JANA's view rights.
EXTRA=()
for slug in users.view users.edit sales.view targets.view collections.view outstanding.view pending_orders.view samples.view dc.view customers.view products.view leads.view mail.view; do
  EXTRA+=("$(perm_id $slug)")
done
ARGS=(--data "name=Admin Coordinator&description=Partial access&data_scope=all")
for id in $CHECKED "${EXTRA[@]}"; do ARGS+=(--data "permissions[]=$id"); done
r=$(post A /access/roles/2 /access/roles/2 "${ARGS[@]}"); expect "role saved" "$r" "303 $BASE/access/roles/2"
req A GET /access/roles/2 >/dev/null; contains "save reports ${#EXTRA[@]} added" "$TMP/body" "${#EXTRA[@]} added, 0 removed"
r=$(req C GET /access/users);        expect "coordinator now opens Users (same session)" "${r%% *}" 200
r=$(req C GET /access/users/1/edit); expect "coordinator cannot edit Admin Head (403)" "${r%% *}" 403
t=$(csrf C /access/users)
r=$(req C POST /access/users/1/reset-password --data-urlencode "_csrf=$t"); expect "coordinator cannot reset Admin Head password" "${r%% *}" 403
r=$(post C /access/users/3 /access/users/3/edit --data "name=Janakiraman S&username=jana&email=jana@example.com&role_id=1&employee_id=2")
req C GET /access/users/3/edit >/dev/null; contains "coordinator cannot assign Admin Head role" "$TMP/body" "more access than you"
r=$(req C GET /access/users/3/permissions); expect "overrides need access.manage (403)" "${r%% *}" 403

echo "== Password reset and disable take effect immediately"
r=$(post C /access/users/3/reset-password /access/users); expect "coordinator resets JANA password" "$r" "303 $BASE/access/users"
req C GET /access/users >/dev/null; JANA_TMP=$(temp_password jana)
[ -n "$JANA_TMP" ] && ok "reset shows new temporary password" || bad "reset shows new temporary password" "none"
first_login J jana "$JANA_TMP" "$JANA_PW"
r=$(req J GET /);                    expect "JANA signs in with reset password" "${r%% *}" 200
contains "JANA sees the Targets dashboard" "$TMP/body" "<h1>Targets</h1>"
post A /access/users/3/status /access/users >/dev/null
r=$(req J GET /); contains "disabled JANA is signed out at once" "$TMP/body" "Sign in to the CRM"
login J jana "$JANA_PW"; req J GET /login >/dev/null; contains "disabled JANA cannot sign in" "$TMP/body" "account is disabled"

echo "== Per-user override (Admin)"
req A GET /access/users/$SID/permissions >/dev/null
RV=$(tr -d '\n' < "$TMP/body" | grep -o 'name="override\[[0-9]*\]"' | head -1 | grep -o '[0-9]*')
r=$(post A /access/users/$SID/permissions /access/users/$SID/permissions --data "override[$RV]=deny"); expect "override saved" "$r" "303 $BASE/access/users/$SID/permissions"
req A GET /access/users/$SID/permissions >/dev/null; contains "override shown as Deny" "$TMP/body" 'value="deny" selected'

echo "== Custom role lifecycle"
req A GET /access/roles/new >/dev/null; DV=$(perm_id dashboard.view); RPV=$(perm_id reports.view)
r=$(post A /access/roles /access/roles/new --data "name=Accounts Viewer&description=Read-only accounts&data_scope=branch&permissions[]=$DV&permissions[]=$RPV")
expect "custom role created" "$r" "303 $BASE/access/roles"
req A GET /access/roles >/dev/null; contains "custom role listed" "$TMP/body" "Accounts Viewer"
NEWID=$(tr -d '\n' < "$TMP/body" | grep -o 'access/roles/[0-9]*/delete' | head -1 | grep -o '[0-9]*')
post A /access/roles/2/delete /access/roles >/dev/null; req A GET /access/roles >/dev/null
contains "built-in role cannot be deleted" "$TMP/body" "cannot be deleted"
r=$(post A "/access/roles/$NEWID/delete" /access/roles); expect "custom role deleted" "$r" "303 $BASE/access/roles"

echo "== API exposes permissions for the mobile menu"
curl -s -o "$TMP/body" -X POST -H 'Content-Type: application/json' -d "{\"username\":\"coordinator\",\"password\":\"$COORD_PW\"}" "$BASE/api/auth/login"
contains "API user has permissions list" "$TMP/body" '"permissions":\['
contains "API lists users.view for coordinator" "$TMP/body" '"users.view"'

rm -rf "$TMP"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
