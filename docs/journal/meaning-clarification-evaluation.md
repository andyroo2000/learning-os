# Meaning clarification evaluation — November 21, 2026

The meaning check must run before deciding that a grammatical sentence needs no
correction. The revised prompts distinguish a completed action, an additional
obligation, and an obligation to start now, then judge the original wording
against the learner's confirmed intent. The example in the shipped prompt uses
synthetic piano practice, not the learner's diary.

## Method

Repeated independent, stateless requests used the full latest reported entry,
with no answers on the first turn. Its text and provider responses remain private
and are not committed. The baseline prompt files matched production by SHA-256.
Requests used the production `CheckJournalMeaning` payload, combined schema,
`gpt-6.1-sol`, medium reasoning, 16,000 output tokens, and 75-second timeout.
Only the system prompt was replaced for candidates; responses went through
`JournalCombinedResult::validated`. Evaluations did not save or alter entries,
clarifications, feedback, or user-facing analysis state.

| Version | Asked about the target ambiguity | Trials |
| --- | ---: | ---: |
| Existing production prompt | 3 | 5 |
| First revision, same-topic example | 5 | 5 |
| Selected revision, synthetic piano example | 10 | 10 |

The two baseline misses resolved immediately. Its three questions offered
now/more/additional studying, but did not explicitly offer the completed-action
choice. Every selected-prompt question offered completed/additional/now choices.
The selected revision tied the first candidate's observed rate while succeeding
in more trials with an example outside the entry's topic. These small samples
are regression evidence, not a guarantee of future model behavior.

## Negative controls

Each synthetic clear entry was run three times. All 12 runs resolved without a
clarification and passed response validation:

- `日本語はもう勉強した。これから大学の宿題をする。`
- `大学の宿題がある。日本語も勉強しないと。`
- `もう夜の十時だ。明日の試験に間に合うように、今すぐ日本語を勉強しないと。`
- `「星のパン屋77」っていう映画を見た。めっちゃよかった！猫が月を食べる場面で笑った。`

## Confirmed meanings

The full private entry was tested three times with each explicit answer to the
clarification question. All nine requests resolved without repeating the
question, and all passed response validation. Manual review confirmed:

- Already completed: 3/3 corrected the obligation ending to a past action and
  marked it as an actual correction, with an explanation of the contrast.
- Additional obligation: 3/3 replaced the temporal wording with additive `も`,
  marked it as an actual correction, and explained the distinction.
- Need to start now: 3/3 preserved the target sentence without a false correction.

## Verification

The journal unit and feature suites passed with 394 passing tests and three
existing skips (1,740 assertions). The first local attempt hit PHP's default
128 MiB memory limit; rerunning PHPUnit with 512 MiB completed successfully.
`composer lint` and `git diff --check` passed. The shipped diff contains prompt
text and evaluation documentation only; Code Health source analysis does not
apply to those files. Fake-provider tests verify the surrounding contracts;
the repeated live evaluations above establish the observed prompt behavior.
