# Journal correction precision — November 23, 2026

A reported Android submission exposed separate client geometry/title defects and
feedback-quality issues. The private source and provider responses remain outside
Git. Evaluations sent stateless provider requests and did not alter the entry or
its persisted analysis.

## Method

Used the checked-in combined meaning-check prompt as baseline, the production
request schema and default `gpt-6.1-sol` model with medium reasoning, 16,000 output
tokens, and `store: false`. The reported diary mixes a past gathering, a recurring
weekly activity, and an unanchored eating sentence. Confirmed-answer trials used
an explicit English answer distinguishing last night from a weekly habit.

The original saved response bundled independent edits and referred to learner
confirmation despite no question being recorded for that submission. Three fresh
baseline runs all asked about the eating sentence's time, so this is not a
consistently reproducible meaning-check failure. The revised prompt explicitly
forbids invented confirmations and checks local tense context without treating
every tense shift as ambiguous.

## Observations

| Prompt / case | Observed result |
| --- | --- |
| Baseline, no answers (3 trials) | All asked about last night / habit / future eating |
| Baseline, last night confirmed (3) | All bundled the date/reference edits and the additive/tense edits |
| First revision, no answers (3) | All asked about eating time |
| First revision, last night confirmed (3) | All separated six local highlights; some spelling repairs added an unnecessary particle lesson |
| Selected revision, no answers (3) | All asked about eating time, with no premature feedback |
| Selected revision, last night confirmed (3) | All separated six local highlights, including independent additive and tense corrections |
| Selected revision, weekly habit confirmed (2) | Both preserved the eating tense; no repeated clarification |
| Selected revision, clear past / clear habit / casual prose (3) | All resolved without questions or corrections; confirmed meaning stayed empty |

The selected prompt produced one localized optional suggestion in each confirmed
trial, rather than bundling word order with vocabulary. Suggestions varied: some
used a more specific appearance verb and some adjusted the topic particle.
It also avoided combining an unnecessary optional embellishment with a spelling
repair in the three final past-event trials. This is small-sample regression
evidence, not a guarantee of identical or always-perfect future output.

All 11 selected-prompt responses passed `JournalCombinedResult::validated`,
including exact source reconstruction and complete bracket furigana. The full
polished output and readings use both correction and optional replacements.

## Applying naturalness suggestions

Newly validated editable feedback records `optionalAppliedByDefault: true`.
That affects the reconstructed corrected text and its initial suggestion states.
Older feedback without the marker retains its original default; explicitly saved
accept/reject choices and manual edits remain authoritative. This avoids changing
old documents silently or disagreeing with their already generated audio text.
No data migration or rewriting of previous analyses is needed.

The focused Journal suite passed 423 tests with three existing skips (2,013
assertions). Coverage includes new optional defaults, exact readings, reversible
toggles, persistence after rejecting a suggestion, legacy defaults, and audio
using the selected version. Formatting checks passed.
