#!/usr/bin/env bash
#
# commands.sh — FitPal docker stack lifecycle and database initialization
#
# Usage: ./commands.sh   (from the repo root)
#        or ./docker/commands.sh   (from the repo root)
#
# Requires: docker, docker compose (v2)
#
#   [1] rebuild everything
#   [2] only reset the mysql and initialize db
#   [0] cancel
#
# Schema and seed are piped into the container via docker cp and
# verified by sha256sum on both sides before import. This removes
# two Windows-specific failure modes: a bind mount returning a
# stale inode after a host-side edit, and `docker cp` reading a
# file the filesystem has since replaced.
#
set -uo pipefail

# ---- configuration ---------------------------------------------------------

COMPOSE_FILE="docker/docker-compose.yaml"
SCHEMA_FILE="sql/database.sql"
SEED_FILE="sql/seed/all-in-one.sql"
DATA_DIR="docker/mysql_data"
DB_SERVICE="db"
DB_CONTAINER="my-mysql-db"
DB_SCHEMA="fitpal_food_delivery"
DB_WAIT_TIMEOUT=90
DATA_SERVICES=(db)
APP_SERVICES=(web phpmyadmin)

# Counts for sql/database.sql v2.6.3.
# 29 CREATE TABLE, 20 CREATE TRIGGER, 8 CREATE VIEW, 9 CREATE PROCEDURE.
EXPECTED_TABLES_AFTER_SCHEMA=29
EXPECTED_VIEWS_AFTER_SCHEMA=8
EXPECTED_TRIGGERS_AFTER_SCHEMA=20
EXPECTED_PROCEDURES_AFTER_SCHEMA=9

# of the triggers above, how many have 'rating' in their name.
EXPECTED_RATING_TRIGGERS=5

CONTAINER_SCHEMA_PATH="/tmp/schema.sql"
CONTAINER_SEED_PATH="/tmp/seed.sql"

# ---- helpers ---------------------------------------------------------------

C_RESET=$'\033[0m'
C_GREEN=$'\033[32m'
C_YELLOW=$'\033[33m'
C_RED=$'\033[31m'
C_CYAN=$'\033[36m'
C_BOLD=$'\033[1m'

