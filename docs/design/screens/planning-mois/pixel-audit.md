# Pixel Audit — Planning mois

## Dimensions critiques
Card r18 pad 12 · cellules 44px r10 · en-têtes 28px · points 5px (légende 6px) · grille gap 2.

## Espacements critiques
Gap cellule interne 3 · légende : padding-top 12 après séparateur dashed, gap 16 entre items.

## Typographie critique
Numéros 13.5/600 tabular · en-têtes 11/700 `gray-400` · légende 12 `gray-500`.

## Couleurs critiques
Aujourd'hui `green-900` blanc, point `green-300` · mission `green-50` + `green-500` · à encoder `amber-50` + `amber-500` · hors mois `gray-300`.

## Radius critiques
Card 18 · cellules 10.

## Ombres critiques
Card shadow-xs · capsule aujourd'hui `0 3px 10px rgba(20,77,56,.35)`.

## Erreurs fréquentes
❌ Grille en px fixes (déborde sur petits écrans) ❌ Semaine commençant dimanche ❌ Légende omise ❌ Jours hors mois masqués (ils doivent être visibles grisés) ❌ Cellules à mission cliquables sans teinte de fond ❌ Point sous le numéro remplacé par un badge
