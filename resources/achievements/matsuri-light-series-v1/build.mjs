process.env.ACHIEVEMENT_CONTAINER_CONFIG = JSON.stringify({
    sourceRoot: 'resources/achievements/matsuri-light-series-v1/source',
    referenceSourcePath: 'resources/achievements/shared-frame-v1/source/original-style-reference.png',
    containerSourcePath: 'resources/achievements/shared-frame-v1/source/container-source.png',
    outputRevision: 'matsuri-light-series-v1',
    generatedBy: 'resources/achievements/matsuri-light-series-v1/build.mjs',
    familyKey: 'yearfire',
    apertureInsetRatio: 0.047,
    trimToContainerFrame: true,
    sheetTitle: 'MATSURI LIGHT · SEVEN-TIER SYSTEM',
    sheetSubtitle: 'Universal border + seven complete generated interior scenes',
    seriesTitle: 'MATSURI LIGHT BADGE SERIES',
    seriesSubtitle: 'One-year memory stability · naturally integrated ember, lantern festival, and sunrise scenes',
    containerLabel: 'UNIVERSAL BORDER',
    sheetFooter: 'Production PNGs contain no text; title and progress are rendered by the client UI.',
    tiers: [
        { key: 'first-ember', title: 'First Ember', sourceFilename: 'first-ember-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'paper-glow', title: 'Paper Glow', sourceFilename: 'paper-glow-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'lantern-heart', title: 'Lantern Heart', sourceFilename: 'lantern-heart-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'festival-gate', title: 'Festival Gate', sourceFilename: 'festival-gate-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'night-parade', title: 'Night Parade', sourceFilename: 'night-parade-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'sky-of-lanterns', title: 'Sky of Lanterns', sourceFilename: 'sky-of-lanterns-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
        { key: 'eternal-sunrise', title: 'Eternal Sunrise', sourceFilename: 'eternal-sunrise-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 },
    ],
});

await import('../shared-frame-v1/build.mjs');
