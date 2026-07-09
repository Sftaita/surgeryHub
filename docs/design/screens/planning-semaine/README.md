# Écran — Planning (vue semaine)

## Informations générales
- **Nom** : Planning — Semaine · **Route** : `/planning` · **Composant React** : `PlanningScreen` (vue par défaut)
- **Layout** : AppLayout · **Sidebar** : desktop (item Planning) · **Header** : BrandHeader (vagues « planning »)
- **Version** : v2 · **Auteur** : Design SurgeryHub · **Date** : 2026-07-08

## Objectif
Vue rapide de la semaine (missions par jour) + liste des missions à venir + accès au détail d'un jour.

## Capture officielle
`mobile.png` · `desktop.png` (+ annotées). Tablette : règle 900px.

## Hiérarchie visuelle
1. BrandHeader (« Planning » + « Juillet 2026 · semaine du 5 au 11 ») — 2. Segmented Semaine/Mois — 3. WeekStrip — 4. Section À VENIR (liste) — 5. Bandeau info (si ≤ 1 mission).

## Structure
Colonne 720, gutter 20, −34, gap 20. Segmented : conteneur blanc r13 padding 4 shadow-sm ; segments 36 r10. WeekStrip : 7 chips flex:1 66px r14 gap 7 (jour 11/600, numéro 17/800 tabular, point 5px). Cards liste : r16 padding 14 16, DateTile 50×54 r14, StatusPill (point pulsant si en cours). Bandeau info : `blue-50` r14 padding 14 16.

## Composition
BrandHeader · SegmentedControl · WeekStrip · MissionListCard (DateTile, StatusPill) · InfoBanner · DayModal · BottomNav/Sidebar.

## Design Tokens utilisés
Aujourd'hui : `green-900` + liseré blanc 2px + `0 5px 14px rgba(20,77,56,.35)` · point mission `green-500` · statuts confirmée/attente/en cours · `size.weekChip=66`. → `design-tokens.json`

## Responsive
Identique aux deux breakpoints (chips flex:1 s'étirent). Aucun masquage.

## États
Jour avec/sans mission (point + cliquable) · aujourd'hui (capsule sombre) · vue conservée pendant la session · liste enrichie en direct par les offres acceptées · ≤ 1 mission → bandeau info avec lien Offres.

## Interactions
Segment → bascule instantanée · chip jour à mission → DayModal · lien bandeau → Offres · header : sous-titre change selon la vue.

## Accessibilité
Chips jours = boutons avec date complète en aria-label · point = jamais seul (le modal détaille) · segmented : `aria-pressed`.

## Contraintes (intouchables)
7 colonnes égales · aujourd'hui toujours en capsule sombre + liseré · ordre segmented avant grille · points de mission visibles.

## Checklist d'acceptation
☐ mobile.png ☐ desktop.png ☐ tokens ☐ composants ☐ espacements ☐ typo ☐ interactions (modal, segmented) ☐ synchro offres→points ☐ accessibilité ☐ aucune différence notable
