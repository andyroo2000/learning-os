# Automatic speaking directions

New Fish Audio requests on Drama 3 analyze the utterance before synthesis and, when justified, prepend a short delivery direction. Existing recordings and saved card text are unchanged. This works for card audio, draft previews, daily audio, and content narration through the shared speech provider.

The classifier defaults to neutral for factual statements, isolated vocabulary, quotations, and ambiguous examples. It can choose warm, excited, nervous, apologetic, reassuring, curious, serious, sad, or whispering delivery. Each choice maps to fixed, restrained direction text; generated output cannot rewrite the utterance or introduce arbitrary performance tags. Explicit square-bracket or angle-bracket directions take priority and bypass automatic analysis. Scripts over 2,000 characters also bypass analysis to avoid applying a single mood to a long passage.

Analysis uses the existing study-card OpenAI model with strict structured output, low reasoning, a 12-second timeout, and response storage disabled. Results are cached for one day by exact text, model, and prompt version. Provider or cache failures leave speech usable without a direction. No card schema migration or client update is needed. Set `FISH_AUDIO_AUTOMATIC_DIRECTIONS=false` to disable; other Fish model selections bypass the feature automatically.

Examples from a live check:

| Utterance | Delivery |
| --- | --- |
| 図書館は駅の隣です。 | Neutral |
| やった！試験に合格しました！ | Excited |
| 遅れてしまって、本当にすみません。 | Apologetic |
| 大丈夫ですよ。ゆっくりでいいですから。 | Reassuring |
| 赤ちゃんが寝ているから、静かに話そう。 | Whispering |
| 明日の面接、うまく話せるか心配です。 | Nervous |

Fish's [Drama 3 documentation](https://docs.fish.audio/features/text-to-speech/drama-3) describes bracketed plain-language directions. The classifier uses OpenAI's [structured output format](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses).
