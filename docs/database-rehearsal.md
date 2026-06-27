# Local Database Rehearsal

Use this runbook to prove Learning OS against a disposable copy of the current
Convo Lab Postgres database before touching the live app or starting the
frontend cutover.

The rehearsal database is safe to drop and recreate. Do not point Learning OS at
the live Convo Lab database for this workflow.

## 1. Dump Convo Lab

Read Convo Lab's current `DATABASE_URL`, strip Prisma's `?schema=public` query
parameter for the Postgres CLI, and write a custom-format dump:

```bash
CONVOLAB_DIR=<path-to-convo-lab>
LEARNINGOS_DIR=<path-to-learning-os>
PG_BIN=${PG_BIN:-/opt/homebrew/opt/postgresql@15/bin}

cd "$CONVOLAB_DIR/server"
CONVOLAB_DATABASE_URL=$(sed -n 's/^DATABASE_URL=//p' .env | head -1 | sed 's/^"//; s/"$//')
CONVOLAB_PG_URL=${CONVOLAB_DATABASE_URL%%\?*}

mkdir -p "$LEARNINGOS_DIR/storage/app/rehearsals"
"$PG_BIN/pg_dump" --format=custom --no-owner --no-acl \
  --file="$LEARNINGOS_DIR/storage/app/rehearsals/convo-lab.dump" \
  "$CONVOLAB_PG_URL"
```

If your Postgres tools are already on `PATH`, set `PG_BIN=` or replace
`"$PG_BIN/pg_dump"` with `pg_dump`.

## 2. Restore Into A Disposable Database

Create a fresh local target and restore the dump into it:

```bash
LEARNINGOS_DIR=<path-to-learning-os>
PG_BIN=${PG_BIN:-/opt/homebrew/opt/postgresql@15/bin}
"$PG_BIN/dropdb" --if-exists learning_os_rehearsal
"$PG_BIN/createdb" learning_os_rehearsal

"$PG_BIN/pg_restore" --clean --if-exists --no-owner --no-acl \
  --dbname=learning_os_rehearsal \
  "$LEARNINGOS_DIR/storage/app/rehearsals/convo-lab.dump"
```

If the restore or later smoke check fails, drop and recreate
`learning_os_rehearsal`, then rerun the restore after patching Learning OS.

## 3. Point Learning OS At The Copy

Update `<path-to-learning-os>/.env` for the restored database:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=learning_os_rehearsal
DB_USERNAME=<local postgres user>
DB_PASSWORD=
PGSQL_SSL_MODE=disable
```

Then migrate the copied database forward:

```bash
cd <path-to-learning-os>
php artisan migrate --force
```

If migration fails because the restored Convo Lab schema already has tables but
does not have Laravel migration history, stop and treat that as real-data
compatibility work for the next PR. Do not manually mark migrations as run
unless a reviewed baseline step exists for the copied schema.

## 4. Run The Smoke Harness

Run the read-oriented API smoke check as a real user from the restored data:

```bash
php artisan rehearsal:smoke --user-email=<your-convo-lab-user@example.com>
```

The command checks:

- database connectivity
- migration table presence and pending migrations
- selected user lookup
- authenticated `GET /api/me`
- authenticated `GET /api/study/settings`
- authenticated `GET /api/study/overview`
- authenticated `GET /api/study/browser?limit=1`
- authenticated `GET /api/study/new-queue?limit=1`
- authenticated `GET /api/study/imports?per_page=1`
- authenticated `GET /api/study/imports/current`

For machine-readable output:

```bash
php artisan rehearsal:smoke --user-email=<your-convo-lab-user@example.com> --json
```

The smoke harness creates a short-lived Sanctum token for the selected user and
deletes it before exiting. Treat any failure as a compatibility issue to fix in
Learning OS, then recreate the rehearsal database and rerun the workflow.
