# FSRS Golden Fixture

`tests/Fixtures/fsrs-golden-v1.json` is the canonical cross-client contract for
ConvoLab review scheduling. It is pure test data: production code does not load
the fixture.

The fixture pins the deterministic FSRS-6 profile used by the API and offline
clients, standard scheduling vectors independently verified against
`ts-fsrs@5.3.3`, and the shared timestamp transport-normalization contract.
Consumers must:

- preserve the fixture byte-for-byte and verify the SHA-256 recorded in
  `tests/Fixtures/fsrs-golden-v1.sha256`;
- compare timestamps as instants, while emitting canonical UTC strings with
  exactly three fractional digits in fixture data;
- compare stability and difficulty with the declared absolute tolerance;
- compare counters, semantic strings, ordered field lists, and profile values
  exactly; and
- keep scheduling cases separate from transport-normalization cases, since the
  latter run before the API calls the scheduler.

The fixture intentionally omits a source commit because a file cannot contain
the SHA of the commit that will eventually contain that same file. Vendored
copies should record the canonical API merge commit alongside their local
fixture-hash assertion.
