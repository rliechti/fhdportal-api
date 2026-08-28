#!/usr/bin/env bash
# =============================================================================
# db_sync.sh — FEGA database synchronization script
#
# Orchestrates the complete workflow in one command:
#   1. Dump the remote production database
#   2. Copy the dump and restore script into the local Docker database container
#   3. Run db_restore.sh inside the container
#   4. Anonymise the restored copy (db_anonymise.sql) — NOT optional, and the
#      sync aborts if this step fails. A production dump carries researcher PII
#      and live DOA download credentials; neither belongs on a workstation.
#   5. Apply an optional local SQL script (data/db/custom.sql by default)
#      — can contain INSERTs, UPDATEs, sequence resets, or any custom SQL
#      — skipped automatically if the file is empty or does not exist
#
# Usage:
#   ./db_sync.sh [OPTIONS]
#
# Options:
#   -e ENV_FILE      .env-style file for remote DB / SSH credentials
#                    (passed through to db_dump.sh; required for remote access)
#   -s SERVICE       Docker Compose service name             (default: db)
#   -d DATABASE      Local database name inside the container (default: fega)
#   -U USER          Local database user                     (default: postgres)
#   -o OUTPUT_DIR    Directory to write the dump file into   (default: data/db)
#   -c CUSTOM_SQL    Custom SQL script to apply after the restore
#                    (default: data/db/custom.sql)
#   -y               Skip the confirmation prompt
#   --help           Show this help
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
usage() {
    sed -n '/^# Usage:/,/^# =/p' "$0" | sed 's/^# \?//'
    exit 0
}

die()  { echo "ERROR: $*" >&2; exit 1; }
log()  { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------
ENV_FILE=""
DOCKER_SERVICE="db"
LOCAL_DB="fega"
LOCAL_USER="postgres"
OUTPUT_DIR="data/db"
CUSTOM_SQL="data/db/custom.sql"
SKIP_CONFIRM=false

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
    case "$1" in
        -e) ENV_FILE="$2"; shift 2 ;;
        -s) DOCKER_SERVICE="$2"; shift 2 ;;
        -d) LOCAL_DB="$2"; shift 2 ;;
        -U) LOCAL_USER="$2"; shift 2 ;;
        -o) OUTPUT_DIR="$2"; shift 2 ;;
        -c) CUSTOM_SQL="$2"; shift 2 ;;
        -y) SKIP_CONFIRM=true; shift ;;
        --help) usage ;;
        *) die "Unknown option: $1. Use --help for usage." ;;
    esac
done

# ---------------------------------------------------------------------------
# Confirmation prompt
# ---------------------------------------------------------------------------
if [[ "$SKIP_CONFIRM" == false ]]; then
    echo ""
    echo "  ┌─────────────────────────────────────────────────────────────┐"
    echo "  │  This will:                                                 │"
    echo "  │   1. Dump the remote database                    │"
    echo "  │   2. Wipe and restore the local Docker database '$LOCAL_DB' │"
    printf "  │      in service: %-44s│\n" "'$DOCKER_SERVICE'"
    echo "  │   3. Apply custom SQL script if non-empty                           │"
    echo "  │  All existing local data will be permanently lost.          │"
    echo "  └─────────────────────────────────────────────────────────────┘"
    echo ""
    read -r -p "  Type 'yes' to continue: " CONFIRM
    [[ "$CONFIRM" == "yes" ]] || { echo "Aborted."; exit 0; }
    echo ""
fi

# ---------------------------------------------------------------------------
# Step 1: Dump remote database
# ---------------------------------------------------------------------------
log "Step 1/4: Dumping remote database …"

DUMP_ARGS=(-o "$OUTPUT_DIR")
[[ -n "$ENV_FILE" ]] && DUMP_ARGS+=(-e "$ENV_FILE")

bash "$SCRIPT_DIR/db_dump.sh" "${DUMP_ARGS[@]}"

# Identify the dump file that was just created (newest .sql in OUTPUT_DIR)
DUMP_FILE=$(find "$OUTPUT_DIR" -maxdepth 1 -name 'fega_*.sql' | sort -r | head -1)
[[ -n "$DUMP_FILE" ]] || die "No dump file found in $OUTPUT_DIR after dump step."
log "Dump file: $DUMP_FILE"

# ---------------------------------------------------------------------------
# Step 2: Copy dump and restore script into the Docker container
# ---------------------------------------------------------------------------
log "Step 2/4: Copying files into Docker service '$DOCKER_SERVICE' …"
docker compose cp "$DUMP_FILE" "$DOCKER_SERVICE":/tmp/restore.sql
docker compose cp "$SCRIPT_DIR/db_restore.sh" "$DOCKER_SERVICE":/tmp/db_restore.sh

# ---------------------------------------------------------------------------
# Step 3: Restore inside the container (non-interactive — confirmation was
#         already obtained above at the db_sync level)
# ---------------------------------------------------------------------------
log "Step 3/5: Restoring inside container …"
docker compose exec "$DOCKER_SERVICE" bash /tmp/db_restore.sh \
    -h localhost -p 5432 -U "$LOCAL_USER" -d "$LOCAL_DB" -y /tmp/restore.sql

# ---------------------------------------------------------------------------
# Step 4: Anonymise (mandatory — not skippable, and the sync aborts on failure)
# ---------------------------------------------------------------------------
log "Step 4/5: Anonymising restored data …"
ANONYMISE_SQL="$SCRIPT_DIR/db_anonymise.sql"
[[ -f "$ANONYMISE_SQL" ]] || die "Anonymisation script not found: $ANONYMISE_SQL. Refusing to leave a non-anonymised production copy on this machine."
docker compose cp "$ANONYMISE_SQL" "$DOCKER_SERVICE":/tmp/db_anonymise.sql
docker compose exec "$DOCKER_SERVICE" \
    psql -h localhost -p 5432 -U "$LOCAL_USER" -d "$LOCAL_DB" \
    -v ON_ERROR_STOP=1 -f /tmp/db_anonymise.sql
log "  Done."

# ---------------------------------------------------------------------------
# Step 5: Apply custom SQL script (if it exists and is non-empty)
# ---------------------------------------------------------------------------
log "Step 5/5: Applying custom SQL script …"

if [[ ! -f "$CUSTOM_SQL" ]]; then
    log "  $CUSTOM_SQL not found — skipping."
elif [[ ! -s "$CUSTOM_SQL" ]]; then
    log "  $CUSTOM_SQL is empty — skipping."
else
    log "  Applying $CUSTOM_SQL …"
    docker compose cp "$CUSTOM_SQL" "$DOCKER_SERVICE":/tmp/custom.sql
    docker compose exec "$DOCKER_SERVICE" \
        psql -h localhost -p 5432 -U "$LOCAL_USER" -d "$LOCAL_DB" \
        -v ON_ERROR_STOP=1 -f /tmp/custom.sql
    log "  Done."
fi

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
log "Sync complete. Local database '$LOCAL_DB' is ready."
