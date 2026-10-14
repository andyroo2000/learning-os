# Study voice rotation

New Japanese card audio uses the 19 approved Fish Audio voices in `StudyCardGenerationDefaults::VOICE_IDS`. The additive `cardAuthoring.defaultAnswerAudioVoiceIds` capability exposes this pool to web and iOS; the two legacy default fields remain for older clients.

Manual creation samples a voice for each new draft. Generated vocabulary bundles, individual AI drafts, preview audio without a selected voice, and missing card audio use the same pool. Preview and card audio save their selected voice alongside the generated media under the existing revision/concurrency guards. Explicit saved voices and imported recordings remain authoritative.

New Daily Audio drill, dialogue, story, and fallback scripts assign Japanese lines a shuffled 19-voice cycle. Every Japanese occurrence advances the cycle, including repeated drill lines. English narration and timing/text/reading data stay unchanged. Assignment happens once when a generation result is built, so persisted scripts and synthesized audio agree. Existing completed tracks are not regenerated automatically.

The iOS capability model samples the advertised pool when callers ask for a draft's default voice, uses the local 19-voice catalog before capabilities load, and retains compatibility with servers that only expose the legacy default.
