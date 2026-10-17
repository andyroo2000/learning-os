# ConvoLab CLI

Dependency-free Node 24+ client for Learning OS through the ConvoLab public API.
Install locally with `npm install --global ./tools/convolab`, or run
`node tools/convolab/cli.js help` directly. No application database access is used.

Set `CONVOLAB_PROFILE` to a mode-600 JSON file containing `baseUrl` (for example,
`https://convo-lab.com`) and `token` (a dedicated, revocable access token).
An explicit `--profile FILE` overrides the environment. Credentials are bound to
that origin; redirects are refused. Existing mobile tokens have broad account
permissions, not fine-grained agent scopes. Never commit the profile or pass its
contents in command arguments. Store it outside the checkout.

## Inspect learning evidence

```sh
convolab whoami
convolab overview
convolab search --q 'でも' --limit 20
convolab note NOTE_ID
convolab card CARD_ID
convolab reviews --card_id CARD_ID --per_page 100
convolab path CARD_ID
convolab drafts
convolab draft DRAFT_ID
```

Successful commands emit API JSON on stdout. Failures emit a JSON diagnostic on
stderr and exit nonzero, including HTTP status and Retry-After when available.
No automatic write retries are performed. Requests time out after 30 seconds.

Lists return **one page**. Preserve the search/filter arguments and pass the
returned cursor with `--cursor` until the API reports no next page. Search uses
`nextCursor`; review events use Laravel pagination metadata. A partial page must
not be treated as proof that a card or draft does not exist. Search is textual:
inspect matching meanings and review evidence before inferring mastery.

## Verification and rollout

Run `npm test --prefix tools/convolab`. Tests execute the real CLI against an
HTTP fixture and check authentication, pagination, failures, and redirects.
The following PRs add authoring/learning paths, then isolated Laravel integration
tests and deployed lifecycle checks with cleanup of explicitly recorded test IDs.
