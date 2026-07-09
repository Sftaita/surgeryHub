# Contrôles divers

## Checkbox
22×22px radius 6. Décochée : blanc + inset ring 1.5px `gray-300`. Cochée : fond `green-600` + coche blanche 13px stroke 3.4. Transition 150ms. Libellé 13.5–14px/600 `gray-600/700` cliquable. Usages : « Se souvenir de moi » (cochée par défaut), « Se termine le lendemain (après minuit) ».

## Filter chips
Pill 38px padding 0 15px, 13.5/600. Actif : fond `green-900` (ou `gray-900`), blanc, **liseré blanc 2px** (`box-shadow: 0 0 0 2px #fff`) + shadow-sm — le liseré garantit la lisibilité quand la chip chevauche le bandeau vert. Inactif : blanc, `gray-600`, shadow-xs.

## Segmented control
Conteneur blanc radius 13 padding 4 shadow-sm, width max-content. Segments 36px padding 0 18 radius 10, 13.5/700 : actif `green-900` blanc, inactif transparent `gray-500`. Usages : Semaine/Mois.

## WeekStrip (planning semaine)
7 chips flex:1, 66px, radius 14, colonne : jour abrégé 11/600, numéro 17/800 tabular, point mission 5px `green-500` (opacity 0 sinon). Aujourd'hui : `green-900` blanc + liseré blanc 2px + ombre. Jour à mission : cursor pointer → modal jour.

## MonthCalendar (planning mois)
Card blanche radius 18 padding 12. En-têtes L M M J V S D 28px 11/700 `gray-400`. Grille `repeat(7, minmax(0,1fr))` gap 2, cellules 44px radius 10 : numéro 13.5/600 tabular + point 5px. Aujourd'hui : capsule `green-900` blanc, point `green-300`. Jour à mission : fond `green-50` (ou `amber-50` si à encoder), point coloré, cliquable. Hors mois : `gray-300`. Légende (sous séparateur dashed) : points 6px + libellés 12 `gray-500` (Mission / À encoder). **Doit tenir sans scroll horizontal à toute largeur.**

## Toast
Fixed bottom ~98px centré, max-width min(420px, 100vw−40), fond `gray-900`, blanc 14/600, radius 12, padding 13 18, shadow-lg, z 1000. Entrée `shPop` 220ms, auto-dismiss 2.8s. Un seul toast à la fois (remplace le précédent).

## Barre brouillon (encodage)
Card blanche radius 14 padding 12 15 shadow-md : point 9px `green-500` pulsant + « Brouillon en cours » 13.5/700 `green-700` + compteurs 12.5 muted tabular + à droite icône cloud-check + « Enregistré à HH:MM » 12 `gray-400` tabular (heure mise à jour à chaque modification).

## Encart total (vert)
Fond `green-50` radius 13 padding 14 16 : libellé 14/700 `green-800` + valeur 22/800 tabular `green-800`. Usages : Total presté, Durée (déclaration).
