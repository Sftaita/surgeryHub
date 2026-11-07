# Heures instrumentistes — module manager (révision 3, 2026-11-03)

> **Version de référence figée pour l'implémentation (révision 3).** Décisions 1–6 et convention d'historique observable (CLAUDE.md § 8) validées le 2026-11-03. Toute modification = nouvelle révision demandée explicitement.

Module manager construit sur les données et les règles de « Mes heures » (D-179) :

1. **Dashboard manager** — carte « Top 5 des instrumentistes » dans « Classements » : sélecteur de mois, 5 instrumentistes classées par heures validées décroissantes, barre proportionnelle, heures validées et coût estimé validé, évolutions vs mois précédent et vs moyenne personnelle, lien « En savoir plus ».
2. **Page `Facturation → Heures instrumentistes`** — 4 indicateurs (encodées, validées, en attente, coût estimé validé), tableau de toutes les instrumentistes, navigation mensuelle, filtre site, recherche, tri. Les totaux suivent exactement les filtres actifs.
3. **Tiroir individuel** — récapitulatif, comparaisons, tarifs appliqués (horaire ≠ forfait consultation) et prestations du mois en piste **DÉBUT | DURÉE TOTALE | FIN**.

Contenu du dossier :

- `CLAUDE.md` : instructions d'intégration complètes (règles, contrat API proposé, composants, états, écarts avec l'existant, checklist, historique).
- `src/types.ts` : types TypeScript du contrat API proposé (liste + détail).
- `src/fixtures.ts` : données des maquettes au format de l'API proposée (octobre 2026, cohérentes au centime et à la minute).
- `src/heures-instrumentistes.css` : feuille des maquettes — **référence des valeurs visuelles**, pas une feuille à importer telle quelle (voir CLAUDE.md § 4).

Maquettes : canevas « Suivi des heures — maquettes » (https://claude.ai/artifact/PxtCiGiMguGeyMBrXNip2T, privé — à partager depuis le menu Share). Artboards : **1a** carte desktop · **1b** carte mobile · **1c** états de la carte · **2a** page desktop · **2b** page mobile · **2c** états de la page · **3a** tiroir desktop · **3b** détail mobile · **4** handoff.

Aucune modification backend, frontend ni base de données n'a été faite à ce stade (phase de conception).
