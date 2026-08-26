import { createHash } from 'node:crypto';
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Resvg } from '@resvg/resvg-js';
import sharp from 'sharp';

const sourceDirectory = dirname(fileURLToPath(import.meta.url));
const repositoryRoot = resolve(sourceDirectory, '../../..');
const buildConfig = process.env.ACHIEVEMENT_CONTAINER_CONFIG
    ? JSON.parse(process.env.ACHIEVEMENT_CONTAINER_CONFIG)
    : {};
const sourceRoot = buildConfig.sourceRoot
    ? resolve(repositoryRoot, buildConfig.sourceRoot)
    : join(sourceDirectory, 'source');
const outputRevision = buildConfig.outputRevision ?? 'shared-frame-v1';
const outputDirectory = join(repositoryRoot, 'public', 'achievement-assets', outputRevision);
const checkOnly = process.argv.includes('--check');
const sizes = [64, 128, 256, 512];

const layout = {
    canvas: 128,
    outerRadiusRatio: 0.0625,
    apertureInsetRatio: buildConfig.apertureInsetRatio ?? 0.052,
    apertureRadiusRatio: 0.025,
    interiorWidthRatio: buildConfig.interiorWidthRatio ?? 0.68,
    interiorBottomRatio: buildConfig.interiorBottomRatio ?? 0.69,
};

const tierConfigs = (buildConfig.tiers ?? [{
    key: 'first-roar',
    title: 'First Roar',
    sourceFilename: 'first-roar-interior-source.png',
}]).map((tier) => ({
    ...tier,
    interiorWidthRatio: tier.interiorWidthRatio ?? layout.interiorWidthRatio,
    interiorHeightRatio: tier.interiorHeightRatio ?? null,
    interiorBottomRatio: tier.interiorBottomRatio ?? layout.interiorBottomRatio,
    interiorOffsetXRatio: tier.interiorOffsetXRatio ?? 0,
    interiorOffsetYRatio: tier.interiorOffsetYRatio ?? 0,
}));

const sourcePaths = {
    reference: buildConfig.referenceSourcePath
        ? resolve(repositoryRoot, buildConfig.referenceSourcePath)
        : join(sourceRoot, 'original-style-reference.png'),
    container: buildConfig.containerSourcePath
        ? resolve(repositoryRoot, buildConfig.containerSourcePath)
        : join(sourceRoot, 'container-source.png'),
    interior: join(sourceRoot, tierConfigs[0].sourceFilename),
    interiors: tierConfigs.map((tier) => join(sourceRoot, tier.sourceFilename)),
    stage: buildConfig.stageFilename ? join(sourceRoot, buildConfig.stageFilename) : null,
};

const sha256 = (data) => createHash('sha256').update(data).digest('hex');

