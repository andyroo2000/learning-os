process.env.ACHIEVEMENT_CONTAINER_CONFIG = JSON.stringify({
    sourceRoot: 'resources/achievements/double-feature-series-v1/source', referenceSourcePath: 'resources/achievements/shared-frame-v1/source/original-style-reference.png', containerSourcePath: 'resources/achievements/shared-frame-v1/source/container-source.png',
    outputRevision: 'double-feature-series-v1', generatedBy: 'resources/achievements/double-feature-series-v1/build.mjs', familyKey: 'double-feature', apertureInsetRatio: 0.047, trimToContainerFrame: true,
    sheetTitle: 'DOUBLE FEATURE · HIDDEN BADGE', sheetSubtitle: 'Listening and conversation study on one day', seriesTitle: 'DOUBLE FEATURE', seriesSubtitle: 'Hidden achievement', containerLabel: 'UNIVERSAL BORDER',
    sheetFooter: 'Production PNGs contain no text; title and progress are rendered by the client UI.',
    tiers: [{ key: 'double-feature', title: 'Double Feature', sourceFilename: 'double-feature-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 }],
});
await import('../shared-frame-v1/build.mjs');
