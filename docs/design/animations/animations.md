# Animations & Motion — spécifications exactes

Easing de marque : `cubic-bezier(0.22, 1, 0.36, 1)`. Easing vagues : `cubic-bezier(0.16, 1, 0.3, 1)`.
`prefers-reduced-motion: reduce` → désactiver tout ce qui est marqué ♻ (boucles) et remplacer les slides par des fades courts.

| # | Animation | Trigger | Durée | Easing | État initial | État final |
|---|---|---|---|---|---|---|
| 1 | `shFade` — entrée d'écran | Changement d'onglet | 250ms | marque | opacity 0, translateY(6px) | opacity 1, none |
| 2 | `shTitleA/B` — titre header | Chaque navigation | 450ms (+80ms délai sous-titre) | marque | opacity 0, translateY(10px) | opacity 1 |
| 3 | Morphing vagues header | Changement d'onglet | 1100 / 1350 / 1600ms (3 couches en cascade) | vagues | tracés de l'onglet précédent | tracés de l'onglet cible (transition CSS sur `d: path(…)` ; les tracés d'une même couche gardent la même structure C…S…) |
| 4 | « Kick » des vagues | Retour d'encodage, validation, login réussi | mêmes durées que #3 | vagues | tracés « kick » (amplitude forte) montés sans transition | morph vers les tracés de l'onglet |
| 5 | `shEncWave1/2` — arrivée encodage | Ouverture écran encodage | 1100 / 1350ms | vagues | keyframes 0% (amplitude haute) → 45% (contre-vague) | 100% tracé final (se pose en douceur) |
| 6 | `shSheetUp` — bottom sheet | Ouverture modal mobile | 300ms | marque | translateY(100%) | none |
| 7 | `shPop` — dialogue / menu | Ouverture modal desktop, menu compte, liste SelectField (`sfPop` 180ms) | 220ms (menu 180ms) | marque | opacity 0, translateY(10px) scale(.98) | opacity 1 |
| 8 | `shOverlay` — fond modal | Ouverture modal | 200ms | ease-out | opacity 0 | opacity 1 (`rgba(11,19,32,.52)` + blur 3px) |
| 9 | ♻ `shPulse` — point « En cours » | Permanent | 1.6s loop | ease-in-out | opacity 1 | opacity .35 → 1 |
| 10 | ♻ `shSpin` — spinner login | Pendant connexion | 700ms loop | linear | rotate 0 | rotate 360° |
| 11 | ♻ Illustration journée libre | Permanent (état vide) | sway 5s ±1.6° · float 4.5s −5px · vagues dash 6/9s opposées · oiseaux 6/7.5s | ease-in-out / linear | — | — |
| 12 | Chevron accordéon / select | Toggle | 200ms | — | rotate(0) | rotate(180deg) |
| 13 | Toast | Apparition | 220ms `shPop` ; auto-dismiss 2.8s | marque | | |
| 14 | Press boutons | :active | instantané | — | — | translateY(0.5px) ou scale(.96) |
| 15 | Hover | :hover | 120–200ms | — | — | fond +1 cran / ombre xs→sm |

## Fermetures
Le prototype ferme les modals sans animation de sortie. **Production : jouer l'inverse** — sheet redescend 250ms, dialogue fade+scale 150ms, overlay fade-out 150ms.

## Notes d'implémentation
- Le morphing `d: path()` fonctionne sur Chrome/Edge/Firefox ; Safari ancien : changement sec acceptable (fallback documenté).
- Les titres header rejouent leur animation à chaque navigation : en React, re-monter le nœud via une `key` liée à l'onglet.
- Une seule boucle visible à la fois par écran (pulse OU illustration).