function roundedMask(size, insetRatio, radiusRatio) {
    const inset = Math.round(size * insetRatio);
    const radius = Math.round(size * radiusRatio);
    const dimension = size - inset * 2;
    return Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}">
        <rect x="${inset}" y="${inset}" width="${dimension}" height="${dimension}" rx="${radius}" fill="#fff"/>
    </svg>`);
}

async function extractChromaKeyedLayer(source, trim) {
    const { data, info } = await sharp(source)
        .ensureAlpha()
        .raw()
        .toBuffer({ resolveWithObject: true });

    for (let index = 0; index < data.length; index += 4) {
        const red = data[index];
        const green = data[index + 1];
        const blue = data[index + 2];
        const magentaScore = Math.min(red, blue) - green;

        if (magentaScore >= 60) {
            data[index + 3] = 0;
            continue;
        }

        if (magentaScore > 25) {
            data[index + 3] = Math.round(255 * (60 - magentaScore) / 35);
        }

        // Remove keyed-color spill without disturbing coral, navy, cream, or turquoise source colors.
        if (magentaScore > 0 && data[index + 3] > 0) {
            if (red >= blue) {
                data[index + 2] = Math.min(blue, Math.max(0, green - 8));
            } else {
                data[index] = Math.min(red, Math.max(0, green - 8));
            }
        }
    }

    let pipeline = sharp(data, { raw: info });
    if (trim) {
        pipeline = pipeline.trim({ background: { r: 0, g: 0, b: 0, alpha: 0 }, threshold: 2 });
    }

    return pipeline.png().toBuffer();
}

async function clipInteriorSourceBottom(interior, bottomRatio = null) {
    if (! bottomRatio) return interior;

    const metadata = await sharp(interior).metadata();
    const width = metadata.width ?? 1;
    const height = metadata.height ?? 1;
    const visibleHeight = Math.max(1, Math.min(height, Math.round(height * bottomRatio)));
    if (visibleHeight === height) return interior;

    return sharp(interior)
        .extract({ left: 0, top: 0, width, height: visibleHeight })
        .extend({
            top: 0,
            bottom: height - visibleHeight,
            left: 0,
            right: 0,
            background: { r: 0, g: 0, b: 0, alpha: 0 },
        })
        .png()
        .toBuffer();
}

async function isolateContainerFrame(source) {
    const { data, info } = await sharp(source)
        .ensureAlpha()
        .raw()
        .toBuffer({ resolveWithObject: true });
    const { width, height, channels } = info;
    const pixelCount = width * height;
    const exterior = new Uint8Array(pixelCount);
    const queue = new Int32Array(pixelCount);
    let head = 0;
    let tail = 0;
    let minX = width;
    let minY = height;
    let maxX = -1;
    let maxY = -1;

    const isFramePixel = (pixel) => {
        const offset = pixel * channels;
        const red = data[offset];
        const green = data[offset + 1];
        const blue = data[offset + 2];
        return blue > green + 18 && green > red + 15 && blue < 145;
    };
    const enqueueExterior = (pixel) => {
        if (exterior[pixel] || isFramePixel(pixel)) return;
        exterior[pixel] = 1;
        queue[tail++] = pixel;
    };

    for (let x = 0; x < width; x += 1) {
        enqueueExterior(x);
        enqueueExterior((height - 1) * width + x);
    }
    for (let y = 1; y + 1 < height; y += 1) {
        enqueueExterior(y * width);
        enqueueExterior(y * width + width - 1);
    }

    while (head < tail) {
        const pixel = queue[head++];
        const x = pixel % width;
        const y = Math.floor(pixel / width);
        if (x > 0) enqueueExterior(pixel - 1);
        if (x + 1 < width) enqueueExterior(pixel + 1);
        if (y > 0) enqueueExterior(pixel - width);
        if (y + 1 < height) enqueueExterior(pixel + width);
    }

    for (let pixel = 0; pixel < pixelCount; pixel += 1) {
        const offset = pixel * channels;
        if (isFramePixel(pixel)) {
            const x = pixel % width;
            const y = Math.floor(pixel / width);
            minX = Math.min(minX, x);
            minY = Math.min(minY, y);
            maxX = Math.max(maxX, x);
            maxY = Math.max(maxY, y);
        }
        if (! exterior[pixel]) continue;

        // Transparent pixels carry the frame color to prevent a cream fringe
        // when the isolated handmade edge is downsampled.
        data[offset] = 8;
        data[offset + 1] = 63;
        data[offset + 2] = 107;
        data[offset + 3] = 0;
    }

    if (maxX < minX || maxY < minY) throw new Error('Could not detect the navy container frame.');
    const frameWidth = maxX - minX + 1;
    const frameHeight = maxY - minY + 1;
    if (frameWidth !== frameHeight) {
        throw new Error(`The detected container frame must be square; received ${frameWidth}x${frameHeight}.`);
    }

    return {
        image: await sharp(data, { raw: info }).png().toBuffer(),
        bounds: { left: minX, top: minY, width: frameWidth, height: frameHeight },
        sourceWidth: width,
        sourceHeight: height,
    };
}

async function cropToDetectedFrame(image, frame, outputSize = null) {
    let pipeline = sharp(image).extract(frame.bounds);
    if (outputSize) {
        pipeline = pipeline.resize(outputSize, outputSize, { fit: 'fill' });
    }
    return pipeline.png().toBuffer();
}

async function clipToAperture(layer, size) {
    return sharp(layer)
        .composite([{
            input: roundedMask(size, layout.apertureInsetRatio, layout.apertureRadiusRatio),
            blend: 'dest-in',
        }])
        .png()
        .toBuffer();
}

async function largestSubjectComponentBounds(interior, selector = 'opaque') {
    const { data, info } = await sharp(interior)
        .ensureAlpha()
        .raw()
        .toBuffer({ resolveWithObject: true });
    const { width, height, channels } = info;
    const pixelCount = width * height;
    const visited = new Uint8Array(pixelCount);
    const queue = new Int32Array(pixelCount);
    let largest = null;
    const isSubjectPixel = (pixel) => {
        const offset = pixel * channels;
        if (data[offset + 3] < 32) return false;
        if (selector !== 'coral') return true;

        const red = data[offset];
        const green = data[offset + 1];
        const blue = data[offset + 2];
        return red > 160
            && green > 35
            && green < 175
            && blue < 150
            && red > green + 45
            && red > blue + 55;
    };

    for (let seed = 0; seed < pixelCount; seed += 1) {
        if (visited[seed] || ! isSubjectPixel(seed)) continue;

        let head = 0;
        let tail = 1;
        let count = 0;
        let minX = width;
        let minY = height;
        let maxX = 0;
        let maxY = 0;
        queue[0] = seed;
        visited[seed] = 1;

        while (head < tail) {
            const pixel = queue[head++];
            const x = pixel % width;
            const y = Math.floor(pixel / width);
            count += 1;
            minX = Math.min(minX, x);
            minY = Math.min(minY, y);
            maxX = Math.max(maxX, x);
            maxY = Math.max(maxY, y);

            const neighbours = [
                x > 0 ? pixel - 1 : -1,
                x + 1 < width ? pixel + 1 : -1,
                y > 0 ? pixel - width : -1,
                y + 1 < height ? pixel + width : -1,
            ];
            for (const neighbour of neighbours) {
                if (neighbour < 0 || visited[neighbour] || ! isSubjectPixel(neighbour)) continue;
                visited[neighbour] = 1;
                queue[tail++] = neighbour;
            }
        }

        if (! largest || count > largest.count) {
            largest = {
                count,
                left: minX,
                top: minY,
                width: maxX - minX + 1,
                height: maxY - minY + 1,
            };
        }
    }

    if (! largest) throw new Error('The keyed interior does not contain an opaque subject.');
    return largest;
}

async function positionInterior(interior, size, tier, subjectBounds) {
    if (tier.subjectHeightRatio && subjectBounds) {
        const metadata = await sharp(interior).metadata();
        const sourceWidth = metadata.width ?? 1;
        const sourceHeight = metadata.height ?? 1;
        const scale = (size * tier.subjectHeightRatio) / subjectBounds.height;
        const targetWidth = Math.max(1, Math.round(sourceWidth * scale));
        const targetHeight = Math.max(1, Math.round(sourceHeight * scale));
        const resized = await sharp(interior)
            .resize({ width: targetWidth, height: targetHeight, fit: 'fill' })
            .png()
            .toBuffer();
        const subjectBottom = (subjectBounds.top + subjectBounds.height) * scale;
        const left = Math.round((size - targetWidth) / 2 + size * tier.interiorOffsetXRatio);
        const top = Math.round(
            size * (tier.subjectBottomRatio ?? tier.interiorBottomRatio)
            - subjectBottom
            + size * tier.interiorOffsetYRatio,
        );
        const sourceLeft = Math.max(0, -left);
        const sourceTop = Math.max(0, -top);
        const destinationLeft = Math.max(0, left);
        const destinationTop = Math.max(0, top);
        const visibleWidth = Math.min(targetWidth - sourceLeft, size - destinationLeft);
        const visibleHeight = Math.min(targetHeight - sourceTop, size - destinationTop);
        if (visibleWidth <= 0 || visibleHeight <= 0) {
            throw new Error(`Positioned artwork for ${tier.key} falls outside the badge canvas.`);
        }
        const visible = await sharp(resized)
            .extract({
                left: sourceLeft,
                top: sourceTop,
                width: visibleWidth,
                height: visibleHeight,
            })
            .png()
            .toBuffer();
        const layer = await sharp({
            create: {
                width: size,
                height: size,
                channels: 4,
                background: { r: 0, g: 0, b: 0, alpha: 0 },
            },
        })
            .composite([{ input: visible, left: destinationLeft, top: destinationTop }])
            .png()
            .toBuffer();

        return clipToAperture(layer, size);
    }

    const targetWidth = Math.round(size * tier.interiorWidthRatio);
    const targetHeight = tier.interiorHeightRatio
        ? Math.round(size * tier.interiorHeightRatio)
        : undefined;
    const resized = await sharp(interior)
        .resize({ width: targetWidth, height: targetHeight, fit: 'inside' })
        .png()
        .toBuffer();
    const metadata = await sharp(resized).metadata();
    const width = metadata.width ?? targetWidth;
    const height = metadata.height ?? targetWidth;
    const left = Math.round((size - width) / 2 + size * tier.interiorOffsetXRatio);
    const top = Math.round(size * tier.interiorBottomRatio - height + size * tier.interiorOffsetYRatio);

    const layer = await sharp({
        create: {
            width: size,
            height: size,
            channels: 4,
            background: { r: 0, g: 0, b: 0, alpha: 0 },
        },
    })
        .composite([{ input: resized, left, top }])
        .png()
        .toBuffer();

    return clipToAperture(layer, size);
}

async function positionStage(stage, size) {
    const targetHeight = Math.max(1, Math.round(size * (buildConfig.stageScaleY ?? 1)));
    let pipeline = sharp(stage).resize(size, targetHeight, { fit: 'fill' });
    if (targetHeight > size) {
        pipeline = pipeline.extract({ left: 0, top: targetHeight - size, width: size, height: size });
    } else if (targetHeight < size) {
        pipeline = pipeline.extend({
            top: size - targetHeight,
            bottom: 0,
            left: 0,
            right: 0,
            background: { r: 0, g: 0, b: 0, alpha: 0 },
        });
    }

    return pipeline.png().toBuffer();
}

async function compositeEarned(container, stage, interior, tier, size, subjectBounds) {
    const base = await sharp(container)
        .resize(size, size, { fit: 'fill' })
        .png()
        .toBuffer();
    const positionedInterior = await positionInterior(interior, size, tier, subjectBounds);
    const layers = [];
    if (stage) {
        const resizedStage = await positionStage(stage, size);
        layers.push({ input: await clipToAperture(resizedStage, size), left: 0, top: 0 });
    }
    layers.push({ input: positionedInterior, left: 0, top: 0 });
    const composite = await sharp(base)
        .composite(layers)
        .png()
        .toBuffer();

    if (buildConfig.trimToContainerFrame) return composite;

    return sharp(composite)
        .composite([{
            input: roundedMask(size, 0, layout.outerRadiusRatio),
            blend: 'dest-in',
        }])
        .png()
        .toBuffer();
}

async function lockedFromEarned(earned) {
    return sharp(earned)
        .greyscale()
        .tint('#8B9292')
        .modulate({ brightness: 1.08, saturation: 0.12 })
        .png()
        .toBuffer();
}

function dataUrl(data) {
    return `data:image/png;base64,${data.toString('base64')}`;
}

function renderSvg(svg, width) {
    return new Resvg(svg, { fitTo: { mode: 'width', value: width } }).render().asPng();
}

function reviewSheetSvg(container, interior, earned, locked, earned64, locked64) {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="940" height="500" viewBox="0 0 940 500">
        <rect width="940" height="500" fill="#FBF1D3"/>
        <g font-family="Arial, sans-serif">
            <text x="36" y="48" font-size="28" font-weight="800" fill="#083F6B">${buildConfig.sheetTitle ?? 'FIRST ROAR · CONTAINER PILOT'}</text>
            <text x="36" y="73" font-size="13" fill="#485F7A">${buildConfig.sheetSubtitle ?? 'Frozen generated container + masked complete interior illustration + deterministic raster exports'}</text>
            <g font-size="13" font-weight="700" fill="#083F6B" text-anchor="middle">
                <text x="147" y="114">${buildConfig.containerLabel ?? 'REUSABLE CONTAINER'}</text>
                <text x="357" y="114">MASKED INTERIOR</text>
                <text x="567" y="114">EARNED COMPOSITE</text>
                <text x="777" y="114">LOCKED COMPOSITE</text>
            </g>
            <image href="${dataUrl(container)}" x="75" y="136" width="144" height="144"/>
            <rect x="285" y="136" width="144" height="144" rx="9" fill="#F6E8C1"/>
            <image href="${dataUrl(interior)}" x="294" y="154" width="126" height="108" preserveAspectRatio="xMidYMid meet"/>
            <image href="${dataUrl(earned)}" x="495" y="136" width="144" height="144"/>
            <image href="${dataUrl(locked)}" x="705" y="136" width="144" height="144"/>
            <path d="M238 208h28M448 208h28" stroke="#EE685A" stroke-width="4" stroke-linecap="round"/>
            <text x="36" y="354" font-size="18" font-weight="800" fill="#083F6B">ACTUAL 64 PX CHECK</text>
            <image href="${dataUrl(earned64)}" x="36" y="378" width="64" height="64"/>
            <image href="${dataUrl(locked64)}" x="116" y="378" width="64" height="64"/>
            <text x="202" y="405" font-size="13" font-weight="700" fill="#EE685A">EARNED + LOCKED</text>
            <text x="202" y="427" font-size="12" fill="#485F7A">${buildConfig.sheetFooter ?? 'The same container pixels, mask ratios, and placement rules are reused for every badge.'}</text>
        </g>
    </svg>\n`;
}

