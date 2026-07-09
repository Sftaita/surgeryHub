# Écran — Planning (vue mois)

## Informations générales
- **Nom** : Planning — Mois · **Route** : `/planning?vue=mois` · **Composant React** : `PlanningScreen` (vue mois)
- **Layout / Sidebar / Header** : identiques à la vue semaine · **Version** : v2 · **Date** : 2026-07-08

## Objectif
Calendrier mensuel complet : repérer d'un coup d'œil les jours à mission / à encoder, ouvrir le détail d'un jour.

## Capture officielle
`mobile.png` · `desktop.png` (+ annotées). Tablette : règle 900px.

## Hiérarchie visuelle
1. BrandHeader (« Planning » + « Juillet 2026 ») — 2. Segmented (Mois actif) — 3. MonthCalendar (card) — 4. Section À VENIR — 5. Bandeau info éventuel.

## Structure
Card calendrier : blanche r18 padding 12, shadow-xs. En-têtes L M M J V S D : 28px, 11/700 `gray-400`. Grille `repeat(7, minmax(0,1fr))` gap 2 ; cellules 44px r10 (numéro 13.5/600 tabular + point 5px, gap 3). Légende : séparateur dashed + points 6px + libellés 12 `gray-500` (Mission / À encoder). **Aucun débordement horizontal à aucune largeur.**

## Composition
BrandHeader · SegmentedControl · MonthCalendar · Légende · MissionListCard · DayModal.

## Design Tokens utilisés
Aujourd'hui `green-900` + point `green-300` · jour mission fond `green-50` + point `green-500` · à encoder fond `amber-50` + point `amber-500` · hors mois `gray-300` · `size.monthCell=44`. → `design-tokens.json`

## Responsive
Cellules fluides `minmax(0,1fr)` — la grille se compresse sans casser. Desktop : même card dans la colonne 720.

## États
Aujourd'hui · jour à mission (teinté + point + cliquable) · à encoder (ambre) · hors mois (grisé, non cliquable) · mis à jour en direct (offres acceptées).

## Interactions
Cellule à mission → DayModal · segmented → retour semaine · lundi = 1re colonne (juillet 2026 : le 1er tombe mercredi ; 29–30 juin et 1–2 août grisés).

## Accessibilité
Cellules = boutons avec date complète · légende texte (couleur jamais seule) · en-têtes de colonnes abbr.

## Contraintes (intouchables)
Grille 7 colonnes fluides · légende visible · lundi en premier · teintes exactes mission/encodage · pas de scroll horizontal.

## Checklist d'acceptation
☐ mobile.png ☐ desktop.png ☐ tokens ☐ grille fluide sans débordement ☐ légende ☐ typo tabular ☐ modal jour ☐ synchro offres ☐ accessibilité ☐ aucune différence notable
