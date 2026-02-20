#!/usr/bin/env bash
# =============================================================================
# db_dump.sh — FEGA database dump script
#
# Generates a portable, user-agnostic SQL dump containing:
#   • Full schema (tables, views, functions, triggers, sequences, constraints)
#   • Reference data only (lookup tables needed to bootstrap the API)
#   • No ownership or privilege statements (safe to restore under any database user)
#
# Modes:
#   Local  — pg_dump/psql run on this machine (default).
#   Remote — pg_dump/psql run on SSH_HOST via SSH; output streams back locally.
#            The database port never needs to be reachable from outside.
#
# Usage:
#   ./db_dump.sh [OPTIONS]
#
# Options (all optional — fall back to env vars, then built-in defaults):
#   -e ENV_FILE    Load variables from a .env-style file
#   -r SSH_HOST    Remote host for SSH execution
#                  Overrides SSH_HOST from the env file
#   -h HOST        DB host as seen from the execution machine (default: localhost)
#   -p PORT        DB port                                    (default: 5432)
#   -d DATABASE    DB name (required)
#   -U USER        DB user
#   -o OUTPUT_DIR  Output directory                           (default: .)
#   -f FILENAME    Output filename  (default: fega_YYYYMMDD_HHMM.sql)
#   --help         Show this help
# =============================================================================
set -euo pipefail

# ---------------------------------------------------------------------------
# Reference data tables — ORDER MATTERS: dependencies must come first.
# ---------------------------------------------------------------------------
REFERENCE_DATA_TABLES=(
    action_type
    namespace
    permission
    role
    status_type
    predicate
    resource_type
    role_permission
    relationship_rule
)

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
usage() {
    sed -n '/^# Usage:/,/^# =/p' "$0" | sed 's/^# \?//'
    exit 0
}

die() { echo "ERROR: $*" >&2; exit 1; }
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# ---------------------------------------------------------------------------
# Execute a PostgreSQL tool (pg_dump / psql) either locally or via SSH.
# In remote mode the command runs on $SSH_HOST; stdout flows to the caller.
# ---------------------------------------------------------------------------
pg_exec() {
    local pgpassword="${PGPASSWORD:-}"
    if [[ -n "${SSH_HOST:-}" ]]; then
        local remote_cmd
        remote_cmd="PGPASSWORD=$(printf '%q' "$pgpassword")"
        local arg
        for arg in "$@"; do
            remote_cmd+=" $(printf '%q' "$arg")"
        done
        # SC2029: expansion on the client side is intentional — $remote_cmd is
        # a pre-built shell command string meant to be run by the remote shell.
        # shellcheck disable=SC2029
        ssh "$SSH_HOST" "$remote_cmd"
    else
        PGPASSWORD="$pgpassword" "$@"
    fi
}

# ---------------------------------------------------------------------------
# Argument parsing — pass 1: locate -e so the env file is loaded first
# ---------------------------------------------------------------------------
ENV_FILE=""
for ((i=1; i<=$#; i++)); do
    if [[ "${!i}" == "-e" ]]; then
        j=$((i+1))
        ENV_FILE="${!j}"
        break
    fi
done

if [[ -n "$ENV_FILE" ]]; then
    [[ -f "$ENV_FILE" ]] || die "Env file not found: $ENV_FILE"
    log "Loading env file: $ENV_FILE"
    set -a
    # shellcheck disable=SC1090
    source "$ENV_FILE"
    set +a
fi

# Map POSTGRES_* aliases → PG* so standard Postgres tools pick them up.
# Explicit PG* vars take priority; POSTGRES_* serve as fallback.
export PGHOST="${PGHOST:-${POSTGRES_HOST:-localhost}}"
export PGPORT="${PGPORT:-${POSTGRES_PORT:-5432}}"
export PGDATABASE="${PGDATABASE:-${POSTGRES_DB:-}}"
export PGUSER="${PGUSER:-${POSTGRES_USER:-}}"
export PGPASSWORD="${PGPASSWORD:-${POSTGRES_PASSWORD:-}}"

# ---------------------------------------------------------------------------
# Argument parsing — pass 2: all flags (override env / env-file values)
# ---------------------------------------------------------------------------
SSH_HOST="${SSH_HOST:-}"
DB_HOST="$PGHOST"
DB_PORT="$PGPORT"
DB_NAME="$PGDATABASE"
DB_USER="$PGUSER"
OUTPUT_DIR="."
OUTPUT_FILE=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        -e) shift 2 ;;
        -r) SSH_HOST="$2"; shift 2 ;;
        -h) DB_HOST="$2"; shift 2 ;;
        -p) DB_PORT="$2"; shift 2 ;;
        -d) DB_NAME="$2"; shift 2 ;;
        -U) DB_USER="$2"; shift 2 ;;
        -o) OUTPUT_DIR="$2"; shift 2 ;;
        -f) OUTPUT_FILE="$2"; shift 2 ;;
        --help) usage ;;
        *) die "Unknown option: $1. Use --help for usage." ;;
    esac
