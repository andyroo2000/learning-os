process.env.ACHIEVEMENT_CONTAINER_CONFIG = JSON.stringify({
    sourceRoot: 'resources/achievements/mountain-path-series-v1/source', referenceSourcePath: 'resources/achievements/shared-frame-v1/source/original-style-reference.png',
    containerSourcePath: 'resources/achievements/shared-frame-v1/source/container-source.png', outputRevision: 'mountain-path-series-v1',
    generatedBy: 'resources/achievements/mountain-path-series-v1/build.mjs', familyKey: 'mountain-path', apertureInsetRatio: 0.047, trimToContainerFrame: true,
    sheetTitle: 'MOUNTAIN PATH · NINE-TIER SYSTEM', sheetSubtitle: 'Lifetime cards reaching Guru · trailhead to mountain range',
    seriesTitle: 'MOUNTAIN PATH BADGE SERIES', seriesSubtitle: '50 · 100 · 500 · 1K · 2K · 3K · 4K · 5K · 10K', containerLabel: 'UNIVERSAL BORDER',
    sheetFooter: 'Production PNGs contain no text; title and progress are rendered by the client UI.',
    tiers: [['trailhead','Trailhead'],['first-cairn','First Cairn'],['hill-path','Hill Path'],['base-camp','Base Camp'],['high-trail','High Trail'],['cloud-camp','Cloud Camp'],['summit-ridge','Summit Ridge'],['peak-parade','Peak Parade'],['mountain-range','Mountain Range']]
        .map(([key,title]) => ({ key, title, sourceFilename: `${key}-complete-scene-source-v1.png`, interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 })),
});
await import('../shared-frame-v1/build.mjs');
