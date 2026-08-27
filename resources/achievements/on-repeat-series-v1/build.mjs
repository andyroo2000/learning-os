process.env.ACHIEVEMENT_CONTAINER_CONFIG = JSON.stringify({
    sourceRoot: 'resources/achievements/on-repeat-series-v1/source', referenceSourcePath: 'resources/achievements/shared-frame-v1/source/original-style-reference.png', containerSourcePath: 'resources/achievements/shared-frame-v1/source/container-source.png',
    outputRevision: 'on-repeat-series-v1', generatedBy: 'resources/achievements/on-repeat-series-v1/build.mjs', familyKey: 'on-repeat', apertureInsetRatio: 0.047, trimToContainerFrame: true,
    sheetTitle: 'ON REPEAT · HIDDEN BADGE', sheetSubtitle: 'Finish one episode on three different days', seriesTitle: 'ON REPEAT', seriesSubtitle: 'Hidden achievement', containerLabel: 'UNIVERSAL BORDER',
    sheetFooter: 'Production PNGs contain no text; title and progress are rendered by the client UI.',
    tiers: [{ key: 'on-repeat', title: 'On Repeat', sourceFilename: 'on-repeat-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 }],
});
await import('../shared-frame-v1/build.mjs');
