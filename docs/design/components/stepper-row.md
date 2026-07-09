# StepperRow — sélecteur de valeur −/+

Référence exécutable : `StepperRow.dc.html` · Captures : `screens/heures-prestees/`, `screens/declarer-mission/`

Le composant signature de saisie sans clavier (heures, dates, pauses).

## Dimensions
Rangée 46px · label 74px fixe à gauche · boutons −/+ **46×46px**, radius 12, bord 1.5px `gray-200` · gap 12px.

## Anatomy
1. Label 13px/700 `gray-700` (74px, flex:none).
2. Bouton − (19px/700).
3. Valeur centrée, flex:1 — 19px/800 tabular-nums `gray-900` (ex. `07h30`, `30 min`, `Mar. 7 juillet`).
4. Bouton +.

## États
Hover boutons : bord + texte `green-500/700` · Press : scale(.96) · Bornes atteintes : le clic est ignoré (production : griser le bouton, `disabled` + opacité .4).

## Comportement
Pas ±15 min (heures) / ±1 jour (dates) / ±15 min (pause). Bornes : début < fin (sauf « lendemain » coché), pause ≤ durée −15, date ≤ aujourd'hui (déclaration). **Appui long = répétition** (à implémenter en production).

## Variantes (props)
`label`, `value` (string formatée), `onMinus`, `onPlus`.

## Responsive
Identique partout.

## Accessibilité
`aria-label` explicites (« Moins 15 minutes », « Jour suivant ») ; valeur dans un live-region poli en production ; flèches haut/bas au clavier.
