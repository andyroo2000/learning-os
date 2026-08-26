process.env.ACHIEVEMENT_CONTAINER_CONFIG = JSON.stringify({
    sourceRoot: 'resources/achievements/roarer-series-v7/source',
    referenceSourcePath: 'resources/achievements/shared-frame-v1/source/original-style-reference.png',
    containerSourcePath: 'resources/achievements/shared-frame-v1/source/container-source.png',
    outputRevision: 'roarer-series-v7',
    generatedBy: 'resources/achievements/roarer-series-v7/build.mjs',
    familyKey: 'roarer',
    apertureInsetRatio: 0.047,
    trimToContainerFrame: true,
    sheetTitle: 'ROARER · SEVEN-TIER SYSTEM',
    sheetSubtitle: 'Universal border + seven complete generated interior scenes',
    seriesTitle: 'ROARER BADGE SERIES',
    seriesSubtitle: 'Conversation milestones · one border · seven naturally integrated scenes',
    containerLabel: 'UNIVERSAL BORDER',
    sheetFooter: 'Production PNGs contain no text; title and progress are rendered by the client UI.',
    tiers: [
        { key: 'first-roar', title: 'First Roar', sourceFilename: 'first-roar-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'echo-call', title: 'Echo Call', sourceFilename: 'echo-call-complete-scene-source-v2.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'city-voice', title: 'City Voice', sourceFilename: 'city-voice-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'mountain-caller', title: 'Mountain Caller', sourceFilename: 'mountain-caller-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'thunder-voice', title: 'Thunder Voice', sourceFilename: 'thunder-voice-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'island-shaker', title: 'Island Shaker', sourceFilename: 'island-shaker-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'sky-splitter', title: 'Sky Splitter', sourceFilename: 'sky-splitter-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
    ],
});

await import('../shared-frame-v1/build.mjs');
