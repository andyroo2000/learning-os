# Compatibility Wire Fixtures

Learning OS is the sole authority for cross-client wire fixtures. The versioned
manifest at `tests/Fixtures/Compatibility/manifest-v1.json` registers each
fixture, its real Laravel Action or Resource producer, and its SHA-256 digest.
Fixtures are test data only; production runtime code must not load them.

Every fixture change must:

- keep deterministic identifiers, timestamps, ordering, and numeric types;
- include meaningful default/null and explicit edge variants;
- compare the checked-in payload with output from the registered producer;
- update the fixture checksum and manifest checksum; and
- land in Learning OS before downstream Swift, TypeScript, or PHP consumers
  vendor the exact bytes.

Downstream copies should verify the fixture checksum and record the Learning OS
merge commit alongside their local contract test. They must not publish a
competing canonical fixture or silently edit vendored payloads.
