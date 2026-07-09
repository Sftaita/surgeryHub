# Pixel Audit — Login

## Dimensions critiques
Badge logo mobile 46×46 r13 (logo 34) ; desktop 48×48 r14 (36). Champs 54px r14. CTA 54px (mobile) / 52px (desktop) r12. Checkbox 22×22 r6. Toggle œil 42×42. Formulaire max 420 (mobile) / 400 (desktop). Grid desktop `minmax(420px,1fr) minmax(460px,1.1fr)`. Cercles arguments 48px.

## Espacements critiques
Panneau mobile padding 54 26 34 ; feuille 28 24 40 ; desktop gauche 44 52, droite 48. Gap champs 18 ; label→champ 7 ; rangée logo gap 12 ; arguments gap 22 (16 interne).

## Typographie critique
Titre mobile 30/800 lh1.18 ; desktop 40/800 lh1.16 ; « Connexion » 30/800 (desktop) ; sous-titres 14.5–15/400 ; labels 13/700 ; inputs 16 ; liens 14/600–700 ; wordmark 20–21/800.

## Couleurs critiques
`gradient.login` · Hub `green-300` · CTA `green-700`→hover `green-800` · liens `green-700` · placeholder `gray-400` · checkbox cochée `green-600`.

## Radius critiques
Feuille 26 (haut seulement) · champs 14 · CTA 12 · badge logo 13–14 · checkbox 6.

## Ombres critiques
`loginSheet 0 -14px 40px rgba(0,0,0,.28)` · badge logo `0 4px 14px rgba(0,0,0,.25)` · CTA `0 6px 18px rgba(20,77,56,.3)` · focus ring 3px vert.

## Erreurs fréquentes
❌ Select/inputs natifs sans le style de marque ❌ Checkbox décochée par défaut ❌ Logo posé direct sur le vert sans badge blanc ❌ Dégradé remplacé par un vert uni ❌ Radius feuille sur les 4 coins ❌ Réintroduire « code d'accès » ❌ CTA green-500 au lieu de green-700 ❌ Oubli du spinner de chargement