function reviewSheetWithStageSvg(container, stage, interior, earned, locked, earned64, locked64) {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="1140" height="500" viewBox="0 0 1140 500">
        <rect width="1140" height="500" fill="#FBF1D3"/>
        <g font-family="Arial, sans-serif">
            <text x="36" y="48" font-size="28" font-weight="800" fill="#083F6B">${buildConfig.sheetTitle}</text>
            <text x="36" y="73" font-size="13" fill="#485F7A">${buildConfig.sheetSubtitle}</text>
            <g font-size="13" font-weight="700" fill="#083F6B" text-anchor="middle">
                <text x="132" y="114">${buildConfig.containerLabel ?? 'UNIVERSAL BORDER'}</text>
                <text x="342" y="114">${buildConfig.stageLabel ?? 'FAMILY STAGE'}</text>
                <text x="552" y="114">TIER ARTWORK</text>
                <text x="762" y="114">EARNED COMPOSITE</text>
                <text x="972" y="114">LOCKED COMPOSITE</text>
            </g>
            <image href="${dataUrl(container)}" x="60" y="136" width="144" height="144"/>
            <rect x="270" y="136" width="144" height="144" rx="9" fill="#F6E8C1"/>
            <image href="${dataUrl(stage)}" x="270" y="136" width="144" height="144"/>
            <rect x="480" y="136" width="144" height="144" rx="9" fill="#F6E8C1"/>
            <image href="${dataUrl(interior)}" x="489" y="154" width="126" height="108" preserveAspectRatio="xMidYMid meet"/>
            <image href="${dataUrl(earned)}" x="690" y="136" width="144" height="144"/>
            <image href="${dataUrl(locked)}" x="900" y="136" width="144" height="144"/>
            <path d="M223 208h28M433 208h28M643 208h28" stroke="#EE685A" stroke-width="4" stroke-linecap="round"/>
            <text x="36" y="354" font-size="18" font-weight="800" fill="#083F6B">ACTUAL 64 PX CHECK</text>
            <image href="${dataUrl(earned64)}" x="36" y="378" width="64" height="64"/>
            <image href="${dataUrl(locked64)}" x="116" y="378" width="64" height="64"/>
            <text x="202" y="405" font-size="13" font-weight="700" fill="#EE685A">EARNED + LOCKED</text>
            <text x="202" y="427" font-size="12" fill="#485F7A">${buildConfig.sheetFooter}</text>
        </g>
    </svg>\n`;
}

function seriesSheetSvg(series) {
    const dense = series.length > 3;
    const width = dense ? 100 + series.length * 180 : 920;
    const iconSize = dense ? 144 : 160;
    const startX = dense ? 100 : 210;
    const stepX = dense ? 180 : 220;
    const columns = series.map((tier, index) => {
        const x = startX + index * stepX;
        return `
            <text x="${x + iconSize / 2}" y="112" text-anchor="middle" font-size="${dense ? 13 : 15}" font-weight="800" fill="#083F6B">${tier.title}</text>
            <image href="${dataUrl(tier.earned)}" x="${x}" y="132" width="${iconSize}" height="${iconSize}"/>
            <image href="${dataUrl(tier.locked)}" x="${x}" y="${dense ? 310 : 320}" width="${iconSize}" height="${iconSize}"/>`;
    }).join('');

    return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="530" viewBox="0 0 ${width} 530">
        <rect width="${width}" height="530" fill="#FBF1D3"/>
        <g font-family="Arial, sans-serif">
            <text x="36" y="48" font-size="28" font-weight="800" fill="#083F6B">${buildConfig.seriesTitle ?? 'ROARER BADGE SERIES'}</text>
            <text x="36" y="73" font-size="13" fill="#485F7A">${buildConfig.seriesSubtitle ?? `One universal border · one shared family stage · ${series.length} tier-specific illustrations`}</text>
            <text x="36" y="214" font-size="14" font-weight="800" fill="#EE685A">EARNED</text>
            <text x="36" y="${dense ? 392 : 402}" font-size="14" font-weight="800" fill="#687586">LOCKED</text>
            ${columns}
            <text x="36" y="512" font-size="12" fill="#485F7A">Production badge PNGs contain no text; these names are review-sheet labels only.</text>
        </g>
    </svg>\n`;
}

