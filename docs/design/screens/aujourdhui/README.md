# Écran — Aujourd'hui (accueil)

## Informations générales
- **Nom** : Aujourd'hui · **Route réelle** : `/app/i/today` (code : `TodayPage`) · **Composant designé** : `TodayScreen`
- **Layout** : MobileLayout · **Sidebar** : desktop (item 1 actif) · **Header** : BrandHeader (vagues « today », titre animé)
- **Version** : v2 · **Auteur** : Design SurgeryHub · **Date** : 2026-07-08
- **⚠️ Correction 2026-07-08** : cette fiche documentait la route `/`. `/` sert en réalité `LandingPage` (page marketing publique, voir `screens/landing/README.md`) — l'écran Aujourd'hui vit à `/app/i/today`. Voir `route-audit.md`.

## Objectif
Répondre à « qu'est-ce que je fais aujourd'hui ? » : mission du jour (ou journée libre), rappel d'encodage, déclaration hors-planning, aperçu des offres.

## Capture officielle
`mobile.png` · `desktop.png` (+ versions annotées). Tablette : cf. règle 900px. État « journée libre » : pas de capture — voir `components/mission-hero.md` (hauteur identique 345px), prop démo `journee=libre` dans le prototype.

## Hiérarchie visuelle
1. BrandHeader (greeting + date) — 2. MissionHero — 3. Card À encoder — 4. Bouton Déclarer — 5. Section À venir — 6. Rail Offres disponibles.

## Structure
Contenu : colonne max 720, gutter 20, **chevauche le header de −34px**, padding bas 130 (mobile). Gap sections 20. Header mobile : plein largeur, coins bas 28, padding 12 20 56 ; desktop : carte r24 max 760, padding 22 26 58, margin-top 22. MissionHero : **345px total**, photo 186px + voile, corps flex, CTA 52 en bas. Card à encoder : r16 bord `amber-100` padding 13 15. Déclarer : 52px dashed. À venir : grid `auto-fit minmax(272px,1fr)` gap 12. Rail : mini-cards 256 min, gap 12, marges −20 (affleure les bords).

## Composition
BrandHeader · MissionHero · StatusPill · DateTile · Card À encoder · Button dashed · Cards À venir · Rail OfferMini · BottomNav / Sidebar · Toast · DayModal (via cards à venir) · DeclareMissionSheet.

## Design Tokens utilisés
`gradient.brandHeader` · `heroPhotoOverlay` · `freeDayCard` · statuts (encours/aencoder/confirmee/attente) · `size.heroImage=186`, `heroCardTotal=345` · `shadow.md/xs` · `motion.waveMorph`, `titleEnter`, `pulse`. → `design-tokens.json`

## Responsive
- **Desktop ≥ 900** : sidebar 248 + colonne 720 ; header en carte ; À venir sur 2 colonnes ; rail reste un rail.
- **Tablette** : règle 900px (pas d'autre variation).
- **Mobile** : tout en colonne ; bottom-nav ; rail scroll-x scrollbar masquée.
- Sticky : aucun élément sticky dans le contenu ; nav fixe.

## États
- **Avec mission** (capture) / **journée libre** (hauteur identique, illustration animée, sous-titre header « · journée libre »).
- Avec/sans card À encoder · avec/sans offres (section masquée si 0 proposée).
- Loading (à implémenter : pas de skeleton designé — signaler, ne pas improviser un pattern visible) · Erreur réseau : non designé.

## Interactions
CTA hero → `/missions/:id/encodage` · Encoder (ambre) → encodage #514 · card à venir → DayModal · « Tout voir »/rail → Offres · cloche → toast stub · avatar → menu compte · vagues morphent à l'arrivée (kick après login/retour encodage), titre rejoue 450ms.

## Accessibilité
Greeting = h1 · pastilles statut avec texte · rail scrollable au clavier (production) · contrainte contraste : sous-titre `green-300` sur header ≥ 14/600.

## Contraintes (intouchables)
Chevauchement −34 · hauteur hero 345 · ordre des sections · une seule photo (hero) · badge nav = nb offres proposées.

## Checklist d'acceptation
☐ mobile.png ☐ desktop.png ☐ tokens ☐ composants ☐ espacements (gap 20, −34) ☐ typo (26/800 header) ☐ animations (morph, titre, pulse) ☐ règles UX ☐ accessibilité ☐ hauteur hero invariante avec/sans mission ☐ aucune différence notable
