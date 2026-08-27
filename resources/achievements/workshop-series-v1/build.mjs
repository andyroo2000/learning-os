process.env.ACHIEVEMENT_CONTAINER_CONFIG = JSON.stringify({
    sourceRoot: 'resources/achievements/workshop-series-v1/source', referenceSourcePath: 'resources/achievements/shared-frame-v1/source/original-style-reference.png',
    containerSourcePath: 'resources/achievements/shared-frame-v1/source/container-source.png', outputRevision: 'workshop-series-v1',
    generatedBy: 'resources/achievements/workshop-series-v1/build.mjs', familyKey: 'workshop', apertureInsetRatio: 0.047, trimToContainerFrame: true,
    sheetTitle: 'THE WORKSHOP · NINE-TIER SYSTEM', sheetSubtitle: 'Lifetime cards reaching Master · first finish to a city of makers',
    seriesTitle: 'THE WORKSHOP BADGE SERIES', seriesSubtitle: '50 · 100 · 500 · 1K · 2K · 3K · 4K · 5K · 10K', containerLabel: 'UNIVERSAL BORDER',
    sheetFooter: 'Production PNGs contain no text; title and progress are rendered by the client UI.',
    tiers: [['first-finish','First Finish'],['steady-hand','Steady Hand'],['workbench','Workbench'],['crafted-set','Crafted Set'],['full-workshop','Full Workshop'],['guild-hall','Guild Hall'],['masters-bench',"Master's Bench"],['grand-atelier','Grand Atelier'],['city-of-makers','City of Makers']]
        .map(([key,title]) => ({ key, title, sourceFilename: `${key}-complete-scene-source-v1.png`, interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 })),
});
await import('../shared-frame-v1/build.mjs');