log()  { printf '%s\n' "$*"; }
info() { printf '%s[INFO]%s    %s\n' "$C_CYAN"   "$C_RESET" "$*"; }
ok()   { printf '%s[OK]%s      %s\n' "$C_GREEN"  "$C_RESET" "$*"; }
warn() { printf '%s[WARN]%s    %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
fail() { printf '%s[FAILED]%s  %s\n' "$C_RED"    "$C_RESET" "$*" >&2; }
step() { printf '\n%s%s== %s ==%s\n' "$C_BOLD"   "$C_CYAN"  "$*" "$C_RESET"; }
die()  { fail "$*"; exit 1; }

require() { command -v "$1" >/dev/null 2>&1 || die "required command '$1' not found in PATH"; }
compose() { docker compose -f "$COMPOSE_FILE" "$@"; }
kv()      { printf '  %-24s %s\n' "$1" "$2"; }

host_sha() { sha256sum "$1" | awk '{print $1}'; }

ctr_sha() {
  docker exec "$DB_CONTAINER" sh -c "sha256sum '$1'" 2>/dev/null \
    | awk '{print $1}'
}

mysql_scalar() {
  docker exec "$DB_CONTAINER" mysql -uroot -N -B -e "$1" 2>/dev/null || echo ""
}

count_tables()     { mysql_scalar "SELECT COUNT(*) FROM information_schema.TABLES       WHERE TABLE_SCHEMA='$DB_SCHEMA' AND TABLE_TYPE='BASE TABLE'"; }
count_views()      { mysql_scalar "SELECT COUNT(*) FROM information_schema.VIEWS        WHERE TABLE_SCHEMA='$DB_SCHEMA'"; }
count_triggers()   { mysql_scalar "SELECT COUNT(*) FROM information_schema.TRIGGERS     WHERE TRIGGER_SCHEMA='$DB_SCHEMA'"; }
count_procedures() { mysql_scalar "SELECT COUNT(*) FROM information_schema.ROUTINES      WHERE ROUTINE_SCHEMA='$DB_SCHEMA' AND ROUTINE_TYPE='PROCEDURE'"; }

list_tables()      { mysql_scalar "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_SCHEMA' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"; }
list_triggers()    { mysql_scalar "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$DB_SCHEMA' ORDER BY TRIGGER_NAME"; }
list_views()       { mysql_scalar "SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA='$DB_SCHEMA' ORDER BY TABLE_NAME"; }

# ---- preflight -------------------------------------------------------------

step "Preflight"

require docker
ok "docker found: $(command -v docker)"

docker compose version >/dev/null 2>&1 || die "docker compose v2 is required but not available"
ok "compose version: $(docker compose version --short 2>/dev/null || echo unknown)"

[ -f "$COMPOSE_FILE" ] || die "compose file not found: $COMPOSE_FILE"
ok "compose file: $COMPOSE_FILE"

[ -f "$SCHEMA_FILE" ] || die "schema file not found: $SCHEMA_FILE"

SCHEMA_ABS=$(realpath "$SCHEMA_FILE")
SEED_ABS=$(realpath "$SEED_FILE" 2>/dev/null || echo "")

[ -f "$SCHEMA_ABS" ] || die "schema file not resolvable: $SCHEMA_FILE"
ok "schema file: $SCHEMA_ABS ($(wc -l < "$SCHEMA_ABS") lines, $(wc -c < "$SCHEMA_ABS" | tr -d '[:space:]') bytes)"
info "schema sha256: $(host_sha "$SCHEMA_ABS")"

if [ -n "$SEED_ABS" ] && [ -f "$SEED_ABS" ]; then
  ok "seed file: $SEED_ABS ($(wc -l < "$SEED_ABS") lines, $(wc -c < "$SEED_ABS" | tr -d '[:space:]') bytes)"
  info "seed sha256: $(host_sha "$SEED_ABS")"
else
  warn "seed file not found or not resolvable: $SEED_FILE — schema will import without seed data"
  SEED_ABS=""
fi

if grep -q '^DELIMITER;$' "$SCHEMA_ABS"; then
  fail "$SCHEMA_ABS still has unspaced 'DELIMITER;' lines:"
  grep -n '^DELIMITER;$' "$SCHEMA_ABS" | sed 's/^/    /'
  exit 1
fi
ok "no unspaced 'DELIMITER;' lines in schema"

# rating.subject_key must be VIRTUAL.
#
# MySQL 8.0 rejects a STORED generated column that reads a
# foreign-key source column with an ON DELETE action, including
# ON DELETE CASCADE. subject_key reads queue_item_id and branch_id,
# both of which carry ON DELETE CASCADE, so STORED fails with
# ERROR 1215. VIRTUAL sidesteps the restriction and can still be
# indexed by UNIQUE on MySQL 8.0.13+ (this environment is 8.0.46).
RATING_VIRTUAL_MATCH=$(grep -A60 'CREATE TABLE rating' "$SCHEMA_ABS" | grep -c 'VIRTUAL' || true)
if [ "${RATING_VIRTUAL_MATCH:-0}" -lt 1 ]; then
  fail "$SCHEMA_ABS does not contain VIRTUAL in the rating block; it must (schema v2.6.3)"
  fail "Run this to see what is actually in the file:"
  fail "  grep -n -A60 'CREATE TABLE rating' $SCHEMA_ABS | grep -E 'STORED|VIRTUAL'"
  log ""
  log "What the rating block currently contains:"
  grep -n -A60 'CREATE TABLE rating' "$SCHEMA_ABS" | grep -E 'STORED|VIRTUAL' | sed 's/^/    /' >&2 || true
  exit 1
fi
ok "rating.subject_key is VIRTUAL"

if [ -n "$SEED_ABS" ]; then
  extra_ddl=$(grep -cE '^[[:space:]]*CREATE[[:space:]]+(TABLE|TRIGGER)' "$SEED_ABS" 2>/dev/null || true)
  if [ "${extra_ddl:-0}" -gt 0 ]; then
    warn "seed file contains $extra_ddl CREATE TABLE / CREATE TRIGGER statement(s)"
    warn "the seed is not pure INSERT; see the seed audit block at the end of this run"
  else
    ok "seed file contains no CREATE TABLE / CREATE TRIGGER statements"
  fi
fi

# ---- menu ------------------------------------------------------------------

step "Mode selection"

cat <<'EOF'
What would you like to do?

  [1] rebuild everything
  [2] only reset the mysql and initialize db

  [0] cancel
EOF
printf 'Choice: '
read -r choice

case "$choice" in
  1) MODE="all" ;;
  2) MODE="db" ;;
  0) log "Cancelled."; exit 0 ;;
  *) die "invalid choice: '$choice' (expected 0, 1, or 2)" ;;
