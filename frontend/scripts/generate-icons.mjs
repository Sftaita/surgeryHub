import sharp from 'sharp';
import { readFileSync, mkdirSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const publicDir = join(__dirname, '..', 'public');
const iconsDir = join(publicDir, 'icons');

mkdirSync(iconsDir, { recursive: true });

// SVG source — 512x512 viewBox
const svgSource = `<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#1565C0"/>
      <stop offset="100%" stop-color="#63C9A3"/>
    </linearGradient>
  </defs>
  <rect width="512" height="512" rx="102" fill="url(#g)"/>
  <rect x="213" y="96" width="86" height="320" rx="18" fill="white"/>
  <rect x="96" y="213" width="320" height="86" rx="18" fill="white"/>
</svg>`;

const svgBuffer = Buffer.from(svgSource);

const sizes = [16, 32, 48, 72, 96, 128, 180, 192, 256, 384, 512];

for (const size of sizes) {
  const outPath = join(iconsDir, `icon-${size}.png`);
  await sharp(svgBuffer)
    .resize(size, size)
    .png()
    .toFile(outPath);
  console.log(`✓ icon-${size}.png`);
}

// favicon.ico (multi-size: 16, 32, 48)
// On génère un favicon-32.png qui servira de favicon.ico de remplacement
// (les navigateurs modernes acceptent .png comme favicon)
console.log('\nDone. Icons generated in public/icons/');
