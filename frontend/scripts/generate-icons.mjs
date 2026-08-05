import sharp from 'sharp';
import { mkdirSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const publicDir = join(__dirname, '..', 'public');
const iconsDir = join(publicDir, 'icons');

mkdirSync(iconsDir, { recursive: true });

// Source du logo réel SurgicalHub (scalpel + croix + swoosh), même asset que
// celui utilisé en app (LoginPage/MobileLayout/LandingPage) — voir favicon
// audit 2026-08-05. Ce script embarquait jusqu'ici un vieux placeholder
// (simple croix blanche sur dégradé) : les icônes ≥72px avaient été
// régénérées manuellement avec le nouveau logo en dehors de ce script, mais
// relancer ce script aurait silencieusement tout ré-écrasé avec l'ancien
// placeholder — c'est la cause du bug (icon-16.png/icon-32.png, jamais
// régénérés manuellement, étaient restés sur cet ancien placeholder alors
// que index.html les référence pour le favicon d'onglet).
const logoSource = join(publicDir, 'logo-mark-transparent.png');

const sizes = [16, 32, 48, 72, 96, 128, 180, 192, 256, 384, 512];

for (const size of sizes) {
  const outPath = join(iconsDir, `icon-${size}.png`);
  await sharp(logoSource)
    .resize(size, size, { kernel: sharp.kernel.lanczos3 })
    .png()
    .toFile(outPath);
  console.log(`✓ icon-${size}.png`);
}

// Les icônes maskable (icon-192-maskable.png / icon-512-maskable.png) ne sont
// pas régénérées ici : elles ont une zone de sécurité (safe zone) dédiée aux
// launchers Android, jamais un simple resize — voir les fichiers existants
// dans public/icons/ si un remplacement futur du logo doit aussi les couvrir.
//
// index.html référence actuellement icon-16-v2.png/icon-32-v2.png (pas
// icon-16.png/icon-32.png) pour casser le cache favicon persistant des
// navigateurs après ce correctif — si ce script est relancé, régénérer aussi
// -v2 (ou mettre à jour index.html pour repointer vers les noms sans
// suffixe) plutôt que de laisser index.html référencer un fichier périmé.
console.log('\nDone. Icons generated in public/icons/');
