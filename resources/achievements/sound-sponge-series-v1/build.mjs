process.env.ACHIEVEMENT_CONTAINER_CONFIG = JSON.stringify({
    sourceRoot: 'resources/achievements/sound-sponge-series-v1/source',
    referenceSourcePath: 'resources/achievements/shared-frame-v1/source/original-style-reference.png',
    containerSourcePath: 'resources/achievements/shared-frame-v1/source/container-source.png',
    outputRevision: 'sound-sponge-series-v1',
    generatedBy: 'resources/achievements/sound-sponge-series-v1/build.mjs',
    familyKey: 'sound-sponge',
    apertureInsetRatio: 0.047,
    trimToContainerFrame: true,
    sheetTitle: 'SOUND SPONGE · SEVEN-TIER SYSTEM',
    sheetSubtitle: 'Daily Audio listening milestones · one complete scene per tier',
    seriesTitle: 'SOUND SPONGE BADGE SERIES',
    seriesSubtitle: 'From a first echo to an ocean of sound',
    containerLabel: 'UNIVERSAL BORDER',
    sheetFooter: 'Production PNGs contain no text; title and progress are rendered by the client UI.',
    tiers: [
        ['first-echo', 'First Echo'], ['sound-snack', 'Sound Snack'], ['all-ears', 'All Ears'],
        ['deep-listener', 'Deep Listener'], ['golden-ears', 'Golden Ears'], ['sound-sponge', 'Sound Sponge'],
        ['ocean-of-sound', 'Ocean of Sound'],
    ].map(([key, title]) => ({ key, title, sourceFilename: `${key}-complete-scene-source-v1.png`, interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 })),
});

await import('../shared-frame-v1/build.mjs');
