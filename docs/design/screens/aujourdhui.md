# Écran — Aujourd'hui (accueil)

## Correspondance
Route : `/` · React Component : `TodayScreen` · Layout : AppLayout · Sidebar : desktop · Header : BrandHeader (vagues « today ») · Navigation : onglet 1 · Responsive : 900px · Version : v2

## Objectif
Répondre à « qu'est-ce que je fais aujourd'hui ? » : mission du jour (ou journée libre), rappels d'encodage, déclaration, aperçu des offres.

## Structure (ordre vertical, gap 20)
1. BrandHeader : « 👋 Bonjour, Sophie ! » + « {Jour date} · {N mission aujourd'hui | journée libre} » (`green-300`, tabular).
2. **MissionHero** 345px (voir `components/mission-hero.md`) — avec mission OU journée libre.
3. **Card À encoder** (si mission d'hier non encodée) — ambre, bouton Encoder.
4. **Bouton dashed** « Déclarer une mission non prévue » → sheet déclaration.
5. **Section À venir** : titre 16/800 + lien « Voir tout le planning » ; grid auto-fit 272px de cards cliquables (→ modal jour).
6. **Section OFFRES DISPONIBLES** (eyebrow + fil dashed + « Tout voir ») : rail horizontal de mini-cards (→ onglet Offres). Masquée si aucune offre proposée.

## Responsive
Desktop : header en carte 760px, contenu 720px ; cards À venir passent sur 2 colonnes ; le rail d'offres reste un rail. Mobile : tout en colonne, rail affleurant les bords.

## États
Avec mission / journée libre (hauteur identique) · avec/sans card à encoder · avec/sans offres. Données : compteurs et sections dérivés de l'état des offres en temps réel.

## Interactions
CTA hero → encodage · Encoder → encodage mission d'hier · cards à venir → modal jour · rail → Offres · cloche → notifications (stub toast) · avatar → menu compte.

## Fidelity — intouchable
Chevauchement contenu/header −34px · hauteur hero 345px · ordre des sections.
