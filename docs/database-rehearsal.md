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

## 2. Restore Into A Disposable Source Database

Create a fresh local source copy and restore the dump into it:

```bash
LEARNINGOS_DIR=<path-to-learning-os>
PG_BIN=${PG_BIN:-/opt/homebrew/opt/postgresql@15/bin}
"$PG_BIN/dropdb" --if-exists learning_os_convolab_source
"$PG_BIN/createdb" learning_os_convolab_source

"$PG_BIN/pg_restore" --clean --if-exists --no-owner --no-acl \
  --dbname=learning_os_convolab_source \
  "$LEARNINGOS_DIR/storage/app/rehearsals/convo-lab.dump"
```

If the restore or later smoke check fails, drop and recreate
`learning_os_convolab_source`, then rerun the restore after patching Learning
OS.

## 3. Migrate A Fresh Learning OS Target

Create a separate disposable target database and point Learning OS at it. Keep
the restored Convo Lab source database unchanged so the import can be rerun:

```bash
PG_BIN=${PG_BIN:-/opt/homebrew/opt/postgresql@15/bin}
"$PG_BIN/dropdb" --if-exists learning_os_rehearsal
"$PG_BIN/createdb" learning_os_rehearsal
```

Update `<path-to-learning-os>/.env` for the Learning OS target:

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

Do not run Learning OS migrations directly against the restored Convo Lab
source copy. Convo Lab's schema uses string user IDs, camelCase study columns,
and legacy table names that are not Laravel's canonical schema.

## 4. Import Convo Lab Study Data

Import the restored source copy into the migrated Learning OS target:

```bash
php artisan rehearsal:import-convolab \
  --source-database=learning_os_convolab_source \
  --truncate
```

The importer is rehearsal-only and expects a disposable Learning OS target. It
maps Convo Lab string user IDs to Learning OS integer users, creates canonical
study decks/cards/media/review rows, and reuses duplicate media storage paths
when Convo Lab has several media records for the same object.

Production use requires both `--allow-production` and a target-specific typed
confirmation. For a disposable production target named `learning_os`, append:

```bash
--allow-production \
--skip-media \
--production-truncate-confirmation="TRUNCATE learning_os"
```

The command rejects the confirmation when it does not exactly name the active
target database. `--skip-media` is also mandatory when source media exists:
Convo Lab does not persist byte sizes, and Learning OS must not expose
zero-byte placeholder assets through its offline media manifests. Migrate media
bytes and verified sizes in a separate step before enabling media-dependent API
features.

## 5. Import Verified Media Bytes

Export Convo Lab's GCS study media into a local directory while preserving each
object's `study-media/...` path. For example, when the bucket contains that
prefix:

```bash
LEARNINGOS_DIR=<path-to-learning-os>
CONVOLAB_MEDIA_BUCKET=gs://<convo-lab-media-bucket>
CONVOLAB_MEDIA_ROOT="$LEARNINGOS_DIR/storage/app/rehearsals/convo-lab-media"

rm -rf "$CONVOLAB_MEDIA_ROOT"
mkdir -p "$CONVOLAB_MEDIA_ROOT"
gcloud storage cp --recursive \
  "$CONVOLAB_MEDIA_BUCKET/study-media" \
  "$CONVOLAB_MEDIA_ROOT"
```

Check that the resulting files are rooted at
`$CONVOLAB_MEDIA_ROOT/study-media/...`, matching `study_media.storagePath` in
the restored source database. Then import and verify the bytes:

```bash
php artisan migration:import-convolab-media \
  --source-database=learning_os_convolab_source \
  --source-media-root="$CONVOLAB_MEDIA_ROOT"
```

The command imports every Convo Lab `study_media` row, including assets that
are not currently attached to a card, so the cutover does not discard import
provenance or temporarily unlinked files. For linked media, the target card
must exist and remain active; the command never invents a link for unlinked
media.

Before writing anything, it preflights every source file, path, owner, linked
target card, import job, byte size, SHA-256 checksum, and existing target file.
It rejects path traversal, cross-user links, conflicting bytes, and metadata
that cannot fit the Postgres target columns.

The import is idempotent. A successful retry reuses matching media rows, files,
and card links. If the database transaction fails, the command rolls it back
and removes files created by that attempt; files that existed before the
attempt are left untouched. A target-database lock also prevents concurrent
import commands from racing over the same files. The command pins this lock to
Laravel's shared database cache store even if the app's default cache store is
configured differently.

For the production Learning OS target, add both safeguards:

```bash
--allow-production \
--production-confirmation="IMPORT MEDIA INTO learning_os"
```

The confirmation must exactly name the active target database. Always run this
against the restored Convo Lab source copy, never the live Convo Lab database.

### Import Historical Daily Audio

Daily Audio files use GCS object paths rooted at
`daily-audio-practice/...`, while Learning OS serves them from canonical paths
rooted at `daily-audio/...`. Export the source objects into the same media root:

```bash
gcloud storage cp --recursive \
  "$CONVOLAB_MEDIA_BUCKET/daily-audio-practice" \
  "$CONVOLAB_MEDIA_ROOT"
```

Then verify and import the historical tracks:

```bash
php artisan migration:import-convolab-daily-audio \
  --source-database=learning_os_convolab_source \
  --source-media-root="$CONVOLAB_MEDIA_ROOT" \
  --source-bucket=<convo-lab-media-bucket>
```

The command accepts only ready source tracks with strict
`https://storage.googleapis.com/<bucket>/daily-audio-practice/...` URLs. It
checks the source practice relationship, matching target track ID, practice,
mode and status, plus source and destination byte checksums. After every track
passes preflight, it copies missing files and rewrites the target rows to
Learning OS's authenticated audio route under a database transaction and row
locks.

Retries reuse byte-identical files. Conflicting files, missing source objects,
dangling records, unmatched legacy target tracks, and non-GCS URLs fail the
whole import. Files created by an attempt are removed if its database
transaction fails.

For production, add both safeguards:

```bash
--allow-production \
--production-confirmation="IMPORT DAILY AUDIO INTO learning_os"
```

## 6. Import Episode Read Data

After the base rehearsal import has created Learning OS users, copy the Episode
read graph into its isolated compatibility tables:

```bash
php artisan content:import-convolab-episodes \
  --source-database=learning_os_convolab_source
```

The command maps Convo Lab users to existing Learning OS users by normalized
email, preserves source Episode UUIDs, and imports dialogues, speakers,
sentences, images, audio scripts, segment image metadata, renders, and course
links. It refuses to run when any target compatibility table is non-empty. To
replace a previous rehearsal import, add `--truncate`.

The source and target databases must differ. In production, replacement also
requires both safeguards:

```bash
--allow-production \
--truncate \
--production-truncate-confirmation="TRUNCATE <learning-os-database-name>"
```

## 7. Run The Smoke Harness

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
- authenticated `GET /api/convolab/episodes?library=true&limit=1`

For machine-readable output:

```bash
php artisan rehearsal:smoke --user-email=<your-convo-lab-user@example.com> --json
```

The command refuses to run when `APP_ENV=production` unless
`--allow-production` is passed. Use that override only after confirming the app
is pointed at the restored Learning OS database, not the live Convo Lab source
database.

The smoke harness creates a short-lived Sanctum token for the selected user and
deletes it before exiting. Treat any failure as a compatibility issue to fix in
Learning OS, then recreate the rehearsal database and rerun the workflow.
