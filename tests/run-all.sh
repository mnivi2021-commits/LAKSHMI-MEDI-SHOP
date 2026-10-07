#!/usr/bin/env bash
# Runs every check in one go and prints a summary.
#
#   ALLOW_DB_RESET=yes bash tests/run-all.sh               everything (about 15 minutes)
#   ALLOW_DB_RESET=yes bash tests/run-all.sh --unit        PHP lint + unit tests only (about 1 minute)
#   ALLOW_DB_RESET=yes bash tests/run-all.sh --e2e         end-to-end suites only
#   ALLOW_DB_RESET=yes bash tests/run-all.sh sms reports   only the named suites (unit and/or e2e)
#
# The database in .env is ERASED and reseeded with demo data (cli/install.php
# --fresh --seed) before the tests. NEVER run this against real data.
set -u
cd "$(dirname "$0")/.."
PHP="${PHP:-/c/xampp/php/php.exe}"
command -v "$PHP" >/dev/null 2>&1 || PHP=php
BASE="${BASE:-http://localhost/marketing_crm}"

UNIT=(run db dashboard sales_kpi collection_kpi pending_kpi outstanding_kpi rep_panel customer_product_panel
      number_sequence sales_grid imports mail sms reports branch_performance requests)
E2E=(auth access dashboard sales kpis branches customers products leads hrm sales_details imports mail sms
     reports settings mobile_api branch_performance requests followup)

mode=all; only=()
for a in "$@"; do
  case "$a" in
    --unit) mode=unit ;;
    --e2e) mode=e2e ;;
    -h|--help) sed -n 2,10p "$0"; exit 0 ;;
    *) only+=("$a") ;;
  esac
done
want() { [ ${#only[@]} -eq 0 ] && return 0; for o in "${only[@]}"; do [ "$o" = "$1" ] && return 0; done; return 1; }

if ! grep -q '^APP_ENV=local' .env 2>/dev/null; then
  echo "Refusing to run: .env is not APP_ENV=local (the tests wipe and reseed the database)."; exit 2
fi
# The office PC's real CRM may also run with APP_ENV=local, so require an explicit
# statement that the database in .env is a throwaway test copy.
if [ "${ALLOW_DB_RESET:-}" != "yes" ]; then
  db=$(grep -E '^DB_(HOST|PORT|DATABASE)=' .env | tr '\n' ' ')
  echo "These tests ERASE the database in .env ($db) and fill it with demo data."
  echo "Only run them against a test database, then:   ALLOW_DB_RESET=yes bash tests/run-all.sh"
  exit 2
fi

results=(); failures=0; started=$(date +%s)
record() { results+=("$(printf '%-28s %s' "$1" "$2")"); case "$2" in *FAIL*|*ERROR*) failures=$((failures+1));; esac; }

# 1. Syntax of every PHP file
if [ "$mode" != e2e ] && [ ${#only[@]} -eq 0 ]; then
  bad=0
  while IFS= read -r f; do "$PHP" -l "$f" >/dev/null 2>&1 || { echo "  syntax error: $f"; bad=$((bad+1)); }; done \
    < <(find app bootstrap cli config routes public tests -name '*.php' -not -path '*/node_modules/*')
  [ $bad -eq 0 ] && record "php lint" "ok" || record "php lint" "FAIL ($bad file(s))"
fi

# 2. Unit / accuracy tests (each runs inside a transaction and rolls back)
if [ "$mode" != e2e ]; then
  "$PHP" cli/install.php --fresh --seed >/dev/null 2>&1 || { echo "Could not seed the database."; exit 2; }
  for t in "${UNIT[@]}"; do
    want "$t" || continue
    out=$("$PHP" "tests/$t.php" 2>&1 | tail -1)
    case "$out" in *" 0 failed"*) record "unit  $t" "$out";; *) record "unit  $t" "FAIL: $out";; esac
  done
fi

# 3. End-to-end suites over HTTP (fresh demo data before each)
if [ "$mode" != unit ]; then
  code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/login")
  if [ "$code" != 200 ]; then
    record "e2e" "ERROR: $BASE/login answered $code (is Apache running?)"
  else
    for s in "${E2E[@]}"; do
      want "$s" || continue
      "$PHP" cli/install.php --fresh --seed >/dev/null 2>&1
      out=$(bash "tests/e2e/$s.sh" "$BASE" 2>&1)
      last=$(echo "$out" | tail -1)
      case "$last" in *" 0 failed"*) record "e2e   $s" "$last";; *) record "e2e   $s" "FAIL: $last"; echo "$out" | grep "FAIL" | sed 's/^/        /';; esac
    done
  fi
fi

# 4. Mobile app typecheck (only if its packages are installed)
if [ "$mode" = all ] && [ ${#only[@]} -eq 0 ] && [ -d mobile/node_modules ]; then
  (cd mobile && npx tsc --noEmit >/dev/null 2>&1) && record "mobile typecheck" "ok" || record "mobile typecheck" "FAIL"
fi

"$PHP" cli/install.php --fresh --seed >/dev/null 2>&1
echo; echo "================ Summary ================"
printf '%s\n' "${results[@]}"
echo "========================================="
echo "$(( ($(date +%s) - started) / 60 )) min · $failures failing group(s)"
[ $failures -eq 0 ]