esac
info "mode: $MODE"

# ---- teardown --------------------------------------------------------------

drop_mysql_data() {
  if [ -d "$DATA_DIR" ]; then
    local size
    size=$(du -sh "$DATA_DIR" 2>/dev/null | awk '{print $1}' || echo "?")
    info "removing $DATA_DIR ($size)"
    rm -rf "$DATA_DIR"
    [ -d "$DATA_DIR" ] && die "could not remove $DATA_DIR"
    ok "data directory removed"
  else
    info "data directory already absent: $DATA_DIR"
  fi
}

step "Teardown"

if [ "$MODE" = "all" ]; then
  info "compose down --remove-orphans -v"
  compose down --remove-orphans -v 2>&1 | sed 's/^/  /'
  ok "all containers removed"
  drop_mysql_data
else
  info "stopping ${DATA_SERVICES[*]}"
  compose stop "${DATA_SERVICES[@]}" 2>&1 | sed 's/^/  /' || true
  info "removing ${DATA_SERVICES[*]}"
  compose rm -f "${DATA_SERVICES[@]}" 2>&1 | sed 's/^/  /' || true
  ok "db containers removed"
  drop_mysql_data
fi

# ---- phase A: start db only ------------------------------------------------

step "Starting $DB_SERVICE"

info "compose up -d --force-recreate $DB_SERVICE"
if ! compose up -d --force-recreate "$DB_SERVICE" 2>&1 | sed 's/^/  /'; then
  die "could not start $DB_SERVICE"
fi
ok "$DB_SERVICE started"

# ---- wait for db healthy ---------------------------------------------------

step "Waiting for MySQL to become healthy"

wait_start=$(date +%s)
last_health=""
while :; do
  health=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$DB_CONTAINER" 2>/dev/null || echo "missing")
  if [ "$health" != "$last_health" ]; then
    info "db health: $health"
    last_health="$health"
  fi
  case "$health" in
    healthy) break ;;
    unhealthy|exited|missing)
      fail "db is not healthy (state: $health)"
      docker logs --tail 40 "$DB_CONTAINER" 2>&1 | sed 's/^/  /' || true
      exit 1
      ;;
  esac
  if [ $(( $(date +%s) - wait_start )) -ge "$DB_WAIT_TIMEOUT" ]; then
    fail "db did not become healthy within ${DB_WAIT_TIMEOUT}s"
    docker logs --tail 40 "$DB_CONTAINER" 2>&1 | sed 's/^/  /' || true
    exit 1
  fi
  sleep 2
done
ok "db healthy in $(( $(date +%s) - wait_start ))s"

# ---- phase B: docker cp the schema and verify by hash ----------------------

step "Copying schema into container"

SCHEMA_HOST_SHA=$(host_sha "$SCHEMA_ABS")
SCHEMA_HOST_BYTES=$(wc -c < "$SCHEMA_ABS" | tr -d '[:space:]')

info "host schema:  $SCHEMA_ABS"
info "host bytes:   $SCHEMA_HOST_BYTES"
info "host sha256:  $SCHEMA_HOST_SHA"

info "docker cp $SCHEMA_ABS $DB_CONTAINER:$CONTAINER_SCHEMA_PATH"
if ! docker cp "$SCHEMA_ABS" "$DB_CONTAINER:$CONTAINER_SCHEMA_PATH"; then
  die "docker cp of $SCHEMA_ABS failed"
fi
ok "schema copied to $CONTAINER_SCHEMA_PATH"

SCHEMA_CTR_SHA=$(ctr_sha "$CONTAINER_SCHEMA_PATH")
if [ -z "$SCHEMA_CTR_SHA" ]; then
  die "could not read sha256 of $CONTAINER_SCHEMA_PATH inside the container"
fi
info "container sha256: $SCHEMA_CTR_SHA"

if [ "$SCHEMA_HOST_SHA" != "$SCHEMA_CTR_SHA" ]; then
  fail "schema sha256 mismatch"
  fail "  host:      $SCHEMA_HOST_SHA"
  fail "  container: $SCHEMA_CTR_SHA"
  fail "docker cp copied different bytes than are on the host disk"
  exit 1
fi
ok "schema sha256 matches (v2.6.3 confirmed)"

# ---- import schema ---------------------------------------------------------

step "Importing schema"

