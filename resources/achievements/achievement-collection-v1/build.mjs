import { createHash } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Resvg } from '@resvg/resvg-js';
import sharp from 'sharp';

const sourceDirectory = dirname(fileURLToPath(import.meta.url));
const repositoryRoot = resolve(sourceDirectory, '../../..');
const revision = 'achievement-collection-v1';
const outputDirectory = join(repositoryRoot, 'public', 'achievement-assets', revision);
const checkOnly = process.argv.includes('--check');
const catalogSourcePath = join(sourceDirectory, 'catalog.source.json');
const familyRevisions = {
    roarer: 'roarer-series-v7',
    'card-muncher': 'card-muncher-series-v1',
    yearfire: 'matsuri-light-series-v1',
};
const reviewOrder = ['roarer', 'card-muncher', 'yearfire'];
const sha256 = (data) => createHash('sha256').update(data).digest('hex');
const escapeXml = (value) => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&apos;');
const dataUrl = (data) => `data:image/png;base64,${data.toString('base64')}`;

const catalogSourceBuffer = await readFile(catalogSourcePath);
const catalogSource = JSON.parse(catalogSourceBuffer.toString('utf8'));
const visualCatalogs = new Map();

for (const [familyKey, familyRevision] of Object.entries(familyRevisions)) {
    const path = join(repositoryRoot, 'public', 'achievement-assets', familyRevision, 'catalog.json');
    const buffer = await readFile(path);
    visualCatalogs.set(familyKey, {
        path,
        buffer,
        catalog: JSON.parse(buffer.toString('utf8')),
    });
}

const families = catalogSource.families.map((family) => {
    const visual = visualCatalogs.get(family.key);
    if (! visual) throw new Error(`No visual catalog is configured for achievement family ${family.key}.`);
    const achievements = new Map(visual.catalog.achievements.map((achievement) => [achievement.tierKey, achievement]));
    const tiers = family.tiers.map((tier) => {
        const achievement = achievements.get(tier.key);
        if (! achievement) {
            throw new Error(`Visual asset ${family.key}.${tier.key} is missing from ${visual.catalog.revision}.`);
        }

        return {
            ...tier,
            assetRevision: visual.catalog.revision,
            assets: {
                earned: { png: achievement.assets.earned },
                locked: { png: achievement.assets.locked },
            },
        };
    });

    if (achievements.size !== tiers.length) {
        throw new Error(`Visual and criteria tier counts differ for family ${family.key}.`);
    }

    return {
        ...family,
        visualRevision: visual.catalog.revision,
        tiers,
    };
});

const outputs = new Map();
outputs.set('catalog.json', Buffer.from(`${JSON.stringify({
    revision,
    status: 'production',
    generatedBy: 'resources/achievements/achievement-collection-v1/build.mjs',
    design: {
        canvas: 256,
        displaySize: 128,
        standardAssetSize: 256,
        retinaAssetSize: 512,
        sourcePixelsPerDisplayPixel: {
            standard: 2,
            retina: 4,
        },
        pixelDensityVariants: {
            '1x': 256,
            '2x': 512,
        },
        exportSizes: [64, 128, 256, 512],
        containerStrategy: 'shared-handmade-frame-with-transparent-exterior',
        captionStrategy: 'client-rendered-css-or-native-ui-below-image',
        embeddedText: false,
    },
    presentation: catalogSource.presentation,
    families,
    sources: {
        criteria: {
            path: relative(repositoryRoot, catalogSourcePath),
            checksumSha256: sha256(catalogSourceBuffer),
        },
        visuals: Object.fromEntries([...visualCatalogs].map(([familyKey, visual]) => [familyKey, {
            revision: visual.catalog.revision,
            path: relative(repositoryRoot, visual.path),
            checksumSha256: sha256(visual.buffer),
        }])),
    },
}, null, 2)}\n`));

