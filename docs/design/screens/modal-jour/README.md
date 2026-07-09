# Overlay — Modal jour

## Informations générales
- **Nom** : Modal jour · **Route** : overlay sur `/planning` et `/` · **Composant React** : `DayModal`
- **Layout** : SheetModal (bottom sheet mobile / dialogue centré desktop) · **Version** : v2 · **Date** : 2026-07-08

## Objectif
Détail des missions d'un jour cliqué (semaine, mois ou cards à venir), avec action contextuelle.

## Capture officielle
`mobile.png` · `desktop.png` (+ annotées) — jour 5 (mission en cours).

## Hiérarchie visuelle
1. Overlay — 2. Eyebrow « PLANNING » + titre date + CloseButton — 3. Rangée(s) mission — 4. Action contextuelle.

## Structure
Patron SheetModal (voir `components/sheet-modal.md`) : mobile r26 haut padding 20 20 24+safe, slide-up 300ms ; desktop 480 r22 padding 24, pop 220ms ; overlay `rgba(11,19,32,.52)` blur 3px. Séparateur dashed sous le titre (marges 16). Rangée mission : fond `gray-50` r14 padding 14, gap 13 : icône map-pin cercle 38 teinté statut + site 14.5/700 + sous-titre 13 muted tabular + StatusPill ; action 46px pleine largeur en dessous (gap 10).

## Composition
SheetModal · CloseButton · StatusPill · Button contextuel.

## Design Tokens utilisés
Statuts (en cours vert / à encoder ambre / confirmée / en attente) · `z.overlay=800/sheet=810` · `motion.sheetUp/dialogPop/overlayFade`. → `design-tokens.json`

## Responsive
Mobile : sheet bas pleine largeur. Desktop : dialogue centré `min(480px,100%)`. Tablette : règle 900px.

## États
1 ou plusieurs missions (empilées gap 12) · action selon statut : En cours → « Voir le détail de la mission » (`green-500`) ; À encoder → « Encoder la mission » (`amber-600`) ; Confirmée / En attente → aucune action.

## Interactions
Ouverture : chip semaine, cellule mois, card à venir · fermeture : X, tap overlay (production : + Échap) · action Encoder → écran encodage (modal se ferme).

## Accessibilité
`role="dialog"` + `aria-labelledby` (titre date) · focus trap · retour focus au déclencheur.

## Contraintes (intouchables)
Titre = date complète en toutes lettres (« Dimanche 5 juillet ») · eyebrow PLANNING · patron modal standard.

## Checklist d'acceptation
☐ mobile.png ☐ desktop.png ☐ patron modal ☐ actions par statut ☐ animations (300/220/200ms) ☐ accessibilité ☐ aucune différence notable
