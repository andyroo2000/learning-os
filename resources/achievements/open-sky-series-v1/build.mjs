process.env.ACHIEVEMENT_CONTAINER_CONFIG = JSON.stringify({
    sourceRoot: 'resources/achievements/open-sky-series-v1/source', referenceSourcePath: 'resources/achievements/shared-frame-v1/source/original-style-reference.png',
    containerSourcePath: 'resources/achievements/shared-frame-v1/source/container-source.png', outputRevision: 'open-sky-series-v1',
    generatedBy: 'resources/achievements/open-sky-series-v1/build.mjs', familyKey: 'open-sky', apertureInsetRatio: 0.047, trimToContainerFrame: true,
    sheetTitle: 'OPEN SKY · NINE-TIER SYSTEM', sheetSubtitle: 'Lifetime cards reaching Enlightened · first feather to beyond the sky',
    seriesTitle: 'OPEN SKY BADGE SERIES', seriesSubtitle: '50 · 100 · 500 · 1K · 2K · 3K · 4K · 5K · 10K', containerLabel: 'UNIVERSAL BORDER',
    sheetFooter: 'Production PNGs contain no text; title and progress are rendered by the client UI.',
    tiers: [['first-feather','First Feather'],['first-flight','First Flight'],['above-the-trees','Above the Trees'],['cloudline','Cloudline'],['wide-horizon','Wide Horizon'],['high-current','High Current'],['blue-expanse','Blue Expanse'],['skybound','Skybound'],['beyond-the-sky','Beyond the Sky']]
        .map(([key,title]) => ({ key, title, sourceFilename: `${key}-complete-scene-source-v1.png`, interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 })),
});
await import('../shared-frame-v1/build.mjs');
