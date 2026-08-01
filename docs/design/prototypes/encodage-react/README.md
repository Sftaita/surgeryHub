# Encodage mission — React

Portage de l'écran d'encodage de `SurgeryHub App v2.dc.html` (photo hero, brouillon auto-enregistré, heures prestées, interventions/matériel, validation). Composants isolés, aucune dépendance tierce.

## Ouverture de l'écran
Deux points d'entrée dans l'app (identiques au prototype) : le CTA "Encoder la mission" sur la card Mission du jour, et le bouton "Encoder" sur la card ambre "À encoder". Les deux appellent `onOpen(missionId)` du composant parent — voir `EXAMPLE_USAGE.md`.

## `onClick` sur "Heures prestées" — ce qui s'ouvre et comment
Le bloc "Heures prestées" (`WorkedHoursRow.tsx`) est un **bouton pleine largeur**, pas un simple texte : icône horloge dans un rond vert clair, libellé + valeur ("Non renseignées" en gris tant que rien n'est saisi, ou "07h30 → 15h30 · 8h00" en noir une fois saisi), chevron à droite.

1. Au clic, `EncodeScreen` met `hoursSheetOpen = true` (state local) et pré-remplit le brouillon (`hoursDraft`) soit avec les heures déjà enregistrées, soit avec l'horaire prévu de la mission par défaut.
2. Cela **monte `WorkedHoursSheet`**, une modal : sur mobile un bottom sheet qui glisse depuis le bas (`slide-up 300ms`), sur desktop/tablette (≥900px, même hook `useIsDesktop` que la nav) un dialogue centré 460px qui apparaît en fondu+scale (`220ms`). Un fond assombri+flouté (`rgba(11,19,32,.52)` + blur) couvre le reste de l'écran ; cliquer dessus ferme la modal sans sauvegarder.
3. Dans la modal : rappel de l'horaire prévu, puis 3 `StepperRow` (Début / Fin / Pause, pas de 15 min, boutons −/+ 46px — jamais de champ clavier), une case "Se termine le lendemain (après minuit)" qui ajoute "+1j" à l'affichage de la fin et permet un total sur 2 jours, et un encart vert "Total presté" recalculé à chaque tap.
4. "Enregistrer les heures" appelle `onSave(hours)` : `EncodeScreen` met à jour `mission.hours`, ferme la modal, met à jour l'horodatage du brouillon ("Enregistré à HH:MM") et affiche un toast. Aucune sauvegarde manuelle n'existe ailleurs — c'est la seule action qui écrit les heures.
5. "Annuler" ferme sans rien changer.

## Section Interventions — tous les composants
- `InterventionsSection.tsx` — conteneur : eyebrow "INTERVENTIONS" + liste de `InterventionCard` + bouton "Ajouter une intervention" (ouvre `NewInterventionSheet`, un simple champ nom + CTA).
- `InterventionCard.tsx` — card accordéon compacte : en-tête cliquable (point vert, nom de l'intervention, compteur "{n} matériels" à droite, chevron qui pivote 200ms) ; déplié, elle affiche `MaterialLine` pour chaque matériel + le bouton "Ajouter du matériel" qui ouvre `MaterialSearchSheet` **pour cette intervention précise** (passée en contexte).
- `MaterialLine.tsx` — ligne compacte : nom + référence + badges "Nouveau"/"À préciser" à gauche, quantité `x{n}` avec boutons −/+ 26px à droite (jamais de gros stepper ici — volontairement dense, différent du `StepperRow` des heures).
- `MaterialSearchSheet.tsx` — modal recherche : champ de recherche unique (filtre dès la frappe), chips de marques horizontaux scrollables (ne montrent que les marques qui ont un résultat pour la requête tapée), liste de résultats filtrée en direct, clic sur un résultat = ajout immédiat (pas d'étape supplémentaire, pas de confirmation). Bouton "Matériel introuvable" en bas → bascule le même sheet vers un petit formulaire (nom + référence optionnelle) pour proposer un nouveau matériel ; ce cas est traité comme l'exception, pas le chemin principal.
- `NewInterventionSheet.tsx` — un champ (nom) + CTA "Ajouter l'intervention".
- `AddButton.tsx` — bouton outline vert générique ("+ Ajouter une intervention", etc.), réutilisé.

## Sauvegarde
Toute modification (heures, quantité, ajout de matériel, nouvelle intervention) passe par un des callbacks (`onSave`, `onAdd`, `onQtyChange`) qui met à jour l'état de la mission **immédiatement** et rafraîchit l'horodatage du brouillon affiché en haut de l'écran (`DraftBar.tsx`). Il n'y a **aucun bouton "Sauvegarder"** — seul "Valider l'encodage" (footer sticky, `StickyValidateFooter.tsx`) clôture la mission, et reste accessible à tout moment via `position: sticky` en bas de l'écran, au-dessus de la nav.

## Fichiers
```
src/
  types.ts                        // Mission, InterventionData, MaterialLineData, WorkedHours
  useIsDesktop.ts                 // même hook que le package nav (matchMedia 900px)
  mockData.ts
  EncodeScreen.tsx                // container : state, tous les callbacks
  components/
    EncodeHeader.tsx               // bandeau sombre, retour, "Mission #N", tags, horodatage brouillon
    MissionReadOnlyCard.tsx        // carte 1 : chirurgien, site/adresse, date/heure prévue — lecture seule
    WorkedHoursRow.tsx             // carte 2 : bouton "Heures prestées" (voir explication ci-dessus)
    WorkedHoursSheet.tsx           // modal heures (StepperRow x3 + case lendemain + total)
    InterventionsSection.tsx       // carte 3 : liste + bouton ajouter
    InterventionCard.tsx
    MaterialLine.tsx
    MaterialSearchSheet.tsx        // recherche + chips marques + "Matériel introuvable"
    NewInterventionSheet.tsx
    StickyValidateFooter.tsx       // carte 5 : CTA toujours visible
    Modal.tsx, StepperRow.tsx, AddButton.tsx, Toast.tsx   // primitives partagées
  hooks/useToast.ts
  styles.css
EXAMPLE_USAGE.md
```

## Câblage production
- `mockData.ts` → vos appels API (mission, catalogue matériel/marques, interventions).
- La recherche matériel est locale (filter en mémoire) dans la maquette — brancher votre endpoint de recherche si le catalogue est trop gros pour être chargé en une fois, en gardant le même debounce visuel (filtre à chaque frappe, pas de bouton "rechercher").
- "Matériel introuvable" doit déclencher côté API la création d'une demande dans l'écran admin "Matériel → Demandes" (déjà livré dans `handoff-regles-facturation-firmes` / package React associé) — mêmes champs (nom, référence, mission, instrumentiste).
