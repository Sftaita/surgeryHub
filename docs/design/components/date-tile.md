# DateTile — tuile date calendrier

Élément identitaire des cards mission/offre.

## Dimensions
Standard 54×58px radius 14 (offres) · 50×54 radius 12 (listes) · 46×50 radius 11–12 (rail accueil) · 48×52 radius 13 blanc (posée sur la photo du hero, shadow-sm).

## Anatomy
Colonne centrée : jour 17–19px/800 tabular (line-height 1) + mois 9.5–10px/700 tracking .08em UPPERCASE (« JUIL »), gap 1px.

## Couleurs par statut
Proposée `green-100`/`green-900` · Confirmée `green-50`/`green-800` · À venir `blue-50`/`blue-700` · À encoder `amber-50`/`amber-700` · Refusée `gray-100`/`gray-500` · Sur photo : blanc `rgba(255,255,255,.95)`/`green-900`.

## Variante « À venir » (accueil)
Colonne texte sans fond : jour de semaine 10.5/700 `gray-400` + numéro 21/800 + mois, suivie d'un séparateur vertical 1px `gray-150`.

## Règle
La tuile est toujours à gauche de la card, flex:none. Ne jamais remplacer par une date inline.
