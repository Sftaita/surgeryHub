# Pixel Audit — Déclarer une mission

## Dimensions critiques
SelectField 50 r12 (options 48) · steppers 46 r12 · valeur date 16/800, heures 19/800 · checkbox 22 · encart durée r13 · input commentaire 50 r12 · CTA 52 r12.

## Espacements critiques
Gap champs 14 · label→champ 7 · indentation lendemain 86 · séparateur dashed entre selects et steppers · CTA margin-top 18.

## Typographie critique
Titre 20/800 · sous-titre 13.5 muted · labels 13/700 (+ « * ») · placeholder selects `gray-400` · durée 22/800 `green-800`.

## Couleurs critiques
Liste select bord `green-300` shadow-md, option sélectionnée `green-50`/`green-800` + coche `green-600` · encart `green-50` · CTA `green-700`.

## Radius critiques
Selects/inputs 12 · liste 14 · encart 13.

## Ombres critiques
Liste select shadow-md · CTA `ctaGreen` · sheet shadow-xl.

## Erreurs fréquentes
❌ Select natif (casse la signature) ❌ Date picker calendrier au lieu du stepper ❌ Date future autorisée ❌ Durée absente ou statique ❌ Validation bloquante agressive (bordures rouges partout) au lieu du toast ❌ Commentaire requis
