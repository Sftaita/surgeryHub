# Écran — Offres

## Correspondance
Route : `/offres` · React : `OffersScreen` · Layout : AppLayout · Header : BrandHeader (vagues « offers ») · Nav : onglet 3 (badge) · Version : v2

## Objectif
Accepter ou refuser les missions proposées, filtrées par type.

## Structure
1. Header : « Offres » + sous-titre dynamique (« N offres correspondent à vos disponibilités » / « Aucune offre en attente »).
2. Filter chips : Toutes / Bloc opératoire / Stérilisation.
3. Liste verticale de **cards offre** (gap 13) — voir `components/cards.md`.

## États
- Proposée : actions Refuser / Prendre.
- Prise : bandeau vert « Ajoutée à votre planning » + lien Voir (→ Planning), tuile/statut passent en « Attribuée » (bleu dans le prototype).
- Refusée : card grisée .55, sans actions.
- Filtre sans résultat : liste vide (production : ajouter un empty state courtois).
- Aucune offre : sous-titre « Aucune offre en attente » (production : empty state avec illustration légère).

## Interactions
Prendre → statut + badge nav + planning + accueil mis à jour, toast « Mission attribuée… ». Refuser → grisée, toast. Chips → filtre immédiat.

## Fidelity
Nom du site en `green-700` · ratio boutons 1 : 1.7 · séparateur dashed interne.
