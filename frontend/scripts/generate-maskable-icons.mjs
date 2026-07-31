// One-off generator for conformant maskable PWA icons, using the real SurgicalHub
// logo mark (public/logo-mark-transparent.png) — not a placeholder shape. The
// existing "any" icons (icon-192.png / icon-512.png) have pre-rounded corners and
// content reaching close to the edge, which is unsafe once an OS applies its own
// mask (see docs audit — Lot 2 PWA). This script builds dedicated full-bleed
// maskable icons with the real logo recentered/rescaled inside the standard
// 40%-radius safe zone.
import sharp from "sharp";
import { mkdirSync } from "node:fs";
import { fileURLToPath } from "node:url";

const ICONS_DIR = new URL("../public/icons/", import.meta.url);
const LOGO_PATH = fileURLToPath(new URL("../public/logo-mark-transparent.png", import.meta.url));
mkdirSync(ICONS_DIR, { recursive: true });

// Maskable icons must be fully opaque, edge-to-edge — a transparent background
// leaves a hole once the OS applies its own mask shape, so this can't be "logo with
// no background". White matches manifest.json's background_color.
const BACKGROUND_COLOR = "#ffffff";

// Fraction of the safe-zone radius (40% of icon size) the logo's half-diagonal is
// allowed to reach — kept below 1.0 for a comfortable margin against launcher masks
// that crop slightly more aggressively than the nominal safe zone.
const SAFE_ZONE_MARGIN = 0.82;

function backgroundSvg(size) {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
  <rect x="0" y="0" width="${size}" height="${size}" fill="${BACKGROUND_COLOR}" />
</svg>`;
}

async function build(size) {
  // 1. Trim the real logo down to its actual visible content (it doesn't fill its
  //    695x695 canvas evenly — a diagonal scalpel + cross composition).
  const trimmedBuffer = await sharp(LOGO_PATH).trim({ threshold: 10 }).png().toBuffer();
  const trimmedMeta = await sharp(trimmedBuffer).metadata();
  const logoW = trimmedMeta.width;
  const logoH = trimmedMeta.height;

  // 2. Scale so the logo's half-diagonal fits inside the safe-zone radius.
  const safeRadius = size * 0.4 * SAFE_ZONE_MARGIN;
  const halfDiagonal = Math.sqrt((logoW / 2) ** 2 + (logoH / 2) ** 2);
  const scale = safeRadius / halfDiagonal;
  const targetW = Math.round(logoW * scale);
  const targetH = Math.round(logoH * scale);

  const resizedLogo = await sharp(trimmedBuffer).resize(targetW, targetH).png().toBuffer();

  // 3. Full-bleed gradient background, real logo composited centered on top.
  const outPath = fileURLToPath(new URL(`icon-${size}-maskable.png`, ICONS_DIR));
  await sharp(Buffer.from(backgroundSvg(size)))
    .composite([{ input: resizedLogo, gravity: "center" }])
    .png()
    .toFile(outPath);

  console.log("wrote", outPath, `(logo ${targetW}x${targetH} within safe radius ${safeRadius.toFixed(1)}px)`);
}

await build(192);
await build(512);
