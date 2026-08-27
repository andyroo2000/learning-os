process.env.ACHIEVEMENT_CONTAINER_CONFIG = JSON.stringify({
    sourceRoot: 'resources/achievements/on-a-roll-series-v1/source',
    referenceSourcePath: 'resources/achievements/shared-frame-v1/source/original-style-reference.png',
    containerSourcePath: 'resources/achievements/shared-frame-v1/source/container-source.png',
    outputRevision: 'on-a-roll-series-v1',
    generatedBy: 'resources/achievements/on-a-roll-series-v1/build.mjs',
    familyKey: 'on-a-roll', apertureInsetRatio: 0.047, trimToContainerFrame: true,
    sheetTitle: 'ON A ROLL · FIVE-TIER SYSTEM',
    sheetSubtitle: 'Consecutive correct answers · one unbroken card road',
    seriesTitle: 'ON A ROLL BADGE SERIES', seriesSubtitle: '10 · 25 · 50 · 75 · 100 consecutive correct answers',
    containerLabel: 'UNIVERSAL BORDER',
    sheetFooter: 'Production PNGs contain no text; title and progress are rendered by the client UI.',
    tiers: [['nice-run','Nice Run'],['locked-in','Locked In'],['hot-hand','Hot Hand'],['laser-focus','Laser Focus'],['century-run','Century Run']]
        .map(([key,title]) => ({ key, title, sourceFilename: `${key}-complete-scene-source-v1.png`, interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 })),
});
await import('../shared-frame-v1/build.mjs');
