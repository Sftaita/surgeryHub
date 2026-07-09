# Overlay — Déclarer une mission

## Informations générales
- **Nom** : Déclarer une mission · **Route** : overlay sur `/` · **Composant React** : `DeclareMissionSheet`
- **Layout** : SheetModal · **Version** : v2 · **Date** : 2026-07-08

## Objectif
Déclarer une mission effectuée hors plateforme / non prévue : site, chirurgien, type, date, horaires, commentaire.

## Capture officielle
`mobile.png` · `desktop.png` (+ annotées).

## Hiérarchie visuelle
1. Titre + CloseButton + sous-titre — 2. SelectFields (Site*, Chirurgien*, Type) — 3. Steppers (Date, Début, Fin) + case lendemain — 4. Encart Durée — 5. Commentaire — 6. CTA + Annuler.

## Structure
Patron SheetModal. Sous-titre 13.5 muted. SelectFields 50 r12 (voir `components/select-field.md`) gap 14. Séparateur dashed entre selects et steppers. StepperRow Date (valeur 16/800, format « Mar. 7 juillet ») puis Début/Fin (19/800 tabular). Case lendemain indentée 86. Encart Durée `green-50` r13 (22/800). Commentaire : input 50 r12 avec label « (optionnel) ». CTA « Déclarer la mission » 52 `green-700`.

## Composition
SheetModal · CloseButton · SelectField ×3 · StepperRow ×3 · Checkbox · Encart durée · Field commentaire · Button.

## Design Tokens utilisés
Identiques à heures-prestées + SelectField (liste bord `green-300`, option sélectionnée `green-50` + coche `green-600`). → `design-tokens.json`

## Responsive
Mobile sheet (scroll interne — le formulaire est long) / desktop dialogue 480.

## États
Défaut (selects sur placeholder, date = aujourd'hui, 07h30→08h30) · select ouvert (chevron pivoté, liste dépliée inline) · validation douce : site ou chirurgien manquant → toast « Sélectionnez un site et un chirurgien. » · succès → fermeture + toast « Mission déclarée. En attente de validation par l'établissement. »

## Interactions
Date bornée à aujourd'hui (le + est ignoré au-delà — on déclare du passé) · steppers ±15 min · lendemain → « (+1j) » + durée sur 2 jours · listes des selects : données démo (production : API sites/chirurgiens de l'instrumentiste + « Autre »).

## Accessibilité
Champs requis marqués * + annoncés · selects : listbox/option, aria-expanded · reste identique aux autres sheets.

## Contraintes (intouchables)
**Jamais de `<select>` natif** · ordre des champs · date non future · durée toujours visible · commentaire optionnel en dernier.

## Checklist d'acceptation
☐ mobile.png ☐ desktop.png ☐ SelectField conforme (pas de natif) ☐ steppers + bornes date ☐ durée en direct ☐ validation douce ☐ accessibilité ☐ aucune différence notable
