# Encodage mission — option 2b (React)

Composants React de la section d'encodage validée : **option « 2b »** de `Interventions Encodage v2.dc.html` — bandeau vert foncé, mécanique de progression, accordéon exclusif avec défilement automatique, suppression du matériel.

Remplace `InterventionCard.tsx`, `MaterialLine.tsx`, `InterventionsSection.tsx` et la ligne des heures du package `handoff-encodage-react`. Zéro dépendance tierce. Style 100 % tokens du design system.

> **Intégration par Claude Code : commencer par `CLAUDE.md`** (installation, règles à ne pas casser, checklist de recette).
> **Valeurs pixel : `DOCUMENTATION.md`** — chaque composant au pixel, les 4 paliers responsive, le défilement et les contraintes iOS / Android.

## Mise en place

```tsx
import { EncodingSection } from './handoff-encodage-interventions/src/EncodingSection';

<EncodingSection
  hours={mission.hours}                 // { start, end, breakMinutes, total } | undefined
  interventions={mission.interventions} // ordre chronologique de création — l'affichage inverse
  onOpenHours={() => setHoursSheetOpen(true)}
  onQtyInc={(i, m) => bumpQty(i, m, +1)}
  onQtyDec={(i, m) => bumpQty(i, m, -1)}
  onRemoveMaterial={(i, m) => confirmRemove(i, m)}
  onAddMaterial={(i) => openMaterialSheet(i)}
  onEditIntervention={(i) => openInterventionSheet(i)}
  onDeleteIntervention={(i) => confirmDeleteIntervention(i)}
  onAddIntervention={() => openNewInterventionSheet()}
  onValidate={() => finishEncoding()}
/>
```

Sous-composants utilisables séparément : `WorkedHoursCard`, `EncodingProgress`, `InterventionsList`, `InterventionCard`, `MaterialRow`, `QtyStepper`, `EncodingFooter`.

## Règles de comportement
0. **Deux zones distinctes** — « 1 TEMPS DE TRAVAIL » (bac gris, carte neutre) et « 2 MATÉRIEL PAR INTERVENTION » (bac vert clair, cartes à bandeau vert foncé). Le vert foncé n'appartient qu'aux interventions ; les heures n'en portent jamais.
1. **Accordéon exclusif** — ouvrir une intervention ferme les autres. Au chargement, la **dernière** intervention non encodée est dépliée.
2. **Ordre inversé** — dernière intervention en haut, n°1 en bas. Les numéros restent chronologiques (la carte du haut peut porter le n°3).
3. **Ajout en haut** — bouton « + Intervention » dans l'en-tête de liste. Aucun bouton d'ajout en pied.
4. **Ouverture/fermeture animée** — `max-height` mesurée au pixel + opacité (300ms), chevron pivoté (260ms). Voir DOCUMENTATION §5bis.
5. **Défilement automatique** — à l'ouverture, le haut de la carte monte en haut de l'écran (320ms) ; à la fermeture, on remonte en tête de liste. Implémenté sans `scrollIntoView` pour iOS/Android (voir DOCUMENTATION §6).
6. **Statut dérivé, jamais saisi** — au moins un matériel = « Complété » ; zéro = « À compléter » + encart ambre.
7. **Validation bloquée** tant qu'une intervention est vide.
8. **Suppression** — corbeille par ligne matériel et par intervention ; la confirmation appartient à l'appelant.

## Fichiers
```
CLAUDE.md                instructions d'intégration (à lire en premier)
DOCUMENTATION.md         spécification pixel par pixel
src/
  index.ts               barrel d'exports
  ExampleApp.tsx         exemple autonome (à supprimer après branchement)
  tokens.fallback.css    repli des tokens si le DS n'est pas chargé
  types.ts               MaterialLineData, InterventionData, WorkedHours
  EncodingSection.tsx    assemblage complet (les deux zones)
  WorkedHoursCard.tsx    bandeau "Heures prestées"
  EncodingProgress.tsx   compteur x/n + jauge segmentée + incitation
  InterventionsList.tsx  en-tête, bouton "+ Intervention", cartes (ordre inversé), auto-scroll
  InterventionCard.tsx   carte accordéon (statut, matériel, actions)
  MaterialRow.tsx        une référence (chip, badges, quantité, suppression)
  QtyStepper.tsx         quantité −/+
  EncodingFooter.tsx     "Terminer l'encodage · x/n" + Valider
  useCardScroll.ts       défilement animé compatible iOS/Android
  interventions.css      toutes les règles + les 4 modes d'affichage
  mockData.ts            jeu de données de la maquette
```

Prérequis : tokens du design system chargés (`tokens/colors.css`, `effects.css`, `fonts.css` — Inter). Valeurs de repli dans `DOCUMENTATION.md` §0.
