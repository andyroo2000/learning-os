process.env.ACHIEVEMENT_CONTAINER_CONFIG = JSON.stringify({
    sourceRoot: 'resources/achievements/card-muncher-series-v1/source',
    referenceSourcePath: 'resources/achievements/shared-frame-v1/source/original-style-reference.png',
    containerSourcePath: 'resources/achievements/shared-frame-v1/source/container-source.png',
    outputRevision: 'card-muncher-series-v1',
    generatedBy: 'resources/achievements/card-muncher-series-v1/build.mjs',
    familyKey: 'card-muncher',
    apertureInsetRatio: 0.047,
    trimToContainerFrame: true,
    sheetTitle: 'CARD MUNCHER · SEVEN-TIER SYSTEM',
    sheetSubtitle: 'Universal border + seven complete generated interior scenes',
    seriesTitle: 'CARD MUNCHER BADGE SERIES',
    seriesSubtitle: 'Review milestones · naturally integrated snacks, feasts, conveyor belts, and a card moon',
    containerLabel: 'UNIVERSAL BORDER',
    sheetFooter: 'Production PNGs contain no text; title and progress are rendered by the client UI.',
    tiers: [
        { key: 'first-nibble', title: 'First Nibble', sourceFilename: 'first-nibble-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'quick-bite', title: 'Quick Bite', sourceFilename: 'quick-bite-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'bento-break', title: 'Bento Break', sourceFilename: 'bento-break-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'full-feast', title: 'Full Feast', sourceFilename: 'full-feast-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'banquet-beast', title: 'Banquet Beast', sourceFilename: 'banquet-beast-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'bottomless', title: 'Bottomless', sourceFilename: 'bottomless-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'moon-muncher', title: 'Moon Muncher', sourceFilename: 'moon-muncher-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
    ],
});

await import('../shared-frame-v1/build.mjs');