const [referenceSource, containerSource, stageSource] = await Promise.all([
    readFile(sourcePaths.reference),
    readFile(sourcePaths.container),
    sourcePaths.stage ? readFile(sourcePaths.stage) : Promise.resolve(null),
]);
const interiorSources = await Promise.all(sourcePaths.interiors.map((path) => readFile(path)));
const extractedInteriors = await Promise.all(
    interiorSources.map((source) => extractChromaKeyedLayer(source, true)),
);
const interiors = await Promise.all(extractedInteriors.map((interior, index) => (
    clipInteriorSourceBottom(interior, tierConfigs[index].interiorClipBottomRatio)
)));
const subjectBounds = await Promise.all(interiors.map((interior, index) => (
    tierConfigs[index].subjectHeightRatio
        ? largestSubjectComponentBounds(interior, tierConfigs[index].subjectSelector)
        : Promise.resolve(null)
)));
const stage = stageSource ? await extractChromaKeyedLayer(stageSource, false) : null;
const framedContainer = buildConfig.trimToContainerFrame
    ? await isolateContainerFrame(containerSource)
    : null;
const compositingContainer = framedContainer?.image ?? containerSource;
const outputs = new Map();
const tierManifests = [];

for (const [tierIndex, tier] of tierConfigs.entries()) {
    const assets = { earned: {}, locked: {} };
    const framedMaster = framedContainer
        ? await cropToDetectedFrame(
            await compositeEarned(
                compositingContainer,
                stage,
                interiors[tierIndex],
                tier,
                framedContainer.sourceWidth,
                subjectBounds[tierIndex],
            ),
            framedContainer,
        )
        : null;
    for (const size of sizes) {
        const earned = framedMaster
            ? await sharp(framedMaster).resize(size, size, { fit: 'fill' }).png().toBuffer()
            : await compositeEarned(
                compositingContainer,
                stage,
                interiors[tierIndex],
                tier,
                size,
                subjectBounds[tierIndex],
            );
        const locked = await lockedFromEarned(earned);

        for (const [state, data] of [['earned', earned], ['locked', locked]]) {
            const outputPath = `${tier.key}/${state}-${size}.png`;
            outputs.set(outputPath, data);
            assets[state][String(size)] = {
                path: `/achievement-assets/${outputRevision}/${outputPath}`,
                width: size,
                height: size,
                checksumSha256: sha256(data),
            };
        }
    }

    tierManifests.push({
        familyKey: buildConfig.familyKey ?? 'roarer',
        tierKey: tier.key,
        title: tier.title,
        layout: {
            interiorWidthRatio: tier.interiorWidthRatio,
            interiorBottomRatio: tier.interiorBottomRatio,
            interiorOffsetXRatio: tier.interiorOffsetXRatio,
            interiorOffsetYRatio: tier.interiorOffsetYRatio,
            ...(tier.interiorClipBottomRatio ? {
                interiorClipBottomRatio: tier.interiorClipBottomRatio,
            } : {}),
            ...(tier.subjectHeightRatio ? {
                subjectHeightRatio: tier.subjectHeightRatio,
                subjectBottomRatio: tier.subjectBottomRatio ?? tier.interiorBottomRatio,
                detectedSubjectBounds: subjectBounds[tierIndex],
            } : {}),
        },
        assets,
    });
}