done

[[ -z "$DB_NAME" ]] && die "Database name is required. Use -d DATABASE or set PGDATABASE / POSTGRES_DB."

# ---------------------------------------------------------------------------
# Build connection argument arrays
# ---------------------------------------------------------------------------
PG_CONN_ARGS=(-h "$DB_HOST" -p "$DB_PORT")
[[ -n "$DB_USER" ]] && PG_CONN_ARGS+=(-U "$DB_USER")

PG_DUMP_ARGS=(
    "${PG_CONN_ARGS[@]}"
    --no-owner          # strip OWNER TO
    --no-privileges     # strip GRANT/REVOKE
    --no-comments       # keep output clean
)

# ---------------------------------------------------------------------------
# Resolve output path
# ---------------------------------------------------------------------------
mkdir -p "$OUTPUT_DIR"
if [[ -z "$OUTPUT_FILE" ]]; then
    OUTPUT_FILE="fega_$(date '+%Y%m%d_%H%M').sql"
fi
OUTPUT_PATH="$OUTPUT_DIR/$OUTPUT_FILE"

if [[ -n "$SSH_HOST" ]]; then
    log "Mode: remote SSH execution on $SSH_HOST"
fi
log "Dumping database '$DB_NAME' on $DB_HOST:$DB_PORT → $OUTPUT_PATH"

# ---------------------------------------------------------------------------
# Step 1: Full schema dump (without data)
#
# Filter lines that start with a backslash-letter sequence (psql meta-commands
# such as \restrict that may be injected by SSH banners / remote shell init
# files) so the dump file contains only valid SQL.
# ---------------------------------------------------------------------------
log "Step 1/2: Dumping schema …"
pg_exec pg_dump \
    "${PG_DUMP_ARGS[@]}" \
    --schema-only \
    "$DB_NAME" \
| grep -v '^\\[a-zA-Z]' \
> "$OUTPUT_PATH"

# ---------------------------------------------------------------------------
# Step 2: Reference data — one INSERT per row with ON CONFLICT DO NOTHING
#
# --column-inserts names columns explicitly (safe against column reordering).
# ---------------------------------------------------------------------------
log "Step 2/2: Dumping essential reference data …"

{
    echo ""
    echo "-- Essential reference data"
    echo ""
} >> "$OUTPUT_PATH"

for TABLE in "${REFERENCE_DATA_TABLES[@]}"; do
    log "  → $TABLE"
    TABLE_DATA=$(pg_exec pg_dump \
        "${PG_DUMP_ARGS[@]}" \
        --data-only \
        --column-inserts \
        --table="public.$TABLE" \
        "$DB_NAME" \
    | grep -E '^INSERT INTO' \
    | sed 's/;$/ ON CONFLICT DO NOTHING;/')

    {
        echo "-- $TABLE"
        echo "$TABLE_DATA"
        echo ""
    } >> "$OUTPUT_PATH"
done

{
    echo "-- End of dump"
} >> "$OUTPUT_PATH"

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
DUMP_SIZE=$(du -sh "$OUTPUT_PATH" | cut -f1)
log "Done. File: $OUTPUT_PATH ($DUMP_SIZE)"
