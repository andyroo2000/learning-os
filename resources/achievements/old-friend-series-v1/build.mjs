process.env.ACHIEVEMENT_CONTAINER_CONFIG = JSON.stringify({
    sourceRoot: 'resources/achievements/old-friend-series-v1/source', referenceSourcePath: 'resources/achievements/shared-frame-v1/source/original-style-reference.png', containerSourcePath: 'resources/achievements/shared-frame-v1/source/container-source.png',
    outputRevision: 'old-friend-series-v1', generatedBy: 'resources/achievements/old-friend-series-v1/build.mjs', familyKey: 'old-friend', apertureInsetRatio: 0.047, trimToContainerFrame: true,
    sheetTitle: 'OLD FRIEND · HIDDEN BADGE', sheetSubtitle: 'Recall a card after six months apart', seriesTitle: 'OLD FRIEND', seriesSubtitle: 'Hidden achievement', containerLabel: 'UNIVERSAL BORDER',
    sheetFooter: 'Production PNGs contain no text; title and progress are rendered by the client UI.',
    tiers: [{ key: 'old-friend', title: 'Old Friend', sourceFilename: 'old-friend-complete-scene-source-v1.png', interiorWidthRatio: 1, interiorHeightRatio: 1, interiorBottomRatio: 1 }],
});
await import('../shared-frame-v1/build.mjs');
