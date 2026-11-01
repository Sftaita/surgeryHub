# Mes heures — app instrumentiste (révision 3, 2026-10-31)

> **Version de référence figée pour l'implémentation.** Toute modification = nouvelle révision demandée explicitement.

Nouvel écran mensuel : heures **encodées** (en attente + validées) et heures **validées**, la liste des prestations et, si l'instrumentiste est payé à l'heure, une **estimation indicative** calculée prestation par prestation. Un filtre par site s'applique aux compteurs, aux montants et à la liste. Chaque prestation se lit **DÉBUT | DURÉE TOTALE | FIN**, la durée étant calculée depuis les heures encodées (passage de minuit compris). Aucune gestion de pause.

- `src/MesHeures.tsx` + `mes-heures.css` : l'écran (mobile + desktop, bascule à 900px)
- `src/tokens.css` : variables CSS des tokens, générées depuis `docs/design/design-tokens.json` (à importer une fois globalement)
- `src/summarize.ts` : durée, sites, filtre, totaux et estimation (fonctions pures)
- `src/summarize.test.ts` : les tests Vitest, sur les données d'octobre de la maquette
- `src/format.ts` : les formats `82h30`, `07h30`, `3 482,75 €`, marqueur `+1j`
- `src/fixtures.ts` : les données de la maquette
- `src/example.*` : l'adaptateur API et la page d'exemple (à adapter, ne pas copier tel quel)

Les instructions complètes sont dans **CLAUDE.md** (historique des révisions : § 9). Maquettes : canevas « Mes heures — maquettes » (https://claude.ai/artifact/UM3L8SuFx6bnAjT3RhwgYc).

Aucune modification backend n'est prévue.