const firstTier = tierConfigs[0];
const firstInterior = interiors[0];
const exportedContainer = framedContainer
    ? await cropToDetectedFrame(compositingContainer, framedContainer)
    : containerSource;
const containerPreview = await sharp(exportedContainer).resize(144, 144).png().toBuffer();
const earned128 = outputs.get(`${firstTier.key}/earned-128.png`);
const locked128 = outputs.get(`${firstTier.key}/locked-128.png`);
const reviewSvg = stage
    ? reviewSheetWithStageSvg(
        containerPreview,
        await sharp(stage).resize(144, 144).png().toBuffer(),
        firstInterior,
        earned128,
        locked128,
        outputs.get(`${firstTier.key}/earned-64.png`),
        outputs.get(`${firstTier.key}/locked-64.png`),
    )
    : reviewSheetSvg(
        containerPreview,
        firstInterior,
        earned128,
        locked128,
        outputs.get(`${firstTier.key}/earned-64.png`),
        outputs.get(`${firstTier.key}/locked-64.png`),
    );
outputs.set('container-contact-sheet.png', renderSvg(reviewSvg, 940));
if (tierConfigs.length > 1) {
    const series = tierConfigs.map((tier) => ({
        title: tier.title,
        earned: outputs.get(`${tier.key}/earned-128.png`),
        locked: outputs.get(`${tier.key}/locked-128.png`),
    }));
    const seriesSvg = seriesSheetSvg(series);
    const seriesWidth = tierConfigs.length > 3 ? 100 + tierConfigs.length * 180 : 920;
    outputs.set('series-contact-sheet.png', renderSvg(seriesSvg, seriesWidth));
}
outputs.set('container-512.png', await sharp(exportedContainer).resize(512, 512).png().toBuffer());
outputs.set('catalog.json', Buffer.from(`${JSON.stringify({
    revision: outputRevision,
    status: 'production',
    generatedBy: buildConfig.generatedBy ?? 'resources/achievements/shared-frame-v1/build.mjs',
    layout,
    ...(framedContainer ? {
        frameTrim: {
            strategy: 'exterior-flood-fill-to-detected-navy-frame',
            sourceWidth: framedContainer.sourceWidth,
            sourceHeight: framedContainer.sourceHeight,
            bounds: framedContainer.bounds,
        },
    } : {}),
    sources: {
        reference: { path: relative(repositoryRoot, sourcePaths.reference), checksumSha256: sha256(referenceSource) },
        container: { path: relative(repositoryRoot, sourcePaths.container), checksumSha256: sha256(containerSource) },
        interior: { path: relative(repositoryRoot, sourcePaths.interior), checksumSha256: sha256(interiorSources[0]) },
        ...(tierConfigs.length > 1 ? {
            interiors: tierConfigs.map((tier, index) => ({
                tierKey: tier.key,
                path: relative(repositoryRoot, sourcePaths.interiors[index]),
                checksumSha256: sha256(interiorSources[index]),
            })),
        } : {}),
        ...(stageSource && sourcePaths.stage ? {
            stage: { path: relative(repositoryRoot, sourcePaths.stage), checksumSha256: sha256(stageSource) },
        } : {}),
    },
    ...(tierConfigs.length > 1 ? {
        achievements: tierManifests,
    } : {
        achievement: {
            familyKey: tierManifests[0].familyKey,
            tierKey: tierManifests[0].tierKey,
            assets: tierManifests[0].assets,
        },
    }),
}, null, 2)}\n`));

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
        console.error(`Shared-frame achievement assets are stale or missing:\n${mismatches.map((path) => `- ${path}`).join('\n')}`);
        process.exitCode = 1;
    } else {
        console.log(`Verified ${outputs.size} deterministic shared-frame files.`);
    }
} else {
    await rm(outputDirectory, { recursive: true, force: true });
    for (const [outputPath, data] of outputs) {
        const destination = join(outputDirectory, outputPath);
        await mkdir(dirname(destination), { recursive: true });
        await writeFile(destination, data);
    }
    console.log(`Wrote ${outputs.size} files to ${relative(repositoryRoot, outputDirectory)}.`);
}
