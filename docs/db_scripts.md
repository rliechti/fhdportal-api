# FEGA Database Scripts

Scripts for dumping, restoring, and synchronizing API databases.

---

## `db_dump.sh` — Dump

Produces a portable SQL file containing:

- Full schema (tables, views, functions, triggers, sequences, constraints)
- Reference data only — inserts are idempotent (`ON CONFLICT DO NOTHING`)
- No ownership or privilege statements — safe to restore under any database user

**Reference data tables included:**
`action_type`, `namespace`, `permission`, `predicate`, `relationship_rule`, `role`, `role_permission`, `resource_type`, `status_type`

All other tables are schema-only (no rows).

### Options

| Flag | Description | Default |
|------|-------------|---------|
| `-e ENV_FILE` | Load variables from a `.env`-style file | — |
| `-r SSH_HOST` | Run `pg_dump`/`psql` on this host via SSH | local execution |
| `-h HOST` | Database host (as seen from the execution machine) | `localhost` |
| `-p PORT` | Database port | `5432` |
| `-d DATABASE` | Database name (**required** if not set via env) | — |
| `-U USER` | Database user | current OS user |
| `-o OUTPUT_DIR` | Directory to write the dump file into | `.` (current dir) |
| `-f FILENAME` | Output filename | `fega_YYYYMMDD_HHMM.sql` |

### Environment variables

The following variables are read from the shell environment (or from an env file loaded via `-e`). `PG*` vars take priority; `POSTGRES_*` serve as fallback.

| Variable | Alias |
|----------|-------|
| `PGHOST` | `POSTGRES_HOST` |
| `PGPORT` | `POSTGRES_PORT` |
| `PGDATABASE` | `POSTGRES_DB` |
| `PGUSER` | `POSTGRES_USER` |
| `PGPASSWORD` | `POSTGRES_PASSWORD` |
| `SSH_HOST` | set via `-r` flag |

### SSH / remote mode

When `SSH_HOST` is set (via `-r` or env file), `pg_dump` and `psql` are executed **on the remote machine** over SSH. The database port never needs to be forwarded — only SSH access is required. The output streams back to your local machine.

This is the intended mode for dumping a remote database.

### Examples

```bash
# Dump remote database using tools/sync.env
./tools/db_dump.sh -e tools/sync.env -o data/db

# Dump a local database by flags
./tools/db_dump.sh -h localhost -p 5432 -d mydb -U myuser -o data/db

# Dump a remote database with an explicit SSH host and custom filename
./tools/db_dump.sh -r fega@dev -d fega_db -U fega -o data/db -f dev.sql
```

### Sample `tools/sync.env`

```dotenv
# SSH host (leave empty for local access)
SSH_HOST='fega@dev'

# Database credentials
POSTGRES_HOST='localhost'
POSTGRES_PORT=5432
POSTGRES_DB='fega_db'
POSTGRES_USER='fega'
POSTGRES_PASSWORD='...'
```

---

## `db_restore.sh` — Restore

Restores an FEGA dump into a target database. The restore strategy is:

1. Drop the `public` schema and all its objects (`CASCADE`)
2. Recreate an empty `public` schema
3. Apply the dump file

Because the dump contains no ownership statements, all objects are owned by whichever database user performs the restore.

### Options

| Flag | Description | Default |
|------|-------------|---------|
| `-e ENV_FILE` | Load variables from a `.env`-style file | — |
| `-h HOST` | Database host | `localhost` |
| `-p PORT` | Database port | `5432` |
| `-d DATABASE` | Database name (**required** if not set via env) | — |
| `-U USER` | Database user | current OS user |
| `-y` | Skip the confirmation prompt (non-interactive / CI mode) | interactive |

Accepts the same environment variables as `db_dump.sh` (`PGHOST`, `PGPASSWORD`, `POSTGRES_*`, etc.).

### Examples

```bash
# Interactive restore — prompts before wiping the schema
./tools/db_restore.sh -h localhost -p 5433 -U postgres -d fega data/db/fega_20260131_1245.sql

# Non-interactive restore (skip confirmation)
./tools/db_restore.sh -h localhost -p 5433 -U postgres -d fega -y data/db/fega_20260131_1245.sql

# Restore using an env file for credentials
./tools/db_restore.sh -e .env.local -y data/db/fega_20260131_1245.sql
```

### Restoring into a local Docker container

Run the restore script inside the `db` container:

```bash
# 1. Copy the dump and script into the container
docker compose cp data/db/fega_20260131_1245.sql db:/tmp/restore.sql
docker compose cp tools/db_restore.sh db:/tmp/db_restore.sh

# 2. Run the restore inside the container
docker compose exec db bash /tmp/db_restore.sh \
  -h localhost -p 5432 -U postgres -d fega -y /tmp/restore.sql
```

---

## `db_sync.sh` — Synchronize (dump + restore + custom SQL)

Orchestrates the complete workflow in a single command:

1. Dump the remote production database (calls `db_dump.sh` internally)
2. Copy the dump and restore script into the local Docker `db` container
3. Run `db_restore.sh` inside the container (drops & recreates the schema)
4. Apply `data/db/custom.sql` — skipped automatically if the file is absent or empty

### Options

| Flag | Description | Default |
|------|-------------|---------|
| `-e ENV_FILE` | `.env`-style file for remote DB / SSH credentials (passed to `db_dump.sh`) | — |
| `-s SERVICE` | Docker Compose service name | `db` |
| `-d DATABASE` | Local database name inside the container | `fega` |
| `-U USER` | Local database user | `postgres` |
| `-o OUTPUT_DIR` | Directory to write the dump file into | `data/db` |
| `-c CUSTOM_SQL` | Custom SQL script to apply after restore | `data/db/custom.sql` |
| `-y` | Skip the confirmation prompt | interactive |

### Custom SQL script (`custom.sql`)

`data/db/custom.sql` is a local-only script applied after every sync. Use it for anything that should exist in the development database but not in production — inserting test users or fixtures, updating rows, resetting sequences, toggling feature flags, etc. If the file is absent or empty the step is silently skipped.

### Examples

```bash
# Full sync using tools/sync.env for remote credentials
./tools/db_sync.sh -e tools/sync.env -y

# Full sync with a custom Docker service name and database
./tools/db_sync.sh -e tools/sync.env -s mydb -d myapp -y

# Full sync with a custom local SQL script
./tools/db_sync.sh -e tools/sync.env -i data/db/seed_dev.sql -y
```

---

## Typical workflow

```bash
# One-command sync from remote production to a local Docker container
./tools/db_sync.sh -e tools/sync.env -y
```

Or step by step:

```bash
# 1. Dump from remote production (creates data/db/fega_YYYYMMDD_HHMM.sql)
./tools/db_dump.sh -e tools/sync.env -o data/db

# 2. Copy into Docker and restore
docker compose cp data/db/fega_20260131_1245.sql db:/tmp/restore.sql
docker compose cp tools/db_restore.sh db:/tmp/db_restore.sh
docker compose exec db bash /tmp/db_restore.sh \
  -h localhost -p 5432 -U postgres -d fega -y /tmp/restore.sql

# 3. Apply custom SQL script (optional)
docker compose cp data/db/custom.sql db:/tmp/custom.sql
docker compose exec db psql -h localhost -p 5432 -U postgres -d fega \
  -v ON_ERROR_STOP=1 -f /tmp/custom.sql
```
