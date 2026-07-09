# Pixel Audit — Planning semaine

## Dimensions critiques
Chips 66px r14 (7 × flex:1, gap 7) · segmented : conteneur r13 pad 4, segments 36 pad 0 18 r10 · cards r16 pad 14 16 · DateTile 50×54 r14 · point 5px.

## Espacements critiques
Gap sections 20 · gap chips 7 · gap cards 11 · eyebrow + fil dashed avant la liste.

## Typographie critique
Jour 11/600 (opacité .8 sur actif) · numéro 17/800 tabular · segments 13.5/700 · site 15/700 · sous-titre 13 muted tabular · eyebrow 12/800 .07em `green-700`.

## Couleurs critiques
Aujourd'hui `green-900` blanc + liseré blanc 2px · chips inactives blanc/`gray-600` shadow-xs · point `green-500` (`green-400` sur capsule) · bandeau `blue-50`/`blue-700`.

## Radius critiques
Chips 14 · segmented 13/10 · cards 16 · bandeau 14.

## Ombres critiques
Aujourd'hui `0 0 0 2px #fff, 0 5px 14px rgba(20,77,56,.35)` · segmented shadow-sm · cards shadow-xs.

## Erreurs fréquentes
❌ Chips de largeur fixe (doivent être flex) ❌ Liseré blanc omis ❌ Point affiché sur les jours sans mission ❌ Semaine commençant lundi ici (cette bande commence au jour courant Dim 5 — respecter l'ordre de la capture) ❌ Bandeau info affiché même avec plusieurs missions