const sheets = await Promise.all(reviewOrder.map(async (familyKey) => {
    const familyRevision = familyRevisions[familyKey];
    return readFile(join(repositoryRoot, 'public', 'achievement-assets', familyRevision, 'series-contact-sheet.png'));
}));
const sheetMetadata = await Promise.all(sheets.map((sheet) => sharp(sheet).metadata()));
const contactSheetWidth = Math.max(...sheetMetadata.map((metadata) => metadata.width ?? 0));
const contactSheetHeight = sheetMetadata.reduce((height, metadata) => height + (metadata.height ?? 0), 0);
let sheetTop = 0;
const contactSheetLayers = sheets.map((sheet, index) => {
    const layer = { input: sheet, left: 0, top: sheetTop };
    sheetTop += sheetMetadata[index].height ?? 0;
    return layer;
});
outputs.set('contact-sheet.png', await sharp({
    create: {
        width: contactSheetWidth,
        height: contactSheetHeight,
        channels: 4,
        background: '#FBF1D3',
    },
}).composite(contactSheetLayers).png().toBuffer());

const smallSheetWidth = 960;
const smallFamilyHeight = 205;
const smallSheetHeight = 78 + families.length * smallFamilyHeight;
const smallFamilyRows = [];
for (const [familyIndex, family] of families.entries()) {
    const top = 78 + familyIndex * smallFamilyHeight;
    const icons = [];
    for (const [tierIndex, tier] of family.tiers.entries()) {
        const x = 185 + tierIndex * 105;
        const earnedPath = join(repositoryRoot, 'public', tier.assets.earned.png['64'].path.replace(/^\//, ''));
        const lockedPath = join(repositoryRoot, 'public', tier.assets.locked.png['64'].path.replace(/^\//, ''));
        const [earned, locked] = await Promise.all([readFile(earnedPath), readFile(lockedPath)]);
        icons.push(`
            <text x="${x + 32}" y="${top + 19}" text-anchor="middle" font-size="10" font-weight="700" fill="#083F6B">${escapeXml(tier.title)}</text>
            <image href="${dataUrl(earned)}" x="${x}" y="${top + 31}" width="64" height="64"/>
            <image href="${dataUrl(locked)}" x="${x}" y="${top + 113}" width="64" height="64"/>`);
    }
    smallFamilyRows.push(`
        <text x="30" y="${top + 57}" font-size="18" font-weight="800" fill="#083F6B">${escapeXml(family.title)}</text>
        <text x="30" y="${top + 80}" font-size="11" font-weight="700" fill="#EE685A">EARNED · ACTUAL 64 PX</text>
        <text x="30" y="${top + 151}" font-size="11" font-weight="700" fill="#687586">LOCKED · ACTUAL 64 PX</text>
        ${icons.join('')}`);
}
const smallSheetSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="${smallSheetWidth}" height="${smallSheetHeight}" viewBox="0 0 ${smallSheetWidth} ${smallSheetHeight}">
    <rect width="${smallSheetWidth}" height="${smallSheetHeight}" fill="#FBF1D3"/>
    <g font-family="Arial, sans-serif">
        <text x="30" y="38" font-size="24" font-weight="800" fill="#083F6B">21-BADGE SMALL-SIZE AUDIT</text>
        <text x="30" y="60" font-size="12" fill="#485F7A">Every image below is rendered at its production 64 × 64 pixel size.</text>
        ${smallFamilyRows.join('')}
    </g>
</svg>`;
outputs.set('small-size-contact-sheet.png', new Resvg(smallSheetSvg, {
    fitTo: { mode: 'width', value: smallSheetWidth },
}).render().asPng());

if (checkOnly) {
    const mismatches = [];
    for (const [outputPath, expected] of outputs) {
        try {
            const actual = await readFile(join(outputDirectory, outputPath));
            if (! actual.equals(expected)) mismatches.push(outputPath);
        } catch {
            mismatches.push(outputPath);
        }
    }

    if (mismatches.length > 0) {
        console.error(`Achievement collection files are stale or missing:\n${mismatches.map((path) => `- ${path}`).join('\n')}`);
        process.exitCode = 1;
    } else {
        console.log(`Verified ${outputs.size} deterministic achievement collection files.`);
    }
} else {
    await mkdir(outputDirectory, { recursive: true });
    for (const [outputPath, data] of outputs) {
        await writeFile(join(outputDirectory, outputPath), data);
    }
    console.log(`Wrote ${outputs.size} files to ${relative(repositoryRoot, outputDirectory)}.`);
}
