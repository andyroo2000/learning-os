# Journal feedback quality

The journal has its own `JOURNAL_MODEL` and `JOURNAL_REASONING_EFFORT` settings.
Defaults are `gpt-6.1-sol` and `medium`; changing the shared content generator
does not change diary feedback. Requests remain stateless, with strict structured
output and server validation of original text and bracket readings.

Before changing the prompt/model, check representative entries for:

- Names and quoted titles preserved verbatim, including unfamiliar/fictional names.
- Grammatical errors corrected without treating casual phrasing as an error.
- Optional wording explained as a choice with a specific contextual benefit.
- Ambiguous subjects explicitly explained without inventing or removing events.
- Original segments reconstructing every character; complete furigana on Japanese
  in suggestions, explanations, questions, and corrected text.
- A meaningful English title without inferring an official translated name.
- Natural follow-up questions; no grammar drills or suggested answer patterns.
- Answers becoming standalone additions without duplicating the existing diary.

## November 12, 2026 comparison

Using the revised prompt and the same movie diary, with medium reasoning:

| Model | Elapsed | Result |
| --- | --- | --- |
| GPT-5.5 | 75 seconds | Request timed out |
| GPT-6.1 Sol | 30.6 seconds | Validated; title preserved, specific grammar explanations |
| GPT-6 Astra | 56.6 seconds | Validated; similar corrections and title preservation |

This is a small qualitative comparison, not a latency benchmark or a guarantee
of accuracy. Sol is the default based on the observed quality/time tradeoff.
Neither result establishes who drove: the explanation must flag that ambiguity.

The Sites prototype was used as a reference for useful explanations and restraint,
not as evidence of its underlying model or prompt. Its strengths include keeping
the writer's movie name, accepting casual language, and explaining word-choice
nuance. Tutor-style grammar practice from that prototype is intentionally outside
the diary feature.

Useful synthetic regression cases:

1. `「星のパン屋77」っていう映画を見た。めっちゃよかった！猫が月を食べる場面で笑った。`
   Preserve the fictional title and event; do not formalize casual language.
2. In response to a question about a favorite scene, `めちゃかっこいいので、ゴジラの喧嘩が一番良かったと思う。`
   Explain any battle/quarrel word-choice distinction; do not claim the grammar
   is inherently wrong or invent what happened in the movie.

Live evaluations use the configured provider and must never print API keys or
commit personal diary text. Fake-provider unit tests verify the request contract;
they do not establish linguistic quality.
