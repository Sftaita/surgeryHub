// Regenerates every "any"-purpose PWA icon size from the real SurgicalHub logo mark
// (public/logo-mark-transparent.png) on a white background — replaces the old
// rounded-square gradient placeholder icons. Keeps the exact same filenames so
// manifest.json / index.html references need no changes.
//
// Unlike the maskable icons (generate-maskable-icons.mjs), these are never cropped
// by an OS mask, so the logo can use most of the canvas — just centered with a
// modest padding margin, not squeezed into the 40%-radius safe zone.
import sharp from "sharp";
import { mkdirSync } from "node:fs";
import { fileURLToPath } from "node:url";

const ICONS_DIR = new URL("../public/icons/", import.meta.url);
const LOGO_PATH = fileURLToPath(new URL("../public/logo-mark-transparent.png", import.meta.url));
mkdirSync(ICONS_DIR, { recursive: true });

const BACKGROUND_COLOR = "#ffffff";

// Trimmed logo's longer side occupies this fraction of the canvas — the rest is
// padding margin. 0.78 keeps a clear border on every size, including the favicon.
const CONTENT_FRACTION = 0.78;

// Sizes that get the full detailed logo (scalpel + cross + swoosh) — legible from
// 48px up. 16/32 (favicon) instead get a simplified cross-only silhouette, see
// SIMPLIFIED_SIZES below: the fine scalpel/swoosh detail collapses into an
// illegible smudge at that size.
const DETAILED_SIZES = [48, 72, 96, 128, 180, 192, 256, 384, 512];
const SIMPLIFIED_SIZES = [16, 32];

// Sampled from the logo's cross fill (public/logo-mark-transparent.png ~(280,120)).
const CROSS_BLUE = "#0076E2";

function backgroundSvg(size) {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
  <rect x="0" y="0" width="${size}" height="${size}" fill="${BACKGROUND_COLOR}" />
</svg>`;
}

function simplifiedCrossSvg(size) {
  const half = size / 2;
  const armLength = size * 0.62;
  const thickness = size * 0.2;
  const rx = thickness * 0.25;
  const vx = half - thickness / 2;
  const vy = half - armLength / 2;
  const hx = half - armLength / 2;
  const hy = half - thickness / 2;

  return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
  <rect x="0" y="0" width="${size}" height="${size}" fill="${BACKGROUND_COLOR}" />
  <rect x="${vx}" y="${vy}" width="${thickness}" height="${armLength}" rx="${rx}" fill="${CROSS_BLUE}" />
  <rect x="${hx}" y="${hy}" width="${armLength}" height="${thickness}" rx="${rx}" fill="${CROSS_BLUE}" />
</svg>`;
}

async function buildSimplified(size) {
  const outPath = fileURLToPath(new URL(`icon-${size}.png`, ICONS_DIR));
  await sharp(Buffer.from(simplifiedCrossSvg(size))).png().toFile(outPath);
  console.log("wrote", outPath, "(simplified cross, favicon size)");
}

async function build(size, trimmedBuffer, logoW, logoH) {
  const scale = (size * CONTENT_FRACTION) / Math.max(logoW, logoH);
  const targetW = Math.max(1, Math.round(logoW * scale));
  const targetH = Math.max(1, Math.round(logoH * scale));

  const resizedLogo = await sharp(trimmedBuffer).resize(targetW, targetH).png().toBuffer();

  const outPath = fileURLToPath(new URL(`icon-${size}.png`, ICONS_DIR));
  await sharp(Buffer.from(backgroundSvg(size)))
    .composite([{ input: resizedLogo, gravity: "center" }])
    .png()
    .toFile(outPath);

  console.log("wrote", outPath, `(logo ${targetW}x${targetH})`);
}

async function main() {
  const trimmedBuffer = await sharp(LOGO_PATH).trim({ threshold: 10 }).png().toBuffer();
  const { width: logoW, height: logoH } = await sharp(trimmedBuffer).metadata();

  for (const size of DETAILED_SIZES) {
    await build(size, trimmedBuffer, logoW, logoH);
  }
  for (const size of SIMPLIFIED_SIZES) {
    await buildSimplified(size);
  }
}

await main();
