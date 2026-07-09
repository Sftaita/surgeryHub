# Pixel Audit — Aujourd'hui

## Dimensions critiques
Hero **345px total / photo 186px** · DateTile hero 48×52 r13 (blanche) · CTA hero 52 r13 · card à encoder : icône 42, bouton 40 · Déclarer 52 r14 dashed · cards à venir : DateTile colonne 40 min + séparateur 1px, min 272 · mini-card rail 256 min, DateTile 46×50 r11–12 · header mobile coins bas 28 / carte desktop r24 max 760 · contenu max 720.

## Espacements critiques
Gutter 20 · chevauchement **−34** · gap sections 20 · gap grids 12 · hero corps padding 14 18 18 · card padding 13 15 · rail marges −20.

## Typographie critique
Header : titre 26/800, sous-titre 14.5 `green-300` tabular · hero : site 18/800 blanc, horaires 21/800 tabular, eyebrow pastille 10.5/800 .06em · cards : site 15/700, méta 12.5–13 muted · section « À venir » 16/800.

## Couleurs critiques
Header `gradient.brandHeader` · voile photo `heroPhotoOverlay` · pastilles photo `rgba(20,77,56,.9)` · CTA `green-800` · à encoder `amber-50/100/600/700` · déclarer `green-50` bord `green-400` texte `green-800` · site rail `green-700`.

## Radius critiques
Hero 18 · cards 16 · header 28 (mobile bas) / 24 (desktop) · CTA 13 · déclarer 14.

## Ombres critiques
Hero shadow-md · cards shadow-xs · CTA `ctaGreen` · header : aucune (aplat).

## Erreurs fréquentes
❌ Hauteur hero différente entre états ❌ Voile photo oublié (texte illisible) ❌ Chevauchement −34 omis ❌ DateTile remplacée par date inline ❌ Rail transformé en grid ❌ Section offres visible avec 0 offre ❌ Vagues statiques (pas de morph) ❌ Greeting sans 👋 ou sous-titre non tabular
