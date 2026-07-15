# Deployment

Learning OS is published as a private-network Laravel API container. The image
is designed to run beside ConvoLab and its PostgreSQL service; it does not need
a public host or port during the feature-flagged migration.

## Image

The `Container` workflow builds and smoke-tests every runtime change against
PostgreSQL. A successful push to `main` publishes:

- `ghcr.io/andyroo2000/learning-os:main-<full commit sha>` for immutable deploys.
- `ghcr.io/andyroo2000/learning-os:latest` for convenience only.

Production deploys must use the immutable tag. The image listens on port 8080,
serves Laravel's `/up` health endpoint, writes application logs to stderr, and
runs as `www-data`.

## Runtime Contract

Required environment values:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:<32-byte Laravel application key>
APP_URL=http://learning-os:8080
LOG_CHANNEL=stderr
QUEUE_CONNECTION=sync
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=learning_os
DB_USERNAME=<postgres role>
DB_PASSWORD=<postgres password>
PGSQL_SSL_MODE=disable
CACHE_STORE=database
SESSION_DRIVER=database
```

`APP_KEY` must be generated once and retained across deploys. The initial
single-node rollout uses the migrated `cache`, `cache_locks`, and `sessions`
tables. Database-backed cache is required because Laravel's rate limiters must
persist across PHP request lifecycles. ConvoLab authenticates to Learning OS
with a Sanctum bearer token, and no queue worker is needed for the read-only
feature slice.

`QUEUE_CONNECTION=sync` prevents queued study-card-draft and import jobs from
accumulating without a worker during the initial rollout. Before enabling those
write features, deploy a separate `php artisan queue:work` service and switch
the connection to `database`.

Run migrations as a one-shot container before starting a new image:

```bash
docker run --rm --network <production-network> \
  --env-file <learning-os-env-file> \
  ghcr.io/andyroo2000/learning-os:<immutable-tag> \
  php artisan migrate --force
```

The initial database copy/import is a separate, explicit release step. Follow
`docs/database-rehearsal.md`, use `--skip-media`, and do not enable browser or
other media-dependent feature flags until media bytes and verified sizes have
their own migration.
