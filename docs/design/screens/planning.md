# Écran — Planning (semaine + mois)

## Correspondance
Route : `/planning` · React : `PlanningScreen` · Layout : AppLayout · Header : BrandHeader (vagues « planning ») · Nav : onglet 2 · Version : v2

## Objectif
Vue d'ensemble des missions : bande semaine ou calendrier mois, liste à venir, accès au détail d'un jour.

## Structure
1. Header : « Planning » + sous-titre (« Juillet 2026 · semaine du X au Y » / « Juillet 2026 » en vue mois).
2. Segmented control **Semaine / Mois**.
3. Vue Semaine : WeekStrip 7 chips. OU Vue Mois : MonthCalendar (grille fluide + légende).
4. Section « À VENIR » (eyebrow + fil) : cards liste (DateTile + site + sous-titre + StatusPill, point pulsant si en cours).
5. Si une seule mission : bandeau info `blue-50` « Acceptez des offres pour compléter votre semaine » + lien.

## États
Jour avec/sans mission (point, teinte, cliquable) · aujourd'hui (capsule sombre + liseré blanc) · à encoder (point/teinte ambre) · vue semaine ↔ mois (état conservé pendant la session).

## Interactions
Segment → bascule instantanée · jour à mission → **modal jour** (voir `screens/modal-jour.md`) · lien offres → onglet Offres. Les missions acceptées apparaissent immédiatement (points + cards).

## Responsive
La grille mois utilise `repeat(7, minmax(0,1fr))` — jamais de débordement horizontal. Desktop : mêmes composants dans la colonne 720px.

## Fidelity
Légende toujours visible sous le mois · lundi = première colonne · jours hors mois visibles mais grisés.
