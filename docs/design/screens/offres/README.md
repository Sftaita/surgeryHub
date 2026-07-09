# Écran — Offres

## Informations générales
- **Nom** : Offres · **Route** : `/offres` · **Composant React** : `OffersScreen`
- **Layout** : AppLayout · **Sidebar** : desktop (item Offres + badge) · **Header** : BrandHeader (vagues « offers »)
- **Version** : v2 · **Auteur** : Design SurgeryHub · **Date** : 2026-07-08

## Objectif
Accepter ou refuser les missions proposées, filtrées par type (Bloc opératoire / Stérilisation).

## Capture officielle
`mobile.png` · `desktop.png` (+ annotées). Tablette : règle 900px. États prise/refusée : pas de capture — spécifiés ci-dessous, visibles dans le prototype en interagissant.

## Hiérarchie visuelle
1. BrandHeader (« Offres » + sous-titre compteur) — 2. Filter chips — 3. Liste de cards offre.

## Structure
Colonne max 720, gutter 20, chevauchement −34, gap 16 (chips→liste), gap cards 13. Card : r18, bord `border-subtle`, shadow-xs, padding 16 ; rangée haut (DateTile 54×58 r14 + textes + StatusPill) ; séparateur dashed marge 14 ; détails gap 9–10 (icônes cercles 34 `green-50`) ; tags 24px ; actions 46px margin-top 16.

## Composition
BrandHeader · FilterChips · OfferCard (DateTile, StatusPill, Tags, Buttons) · Toast · BottomNav/Sidebar.

## Design Tokens utilisés
Statuts proposée/prise/refusée (`semantic.status`) · chips actives `green-900` + liseré blanc 2px · site `green-700` 16/800 · boutons `green-500`/outline · `radius.card=18`. → `design-tokens.json`

## Responsive
Desktop : mêmes cards dans la colonne 720 (une par ligne — **ne pas** passer en 2 colonnes). Mobile : identique. Aucun élément masqué.

## États
- **Proposée** (capture) : boutons Refuser / Prendre la mission (flex 1 / 1.7).
- **Prise** : pastille « Attribuée » (bleu), tuile bleue, bandeau `green-50` « ✓ Ajoutée à votre planning » + lien Voir → Planning.
- **Refusée** : card opacity .55, pastille grise, sans actions.
- **Filtre sans résultat / aucune offre** : sous-titre « Aucune offre en attente » ; empty state visuel non designé (à signaler avant d'implémenter).

## Interactions
Prendre → mise à jour immédiate (badge nav, planning, accueil) + toast « Mission attribuée… » · Refuser → grisée + toast · chips → filtre immédiat · hover boutons (Refuser → rouge doux).

## Accessibilité
Cards = régions labellisées (site + date) · boutons avec libellés complets · statut = texte + couleur · compteur badge annoncé.

## Contraintes (intouchables)
Site en `green-700` · ratio 1:1.7 des boutons · séparateur dashed · offre refusée reste visible · paires statut/couleur.

## Checklist d'acceptation
☐ mobile.png ☐ desktop.png ☐ tokens ☐ composants ☐ espacements ☐ typo (16/800 site) ☐ états prise/refusée conformes ☐ mises à jour croisées (badge/planning) ☐ accessibilité ☐ aucune différence notable
