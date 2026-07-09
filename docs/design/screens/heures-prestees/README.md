# Overlay — Heures prestées

## Informations générales
- **Nom** : Heures prestées · **Route** : overlay sur `/missions/:id/encodage` · **Composant React** : `WorkedHoursSheet` (réutilisable : `HeuresPrestees`)
- **Layout** : SheetModal · **Version** : v2 · **Date** : 2026-07-08

## Objectif
Saisir début / fin / pause **sans clavier** (steppers ±15 min), total en direct, passage minuit optionnel.

## Capture officielle
`mobile.png` · `desktop.png` (+ annotées).

## Hiérarchie visuelle
1. Titre + CloseButton — 2. Encart horaire prévu — 3. Steppers Début / Fin (+ case lendemain) / Pause — 4. Encart Total — 5. CTA + Annuler.

## Structure
Patron SheetModal. Encart contexte `gray-50` r12 pad 12 14 (icône calendrier 16 + « Horaire prévu : 07h30 → 15h30 »). Steppers : StepperRow (label 74, boutons 46, valeur 19/800 tabular), gap 14. Case lendemain indentée 86px (checkbox 22 + libellé 13.5/600 + « (après minuit) » `gray-400`). Encart total `green-50` r13 pad 14 16 : libellé 14/700 + valeur 22/800 `green-800`. CTA 52 `green-700` margin-top 16, fantôme 44.

## Composition
SheetModal · CloseButton · StepperRow ×3 · Checkbox · Encart total · Button.

## Design Tokens utilisés
`size.stepperButton=46` · `green-50/700/800` · `motion.sheetUp/dialogPop`. → `design-tokens.json`

## Responsive
Mobile sheet / desktop dialogue 480. Identique sinon.

## États
Défaut (prérempli horaire prévu 07h30/15h30, pause 0) · lendemain coché → fin « HHhMM (+1j) », total sur 2 jours · bornes atteintes (clic ignoré ; production : bouton grisé) · enregistré → card encodage + récapitulatif passent en valeur verte.

## Interactions
±15 min par tap (production : répétition à l'appui long, flèches clavier) · total recalculé à chaque tap · Enregistrer → toast « Heures prestées enregistrées. » + horodatage brouillon · Annuler = aucune modification.

## Règles de bornes
début ≥ 0 · fin ≥ début+15 (sauf lendemain) · pause ≤ durée−15 · lendemain : total = fin+24h − début − pause.

## Accessibilité
aria-labels « Moins/Plus 15 minutes » · valeur annoncée (live region production) · case + libellé cliquables.

## Contraintes (intouchables)
Aucun input clavier pour les heures · encart total toujours visible · ordre Début/Fin/lendemain/Pause · pas ±15 min (prop `step` du composant si autre besoin).

## Checklist d'acceptation
☐ mobile.png ☐ desktop.png ☐ steppers 46px ☐ bornes ☐ lendemain (+1j) ☐ total en direct ☐ effets (card, récap, toast) ☐ accessibilité ☐ aucune différence notable
