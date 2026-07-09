# SheetModal — patron modal

Utilisé par : modal jour, wizard matériel, nouvelle intervention, heures prestées, déclaration, récapitulatif.

## Mobile (< 900px)
Bottom sheet : fixed bas, largeur 100%, radius 26px haut, padding `20px 20px 24px + safe-area`, fond blanc, shadow-xl, **max-height 80vh** scroll interne. Entrée `shSheetUp` 300ms.

## Desktop (≥ 900px)
Dialogue centré `min(480px, 100%)`, radius 22, padding 24. Entrée `shPop` 220ms.

## Overlay
`rgba(11,19,32,.52)` + `backdrop-filter: blur(3px)`, fade 200ms, z-index 800 (sheet 810). Tap overlay = fermer.

## Structure obligatoire
1. Rangée titre : titre 20px/800 tracking −0.02em (flex:1) + `CloseButton` 40px.
2. (Optionnel) sous-titre 13.5px muted, ou encart contexte `gray-50` radius 12 padding 12–13 14.
3. Contenu (gap 14–18).
4. CTA plein 52px `green-700/800` pleine largeur (margin-top 16–18).
5. Action fantôme 44px `gray-500` (Annuler / Retour / Continuer…).

## Wizard (variante à étapes)
Sous le titre : rangée d'étapes — cercles 30px (fait : `green-600` + coche blanche ; actif : `green-600` + numéro blanc ; à venir : blanc + inset ring 1.5 `gray-200` + numéro `gray-400`), libellés 12/600 dessous, connecteurs 2px (`green-600` si franchi, sinon `gray-200`). Titre d'étape « Étape N/3 – … » 14.5/700.

## Accessibilité
`role="dialog"`, `aria-modal`, focus trap, Échap ferme (production), retour du focus au déclencheur.
