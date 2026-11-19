# Private media storage

Journal narration, tutor replies, timing JSON, Reading audio/illustrations, study
media, daily audio and generated content use the existing `local` and `media`
logical disks. Set `APP_MEDIA_DRIVER=gcs-private`, `APP_MEDIA_BUCKET` to a dedicated
private bucket, and `APP_MEDIA_PREFIX` to an environment-specific prefix. The
historical disk names and object paths stay unchanged, including database records.
Associated images/metadata move with their library, preventing split references.
Temporary assembly files and import archives remain local and are cleaned up by
the existing jobs. Do not point `APP_MEDIA_BUCKET` at the public static asset bucket.

Create the bucket with uniform bucket-level access and enforced public access
prevention. Grant the runtime service account object access on this bucket only.
The GCS adapter uses application default credentials from the existing runtime
secret mount, streams reads, and writes `private, no-store` cache metadata.
Journal playback stays behind the existing authenticated ownership-checked API;
there are no public Journal URLs. Existing web and iOS clients need no changes.

## Migration

1. Deploy support with `APP_MEDIA_DRIVER=local`. Configure the private bucket and
   prefix for **all three** API, worker, and scheduler services. Keep the same
   prefix across them and distinct prefixes for isolated test/stage environments.
2. Run `php artisan media:migrate-to-gcs`. This streams uploads and compares each
   object's size and MD5 checksum to the source. Existing mismatched objects stop
   the migration rather than being overwritten. Local files remain untouched.
3. Quiesce all producers: disable HTTP writes, stop the scheduler, and gracefully
   drain/stop the worker. Run migration again and then `--verify-only`. Do not
   leave old workers writing locally during cutover; do not use this command as
   an online mirror while deletes can occur.
4. Set `APP_MEDIA_DRIVER=gcs-private` and recreate all three services using the
   verified release image. Confirm authenticated playback of existing Journal,
   tutor, Reading, study, daily-audio and course files; verify anonymous and other
   accounts cannot read private recordings. Confirm new files are written to GCS.
5. Run `php artisan media:migrate-to-gcs --verify-only --delete-local` only after
   all old writers are stopped. Each source is deleted only after remote bytes
   match. Missing or conflicting remote files fail closed. Keep the migration
   output and deployment identity with the release record.

Before cutover, rollback is simply retaining `local`. After cutover, **never**
switch back to `local` without first stopping all writers and copying every cloud
object back, including recordings generated since cutover. Normal code rollback
must keep the cloud-capable storage adapter and GCS environment configuration.

The command is restartable. A failed upload does not delete its source. A remote
checksum conflict requires investigation; do not blindly overwrite it. Preserve
separate database backups: moving audio does not back up Journal text or accounts.

Public tool clips remain in the existing static GCS bucket. Legacy
`/tools-audio/*.mp3` URLs redirect through an allowlisted, rate-limited API endpoint
to a short-lived GCS URL. Verify/upload the checked-in clip inventory before
shipping a frontend image that omits those bundled files.
