# Feature audiences and access groups

The global switches remain authoritative for everyone: On enables a feature for all users; Off enables it only for directly selected users and members of selected groups. Admins retain access. Journal is always available. Removing a direct grant does not revoke access inherited from a group, and group membership edits immediately affect every feature using the group.

## Admin API

The first-party admin browser session is required for `/api/convolab/admin/feature-access`:

- `GET /users`: paginated name/email/display-name search (`search`, `role`, `groupId`, `page`), 25 results per page.
- `GET /groups`, `POST /groups`, `PUT /groups/{uuid}`: list, create, or replace a group's name and membership.
- `GET /audiences/{feature}`, `PUT /audiences/{feature}`: retrieve or replace direct user grants and selected groups.

Feature keys are `learnEnabled`, `flashcardsEnabled`, `readingsEnabled`, and `studyTimeEnabled`. User IDs are canonical database user IDs serialized as decimal strings; group IDs are UUIDs. Never use ConvoLab projection IDs as canonical user IDs.

Writes include `version`, `userIds`, and either `name` for groups or `groupIds` for audiences. New groups use version zero. Arrays are limited to 1,000 members/groups per write. Validation rejects unknown IDs, malformed lists, and duplicates. Reference-row locks protect against concurrent deletion. Owner locks and optimistic versions prevent lost updates; a stale version returns 409 without applying the draft. Group creation is a separate saved operation; it does not enable a feature until the audience is saved.

`GET /api/convolab/feature-availability` returns uncached effective flags for the authenticated user. Learn middleware uses the same resolver. `/api/feature-flags` continues to represent global settings for compatibility.

## Search implementation

`AudienceUserSearch` is the provider boundary, bound to `DatabaseAudienceUserSearch` in `AppServiceProvider`. Database search uses parameterized, case-insensitive LIKE with escaped wildcards and stable name/user-ID pagination. Search and group serialization batch membership reads.

To introduce OpenSearch, implement the same contract and change the container binding. Keep query/filter validation, response shape, canonical IDs, and membership writes unchanged. Hydrate and validate indexed results against current database users; the index must not become the source of truth for authorization or group membership. Database foreign keys and the effective-access resolver remain authoritative.

## Rollout

Deploy backend migrations and API before the frontend. The migrations add independent tables and preserve existing global values. Rollback removes audience/group grants only. Backend CI covers SQLite behavior and PostgreSQL migrations; schema-generation tests additionally exercise MySQL. Frontend deployment uses the existing staging and production promotion workflows.
