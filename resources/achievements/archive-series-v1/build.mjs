process.env.ACHIEVEMENT_CONTAINER_CONFIG = JSON.stringify({
    sourceRoot: 'resources/achievements/archive-series-v1/source', referenceSourcePath: 'resources/achievements/shared-frame-v1/source/original-style-reference.png',
    containerSourcePath: 'resources/achievements/shared-frame-v1/source/container-source.png', outputRevision: 'archive-series-v1',
    generatedBy: 'resources/achievements/archive-series-v1/build.mjs', familyKey: 'archive', apertureInsetRatio: 0.047, trimToContainerFrame: true,
    sheetTitle: 'THE ARCHIVE · NINE-TIER SYSTEM', sheetSubtitle: 'Lifetime burned cards · first shelf to endless library',
    seriesTitle: 'THE ARCHIVE BADGE SERIES', seriesSubtitle: '50 · 100 · 500 · 1K · 2K · 3K · 4K · 5K · 10K', containerLabel: 'UNIVERSAL BORDER',
    sheetFooter: 'Production PNGs contain no text; title and progress are rendered by the client UI.',
    tiers: [['first-shelf','First Shelf'],['bookcase','Bookcase'],['reading-room','Reading Room'],['library-wing','Library Wing'],['great-hall','Great Hall'],['deep-stacks','Deep Stacks'],['grand-archive','Grand Archive'],['city-of-books','City of Books'],['endless-library','Endless Library']]
        .map(([key,title]) => ({ key, title, sourceFilename: `${key}-complete-scene-source-v1.png`, interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 })),
});
await import('../shared-frame-v1/build.mjs');
