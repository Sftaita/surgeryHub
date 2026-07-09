# docs/design/screens — index

## Écrans designés (documentation complète)
| Dossier | Contenu |
|---|---|
| `login/` | README, pixel-audit, mobile/desktop.png (+ annotées) |
| `aujourdhui/` | idem (état journée libre décrit, pas de capture) |
| `offres/` | idem |
| `planning-semaine/` · `planning-mois/` | idem |
| `modal-jour/` | idem |
| `encodage/` | idem |
| `heures-prestees/` | idem |
| `declarer-mission/` | idem |
| `ajout-materiel/` | README + pixel-audit (flux interactif — référence = prototype, pas de capture statique) |

Sheets « Nouvelle intervention » et « Récapitulatif » : documentés dans `sheets-divers.md` + `encodage/README.md`.

## Écrans NON designés (placeholders « ne pas implémenter »)
`dashboard/`, `planning-v2/`, `planning-preview/`, `planning-alerts/`, `missions/`, `mission-detail/`, `surgeons/`, `instrumentists/`, `hospitals/`, `notifications/`, `settings/` — chaque dossier contient un README explicite. **Aucune implémentation sans passe de design préalable.**

## Cas particulier : `profile/`
Implémenté en code (pré-existant), mais sans maquette pour son agencement général — seule sa photo de profil est couverte par une référence design (`components/avatar.md`). Ni « designé » ni « à ne pas toucher » : voir `profile/README.md` avant toute modification.

## Captures — conventions
- `mobile.png` : layout mobile forcé, 390px de large (hauteur ~530px — la page continue en scroll ; le prototype est la référence exacte).
- `desktop.png` : layout desktop ~909px (sidebar).
- **`tablet.png` : volontairement absent.** Une seule bascule responsive à 900px : tablette paysage = desktop, tablette portrait = mobile. Créer une capture tablette dupliquerait desktop.png. (Décision documentée — ne pas la traiter comme un oubli.)
- `*-annotated.png` : annotations légères — guides de structure (gutters, sidebar, bandes) + repères numérotés ①②③… renvoyant aux sections du `pixel-audit.md`. Les cotes exactes sont TOUJOURS les valeurs numériques des fiches et de `design-tokens.json`, pas des mesures sur image.
- `states/` : pas de dossiers d'états — les états sont documentés en markdown dans chaque README (décision : ne pas designer loading/error/permission sans passe dédiée ; ne pas improviser).

## Règle absolue (rappel)
1. Lire le README de l'écran avant toute modification. 2. Comparer à la capture. 3. Corriger jusqu'à correspondance. 4. Ne jamais décider visuellement sans documentation — signaler les manques.
