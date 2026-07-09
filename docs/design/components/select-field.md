# SelectField — liste déroulante de marque

Référence exécutable : `SelectField.dc.html` · Capture : `screens/declarer-mission/*.png`

## Dimensions
Bouton 50px, radius 12px · options 48px min · liste : radius 14, bord 1.5px `green-300`, shadow-md.

## Anatomy
1. Label 13px/700 `gray-700` (gap 7px).
2. Bouton déclencheur : valeur (15px ; `gray-900`/600 si choisie, `gray-400` si placeholder) + chevron 16px `gray-400` qui **pivote 180°** (200ms) à l'ouverture.
3. Liste dépliante **inline** (pousse le contenu, pas d'overlay) : entrée 180ms `sfPop` (fade + translateY(−5px)) ; options séparées par 1px `gray-100`.
4. Option sélectionnée : fond `green-50`, texte `green-800`/700 + coche 16px `green-600`.

## États
Repos bord `gray-200` · Ouvert bord `green-500` · Option hover : fond `gray-50` (production).

## Variantes (props)
`label`, `placeholder`, `value`, `options: string[]`, `onChange(value)`.

## Interactions
Tap bouton = toggle ; tap option = sélection + fermeture + `onChange`. Production : fermer sur Échap et clic extérieur ; navigation flèches + Enter.

## Responsive
Identique partout (dans un sheet, la liste pousse le contenu scrollable — jamais clippée).

## Accessibilité
`role="listbox"`/`option` + `aria-expanded` sur le déclencheur en production ; valeur sélectionnée annoncée.

## Règle
**Ne jamais utiliser un `<select>` natif nu** — il casse la signature visuelle.
