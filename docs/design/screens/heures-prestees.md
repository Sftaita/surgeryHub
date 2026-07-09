# Sheet — Heures prestées

## Correspondance
Overlay sur `/missions/:id/encodage` · React : `WorkedHoursSheet` · Patron : SheetModal · Composant réutilisable : `HeuresPrestees.dc.html`

## Objectif
Saisir début / fin / pause sans clavier, voir le total en direct, gérer le passage de minuit.

## Structure
1. Titre « Heures prestées » + CloseButton.
2. Encart contexte `gray-50` : « Horaire prévu : 07h30 → 15h30 » (tabular).
3. StepperRow **Début** (±15 min) · StepperRow **Fin** · Checkbox « Se termine le lendemain (après minuit) » (indentée 86px) · StepperRow **Pause** (±15 min, « N min »).
4. **Encart total vert** : « Total presté » + valeur 22/800 (recalculée à chaque tap).
5. CTA « Enregistrer les heures » 52px `green-700` · fantôme « Annuler ».

## Règles de bornes
début ≥ 0 ; fin ≥ début+15 (sauf lendemain coché) ; pause ≤ durée−15 ; lendemain coché → fin affichée « HHhMM (+1j) », total = fin+24h − début − pause.

## Effets
Enregistrer → card Heures de l'encodage passe en valeur forte, ligne du récapitulatif passe ambre→vert, brouillon horodaté, toast « Heures prestées enregistrées. »

## Composant autonome
`HeuresPrestees` : props `title`, `planned`, `defaultStart/End` (minutes), `step` (5–30), `overnight` (affiche/masque la case), `saveLabel`, `onSave({start,end,pause,nextDay,totalMinutes})`.