info "docker exec -i $DB_CONTAINER sh -c 'mysql -uroot --show-warnings < $CONTAINER_SCHEMA_PATH'"
if docker exec -i "$DB_CONTAINER" sh -c "mysql -uroot --show-warnings < $CONTAINER_SCHEMA_PATH"; then
  ok "schema import succeeded"
else
  rc=$?
  fail "schema import FAILED (exit $rc)"
  log ""
  log "Run this manually to see the full error:"
  log "  docker exec -i $DB_CONTAINER sh -c 'mysql -uroot --show-warnings < $CONTAINER_SCHEMA_PATH'"
  exit 1
fi

# ---- schema verification ---------------------------------------------------

step "Verifying schema (post-schema, pre-seed)"

tables_after_schema=$(count_tables)
views_after_schema=$(count_views)
triggers_after_schema=$(count_triggers)
procedures_after_schema=$(count_procedures)

kv "schema"            "$DB_SCHEMA"
kv "tables"            "${tables_after_schema:-?}   (expected $EXPECTED_TABLES_AFTER_SCHEMA)"
kv "views"             "${views_after_schema:-?}    (expected $EXPECTED_VIEWS_AFTER_SCHEMA)"
kv "triggers"          "${triggers_after_schema:-?} (expected $EXPECTED_TRIGGERS_AFTER_SCHEMA)"
kv "procedures"        "${procedures_after_schema:-?}"

problems=0
[ "${tables_after_schema:-0}"     -eq "$EXPECTED_TABLES_AFTER_SCHEMA" ]     || { fail "table count after schema: ${tables_after_schema:-0}, expected $EXPECTED_TABLES_AFTER_SCHEMA";     problems=$(( problems + 1 )); }
[ "${views_after_schema:-0}"      -eq "$EXPECTED_VIEWS_AFTER_SCHEMA" ]      || { fail "view count after schema: ${views_after_schema:-0}, expected $EXPECTED_VIEWS_AFTER_SCHEMA";       problems=$(( problems + 1 )); }
[ "${triggers_after_schema:-0}"   -eq "$EXPECTED_TRIGGERS_AFTER_SCHEMA" ]   || { fail "trigger count after schema: ${triggers_after_schema:-0}, expected $EXPECTED_TRIGGERS_AFTER_SCHEMA"; problems=$(( problems + 1 )); }
[ "${procedures_after_schema:-0}" -eq "$EXPECTED_PROCEDURES_AFTER_SCHEMA" ] || { fail "procedure count after schema: ${procedures_after_schema:-0}, expected $EXPECTED_PROCEDURES_AFTER_SCHEMA"; problems=$(( problems + 1 )); }

rating_present=$(mysql_scalar "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_SCHEMA' AND TABLE_NAME='rating'")
[ "${rating_present:-0}" -eq 1 ] && ok "rating table present" || { fail "rating table MISSING"; problems=$(( problems + 1 )); }

rating_triggers=$(mysql_scalar "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$DB_SCHEMA' AND TRIGGER_NAME LIKE '%rating%'")
[ "${rating_triggers:-0}" -eq "$EXPECTED_RATING_TRIGGERS" ] && ok "rating-related triggers present ($EXPECTED_RATING_TRIGGERS)" || { fail "rating-related triggers: got ${rating_triggers:-0}, expected $EXPECTED_RATING_TRIGGERS"; problems=$(( problems + 1 )); }

if [ "$problems" -ne 0 ]; then
  step "Result"
  fail "$problems schema verification problem(s)"
  exit 1
fi

tables_before_seed="$(list_tables)"
triggers_before_seed="$(list_triggers)"
views_before_seed="$(list_views)"

# ---- phase C: docker cp + import seed --------------------------------------

