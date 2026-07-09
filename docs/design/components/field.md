# Field — champ de saisie

Référence exécutable : `Field.dc.html` · Capture : voir `screens/login/*.png`

## Dimensions
Hauteur 54px (50px variantes denses) · radius 14px (12px dense) · largeur 100%.

## Anatomy
1. Label — 13px/700 `gray-700`, gap 7px au-dessus du contrôle.
2. Icône gauche (optionnelle) — 18px `green-600`, à 16px du bord, padding-left du texte 46px (16px sans icône).
3. Input — 16px `gray-900`, placeholder `gray-400`, fond blanc, bord 1.5px `gray-200`.
4. Toggle œil (type password) — bouton 42px, icône 19px `gray-400`, hover fond `gray-75`.

## États
- Repos : bord `gray-200`.
- Focus : bord `green-500` + ring 3px `--focus-ring` (transition border-color 150ms).
- Rempli : texte `gray-900` 600.
- Erreur (à implémenter) : bord `red-500`, message 13px `red-600` sous le champ.

## Variantes (props)
`label`, `placeholder`, `type: text|email|password`, `icon: none|mail|lock|user`, `onEnter`.

## Interactions
Enter → `onEnter` (soumet le login). Œil : bascule visibilité, `aria-label="Afficher le mot de passe"`.

## Responsive
Identique sur tous les breakpoints ; largeur portée par le conteneur (max 400–420px dans le login).

## Accessibilité
Label lié à l'input ; taille 16px (pas de zoom iOS) ; ring focus jamais supprimé.
