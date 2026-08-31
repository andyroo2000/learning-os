# Study card presentation v1

`StudyCardSummaryResource` includes an additive `presentation` object for review UI rendering. The API owns this projection so web, iOS, and future clients do not independently interpret the editable `prompt` and `answer` documents.

The raw documents remain in the response and remain the write contract for card editors. Released clients may continue using them.

## Versioning

`presentation.version` is `1`. Clients should decode versions they support and fall back to the raw documents for an unknown future version. New optional fields may be added within a version; incompatible semantic or structural changes require a new version.

## Front face

- `mode` is `text`, `media`, or `cloze`.
- `text` is normalized display text with HTML removed and entities decoded.
- `ruby` is bracket-furigana text only when it aligns with `text`; clients render `text` when it is null.
- `hint` is the normalized cue meaning or cloze hint. Media-led production cards expose only the established Japanese visual-part-of-speech labels.
- `media.audio` and `media.image` are the stored prompt media references. Their shape is the same as media references in the raw documents.
- `autoplayAudio` is true only for audio-led recognition prompts.

Cloze derivation masks every active `c1` span, preserves other ordinals, accepts the legacy loose-bracket form without treating Japanese bracket furigana as a blank, and never uses resolved legacy text as an unmasked prompt.

## Answer face

- `heading` is the normalized primary answer text; `ruby` is its aligned bracket-furigana form.
- `restored` contains the revealed cloze/restored text when present.
- `meaning`, Japanese/English `sentences`, and `notes` are normalized display values. Notes are a deterministic list with HTML block boundaries and common bullet prefixes removed.
- `media.image` prefers the answer image and falls back to the prompt image, matching the current review layout.
- `audio` follows the existing one-logical-card-audio rule: prompt audio wins, with answer audio as the fallback. This preserves older listening cards whose audio is stored only on the prompt.
- `pitchAccent` contains a normalized resolved pitch-accent payload. Unresolved or malformed payloads remain available in raw `answer.pitchAccent` but appear as null in the rendering projection.

Every documented key is present. Missing content is represented by null, an empty list, or a nested object containing null fields, as shown in `tests/Fixtures/Compatibility/study-card-summary-v1.json`.