if [ -n "$SEED_ABS" ] && [ -f "$SEED_ABS" ]; then

  step "Copying seed into container"

  SEED_HOST_SHA=$(host_sha "$SEED_ABS")
  SEED_HOST_BYTES=$(wc -c < "$SEED_ABS" | tr -d '[:space:]')

  info "host seed:    $SEED_ABS"
  info "host bytes:   $SEED_HOST_BYTES"
  info "host sha256:  $SEED_HOST_SHA"

  info "docker cp $SEED_ABS $DB_CONTAINER:$CONTAINER_SEED_PATH"
  if ! docker cp "$SEED_ABS" "$DB_CONTAINER:$CONTAINER_SEED_PATH"; then
    die "docker cp of $SEED_ABS failed"
  fi
  ok "seed copied to $CONTAINER_SEED_PATH"

  SEED_CTR_SHA=$(ctr_sha "$CONTAINER_SEED_PATH")
  if [ -z "$SEED_CTR_SHA" ]; then
    die "could not read sha256 of $CONTAINER_SEED_PATH inside the container"
  fi
  info "container sha256: $SEED_CTR_SHA"

  if [ "$SEED_HOST_SHA" != "$SEED_CTR_SHA" ]; then
    fail "seed sha256 mismatch"
    fail "  host:      $SEED_HOST_SHA"
    fail "  container: $SEED_CTR_SHA"
    exit 1
  fi
  ok "seed sha256 matches"

  step "Importing seed"

  info "docker exec -i $DB_CONTAINER sh -c 'mysql -uroot --show-warnings $DB_SCHEMA < $CONTAINER_SEED_PATH'"
  if docker exec -i "$DB_CONTAINER" sh -c "mysql -uroot --show-warnings $DB_SCHEMA < $CONTAINER_SEED_PATH"; then
    ok "seed import succeeded"
  else
    rc=$?
    fail "seed import FAILED (exit $rc)"
    log ""
    log "Run this manually to see the full error:"
    log "  docker exec -i $DB_CONTAINER sh -c 'mysql -uroot --show-warnings $DB_SCHEMA < $CONTAINER_SEED_PATH'"
    exit 1
  fi

  step "Auditing seed (post-seed vs pre-seed)"

  tables_after_seed="$(list_tables)"
  triggers_after_seed="$(list_triggers)"
  views_after_seed="$(list_views)"

  new_tables=$(comm -13 <(printf '%s\n' "$tables_before_seed"    | sort) <(printf '%s\n' "$tables_after_seed"    | sort) | grep -v '^$' || true)
  new_triggers=$(comm -13 <(printf '%s\n' "$triggers_before_seed" | sort) <(printf '%s\n' "$triggers_after_seed" | sort) | grep -v '^$' || true)
  new_views=$(comm -13 <(printf '%s\n' "$views_before_seed"       | sort) <(printf '%s\n' "$views_after_seed"    | sort) | grep -v '^$' || true)

  if [ -z "$new_tables" ] && [ -z "$new_triggers" ] && [ -z "$new_views" ]; then
    ok "seed added no tables, triggers, or views — pure INSERT as intended"
  else
    warn "seed added the following objects (these belong in database.sql, not the seed):"
    if [ -n "$new_tables" ]; then
      warn "  tables:"
      printf '%s\n' "$new_tables" | sed 's/^/    /' >&2
    fi
    if [ -n "$new_triggers" ]; then
      warn "  triggers:"
      printf '%s\n' "$new_triggers" | sed 's/^/    /' >&2
    fi
    if [ -n "$new_views" ]; then
      warn "  views:"
      printf '%s\n' "$new_views" | sed 's/^/    /' >&2
    fi
    warn "Search $SEED_ABS for CREATE TABLE / CREATE TRIGGER / CREATE VIEW and remove them."
    warn "This run will still report success, but the schema is not in a canonical state."
  fi

  step "Checking seed produced data"

  for tbl in financial_account customer administrator restaurant delivery_rider product dietary_information ingredient; do
    rowcount=$(mysql_scalar "SELECT COUNT(*) FROM \`$DB_SCHEMA\`.\`$tbl\`")
    if [ "${rowcount:-0}" -gt 0 ]; then
      ok "  $tbl: ${rowcount} row(s)"
    else
      warn "  $tbl: 0 rows (seed may not have populated this table)"
    fi
  done

fi

# ---- phase D: start remaining services ------------------------------------

if [ "$MODE" = "all" ]; then
  step "Starting ${APP_SERVICES[*]}"
  info "compose up -d ${APP_SERVICES[*]}"
  if compose up -d "${APP_SERVICES[@]}" 2>&1 | sed 's/^/  /'; then
    ok "web and phpmyadmin started"
  else
    fail "could not start ${APP_SERVICES[*]}"
    exit 1
  fi
fi

# ---- success ---------------------------------------------------------------

step "Result"

ok "${C_BOLD}database reset complete${C_RESET}"
log ""
log "  http://localhost:8080/  (web)"
log "  http://localhost:8081/  (phpMyAdmin)"
log "  localhost:3306          (MySQL)"
log ""

if [ -n "${new_tables:-}" ] || [ -n "${new_triggers:-}" ] || [ -n "${new_views:-}" ]; then
  warn "Reminder: seed added DDL. Clean $SEED_ABS before the next reset."
fi

exit 0