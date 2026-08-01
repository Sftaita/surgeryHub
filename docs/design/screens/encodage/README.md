# Écran — Encodage mission

## Informations générales
- **Nom** : Encodage mission (alias spec : encode-mission) · **Route** : `/missions/:id/encodage` · **Composant React** : `EncodeScreen`
- **Layout** : AppLayout avec header encodage propre (bottom-nav conservée) · **Sidebar** : desktop · **Version** : v2 · **Date** : 2026-07-08

## Objectif
Saisir après l'intervention : heures prestées, interventions, matériel ; clôturer via récapitulatif.

## Capture officielle
`mobile.png` · `desktop.png` (+ annotées). Sous-flux capturés séparément : `heures-prestees/`. Wizard matériel & récapitulatif : décrits dans `ajout-materiel/` et `sheets-divers.md` (pas de capture — prototype = référence).

**Référence exécutable (2026-07-16)** : `prototypes/encodage.html` — traduction 1:1 en HTML/CSS/JS vanilla du prototype propriétaire, écrite spécifiquement pour être lue par un agent de code (contrairement à `SurgeryHub App v2.dc.html`). Couvre l'écran + les 4 sheets (Heures prestées, Nouvelle intervention, Ajouter du matériel, Récapitulatif). En cas de doute sur une valeur ou un comportement de ce module, c'est la référence à ouvrir en premier.

## Hiérarchie visuelle
1. Header encodage (retour + Mission #N) — 2. Barre brouillon — 3. Card Heures prestées — 4. Section INTERVENTIONS (accordéons) — 5. Nouvelle intervention — 6. CTA Terminer l'encodage.

## Structure
Header : dégradé `encodeHeader`, coins bas 26 (mobile) / carte r24 margin 22 20 0 (desktop), padding 12 18 46 / 20 22 46 ; retour 40 blanc 12% ; eyebrow 12/800 `green-300` ; titre 24/800 ; tags pill 28 blanc 14%. Vague animée à l'arrivée (1.1/1.35s). Contenu : gutter 20, chevauche −28, gap 14. Barre brouillon r14 pad 12 15 shadow-md. Card heures r16 pad 14 16. Accordéons r16 (en-tête 15 16, lignes dashed, pied 46). Nouvelle intervention 50 outline vert. CTA 54 `green-800`.

## Composition
EncodeHeader · DraftBar · HoursCard · InterventionAccordion (badges Nouveau/À préciser) · MaterialWizard · NewInterventionSheet · WorkedHoursSheet · EncodeRecapSheet · Toast.

## Design Tokens utilisés
`gradient.encodeHeader` · `motion.encodeWaveArrival=[1100,1350]` · badges `green-100`/`amber-50` · CTA `green-800` + `ctaGreen`. → `design-tokens.json`

## Responsive
Desktop : header en carte, contenu 720 ; sheets → dialogues. Mobile : sheets bas. Bottom-nav visible dans les deux cas.

## États
Brouillon permanent (horodatage à chaque modification) · heures non renseignées (`gray-400`) / renseignées (valeur forte) · accordéons ouverts/fermés (1er ouvert) · lignes « À préciser » (ambre) · intervention vide (0 matériel).

## Interactions
Retour ← → écran précédent (vagues kick) · card heures → WorkedHoursSheet · « + Ajouter du matériel » → wizard (contexte intervention) · « Nouvelle intervention » → sheet nom · « Terminer l'encodage » → récapitulatif · chevrons accordéon 200ms.

## Accessibilité
Retour aria-label · accordéons `aria-expanded` · horodatage lisible (« Enregistré à 10:32 ») · compteurs tabular.

## Contraintes (intouchables)
Bottom-nav visible (sortie de secours) · horodatage mis à jour à chaque action · aucune donnée perdue en quittant · header plus sombre que le header principal (hiérarchie).

## Checklist d'acceptation
☐ mobile.png ☐ desktop.png ☐ tokens ☐ vague d'arrivée (1.1/1.35s) ☐ brouillon horodaté ☐ accordéons + badges ☐ sous-flux (wizard, heures, récap) ☐ accessibilité ☐ aucune différence notable

## Écarts connus vs. implémentation (audité 2026-07-16, corrigé le même jour)
`MissionEncodingPage` (route réelle `/app/i/missions/:id/encoding`) est conforme sur l'ensemble du flux : `EncodeHeader`, barre brouillon, accordéons `InterventionsSection`, wizard `MaterialWizard` (3 étapes, branché sur le vrai catalogue backend — la voie « non trouvé » ouvre en réalité `MaterialItemRequestDialog`, un vrai workflow de demande au manager, volontairement plus riche que le placeholder du prototype, cf. `docs/decisions.md` `MaterialItemRequest`), sheet **Heures prestées** (`EditServiceHoursDialog`, steppers ±15 min + case lendemain + total live) et sheet **Récapitulatif** (`SubmitDialog`, lignes Heures/Interventions/Matériel encodé/Matériel non trouvé + CTA « Valider et clôturer la mission »).

Note d'implémentation : `MissionSubmitRequest.noMaterial`/`.comment` existent toujours côté backend mais `MissionService::submit()` ne les lit jamais — le récapitulatif ne les collecte donc plus (aucune perte, ils étaient déjà morts). Autre écart assumé par rapport au prototype : `InstrumentistService.hours` ne stocke qu'une durée décimale (pas de start/end distincts), donc la sheet Heures repart toujours de l'horaire *prévu* de la mission à l'ouverture plutôt que de reconstruire un horaire déjà enregistré — impossible à partir d'une seule durée.

## Audit — nouvelle référence `encodage-react` (2026-07-20)

**Référence exécutable (2026-07-20)** : `prototypes/encodage-react/` — deuxième portage du même écran, cette fois en **composants React réels** (pas du HTML/JS vanilla) : `EncodeScreen.tsx` + 13 sous-composants, tokens identiques à `design-tokens.json`. Plus lisible/actionnable que `encodage.html` pour la structure des composants, mais **c'est une maquette générique** — elle ignore entièrement le modèle métier réel (référentiel fermé, cycle de vie backend, cohérence). Ne pas la suivre aveuglément : voir verdicts ci-dessous. En cas de conflit entre les deux références HTML/React, `encodage.html` reste la plus proche du prototype propriétaire source ; `encodage-react` est une proposition de restructuration, pas une autorité supérieure.

### Conforme — à adopter tel quel

| Élément | Détail |
|---|---|
| Tokens visuels | Couleurs/ombres/rayons identiques à `design-tokens.json` — aucun écart de palette. |
| Patron Modal (`Modal.tsx`) | Bottom sheet mobile (`sheetUp` 300ms) / dialogue centré desktop ≥900px (`dialogPop` 220ms) — identique à `SheetModal.tsx` déjà en prod. |
| Sheet Heures (`WorkedHoursSheet`) | StepperRow ×3 (Début/Fin/Pause, pas 15 min, jamais de clavier), case lendemain, encart total live — conforme à `heures-prestees/README.md` et à `EditServiceHoursDialog.tsx` existant. Câblage trivial. |
| `MissionReadOnlyCard` (nouveau) | Carte site/chirurgien/horaire prévu, lecture seule, sous le header. N'existait pas comme carte séparée (l'info était dans le header sombre) — améliore la lisibilité sans rien retirer côté métier. **Recommandé à intégrer.** |
| Chips « marques récentes » scrollables horizontalement | Amélioration ergonomique compatible avec `recentFirmIds` déjà exposé par `InterventionsSection.tsx`. |

### À adapter — la forme change, le fond métier reste

| Élément du handoff | Réalité métier à préserver en l'adaptant |
|---|---|
| `EncodeHeader` : « Enregistré à » déplacé dans le header (badge horloge), tags réduits à date+type | OK à adopter, mais le header perd le nom du site et le `personLine` (chirurgien+spécialité) qu'affiche `EncodeHeader.tsx` actuel — cette info doit être relogée dans `MissionReadOnlyCard` (elle y est déjà : avatar+chirurgien+spécialité, site+adresse). Vérifier qu'aucune info n'est perdue en pratique une fois les deux fusionnés. |
| Compteur « N interventions · M matériels » (barre brouillon actuelle) | Absent du nouveau EncodeHeader **et** absent de MissionReadOnlyCard — aucun composant du handoff ne le reprend. À décider : le garder (ex. sous l'eyebrow INTERVENTIONS) ou l'abandonner sciemment. Ne pas le perdre par omission. |
| Recherche matériel 1 champ + chips marques (`MaterialSearchSheet`) | Remplace le wizard 3 étapes. Le résultat final (chercher → cliquer → ajouté) est plus rapide, mais ajout **immédiat sans étape Quantité/Commentaire** : qty=1 fixe à la création, pas de champ commentaire du tout dans `MaterialLineData`. Le modèle réel (`EncodingMaterialLine.comment`, `CreateMaterialLineBody.comment`) a un commentaire optionnel utilisé (« utile pour préciser un lot, une taille » — `missionEncoding.ts` aide contextuelle). **Ne pas droper le commentaire** : soit le proposer aussi depuis la ligne (post-ajout, cf. ligne ci-dessous), soit garder une micro-étape "détails" optionnelle. |
| Quantité inline ±1 sur `MaterialLine` (26px, jamais de modal) | Remplace `EditMaterialLineDialog`. Ergonomiquement supérieur pour le cas courant (ajuster une quantité). Mais `EditMaterialLineDialog` est aussi le seul endroit où on édite le **commentaire** d'une ligne existante et où on déclenche sa **suppression** (`ConfirmDeleteDialog`) — le handoff ne propose ni l'un ni l'autre (`Math.max(1, qty+delta)` interdit même de redescendre à 0). Il faut composer les deux : stepper inline pour l'ajustement rapide + un point d'entrée (tap long ? bouton discret ?) vers modifier/supprimer, sinon régression fonctionnelle réelle (aujourd'hui : on peut supprimer une ligne mal ajoutée, demain : plus moyen). |

### Conflit métier — ne pas adopter sans trancher explicitement

| Élément du handoff | Règle métier contredite |
|---|---|
| `MaterialSearchSheet` = recherche unique + chips marques (fusionne Marque+Matériel en un seul écran) | Contredit `ajout-materiel/README.md` §Contraintes intouchables : **« 3 étapes exactement… ne jamais fusionner en un seul formulaire »** — règle déjà rappelée en commentaire dans `MaterialWizard.tsx`. Le handoff *encodage.html* (référence actuelle) respecte les 3 étapes ; *encodage-react* ne les respecte pas. Décision utilisateur requise avant tout portage de cette sheet. |
| `NewInterventionSheet` = un seul champ texte libre « Nom de l'intervention » | Contredit D-068 (Lot 5) : le type d'intervention vient d'un **référentiel fermé** (`CatalogInterventionType`), jamais d'un texte libre — c'est exactement ce que `AddInterventionDialog.tsx` corrige déjà par rapport au prototype HTML d'origine (`interventionTypeId` obligatoire + firme optionnelle + lien « Faire une demande au manager » si le type n'existe pas). Le nouveau handoff réintroduit le même raccourci texte libre que l'ancien — à ne pas reporter tel quel, réutiliser le picker fermé existant. |
| `StickyValidateFooter` → `onValidate()` direct, sans écran intermédiaire | Contredit `sheets-divers.md#Récapitulatif` : **« passage obligé avant la clôture, jamais un raccourci direct »** — et court-circuite `SubmitDialog.tsx` (résumé Heures/Interventions/Matériel/Non-trouvé avant `submitMission()`). Le handoff n'a tout simplement pas de composant récapitulatif. À conserver côté implémentation réelle. |
| Aucun statut/cycle de vie visible (Brouillon / Encodage en cours / Soumis / Validé), aucun signal de cohérence, aucun historique de commentaires manager | Le handoff ignore entièrement le Lot 7 (D-070, workflow explicite + verrouillage backend + commentaires reject/reopen) et le Lot 6 (signaux de cohérence informationnels). C'est `EncodingStatusPanel.tsx` aujourd'hui — composant métier réel sans équivalent dans la maquette générique. À garder intégralement, greffé sur la nouvelle mise en page. |
| Bouton « Démarrer l'encodage » absent | Invite optionnelle du Lot 7 (`startMissionEncoding()`, gated par `allowedActions.includes("start_encoding")`) — absente du handoff. À garder. |
| Aucun gating de visibilité sur le footer/actions | Le handoff affiche toujours le CTA de validation ; en prod il est conditionné à `allowedActions.includes("submit")` (et édition d'heures/matériel à `edit_hours`/`encoding`). Attendu d'une maquette générique — à ne pas copier tel quel, le gating réel doit rester la seule source de vérité. |
| Modifier/supprimer une intervention (type, firme principale) | Absent du handoff (`InterventionCard` n'a qu'un toggle + « Ajouter du matériel »). `EditInterventionDialog`/`ConfirmDeleteDialog` (intervention) sont des fonctions réelles existantes (corriger un type mal choisi, une firme erronée) — à ne pas perdre en portant la nouvelle carte accordéon. |

### Verdict

Le nouveau design est **compatible visuellement** (mêmes tokens, même patron de modal) et apporte de vraies améliorations d'ergonomie (carte mission dédiée, quantité inline, recherche matériel plus rapide). Il n'est **pas directement portable tel quel** : trois points touchent des règles métier documentées comme intouchables (wizard matériel 3 étapes, référentiel fermé d'intervention, récapitulatif obligatoire avant clôture) et plusieurs fonctions réelles du Lot 7 (statut/cohérence/commentaires manager, démarrage, édition/suppression) n'ont simplement pas d'équivalent dans la maquette. Rien de tout cela n'est un problème du handoff en soi — c'est une maquette générique, pas un cahier des charges métier — mais un portage naïf ferait régresser des fonctionnalités réelles.

### Implémentation (2026-07-20) — décisions actées et état réel

Les 3 conflits métier ont été tranchés explicitement avant portage :
- **Wizard matériel** → 3 étapes existantes conservées telles quelles (`MaterialWizard.tsx` non modifié).
- **Récapitulatif** → conservé obligatoire ; le nouveau CTA sticky (`StickyValidateFooter.tsx`) ouvre toujours `SubmitDialog`, ne clôture jamais directement.
- **Ligne de matériel** → stepper inline ±1 adopté pour l'ajustement rapide (`InterventionsSection.tsx`, boutons 26px), mais taper la ligne (hors stepper, `stopPropagation` sur les boutons) ouvre toujours `EditMaterialLineDialog` — seul point d'accès au commentaire et à la suppression (`ConfirmDeleteDialog`), qui restent inchangés.

Éléments adoptés du nouveau design : `MissionReadOnlyCard` (nouveau composant — chirurgien/spécialité + site/adresse + date/horaire prévu, remplace le texte brut de l'ancien `EncodeHeader`), horodatage « Enregistré à » déplacé dans le header (`EncodeHeader.savedAt`), CTA de clôture en position sticky. L'ancienne « barre brouillon » (pastille pulsante + statut texte) est supprimée — le statut est déjà porté par `EncodingStatusPanel` (Chip), qui n'a jamais été dupliqué. Le compteur « N interventions · M matériels » n'est pas perdu : relocalisé dans l'eyebrow INTERVENTIONS (`InterventionsSection.tsx`), à côté de la liste qu'il décrit.

Tout le reste du Lot 7 (statut, signaux de cohérence, commentaires manager, bouton Démarrer l'encodage, édition/suppression d'intervention, gating par `allowedActions`) est inchangé et vérifié fonctionnel en live (Sophie Collette, mission #690, 2026-07-20). 440/440 tests frontend verts.

### Justification obligatoire si aucun matériel encodé (2026-07-20, suite Lot 7)

**Règle métier :** lors de la clôture de l'encodage, si aucune ligne de matériel active
avec une quantité strictement positive n'est enregistrée, l'instrumentiste doit décrire
les interventions réellement réalisées. La vérification est effectuée côté serveur.

- Le champ commentaire apparaît uniquement dans `SubmitDialog` (le récapitulatif affiché
  avant clôture), jamais ailleurs dans l'écran d'encodage — ce n'est pas un commentaire
  général de mission/intervention/matériel.
- Le compte de lignes est recalculé côté serveur (`MissionEncodingWorkflowService::
  countActiveMaterialLines()`), jamais déduit d'un flag envoyé par le client : source de
  vérité unique.
- Deux colonnes distinctes sur `Mission` portent cette règle : `no_material_comment`
  (texte, conservé indéfiniment pour traçabilité, jamais effacé automatiquement) et
  `submitted_without_material` (booléen, figé à chaque clôture — reflète la soumission
  courante, pas un état dérivé). Une resoumission ultérieure avec du matériel repasse ce
  flag à `false` sans exiger de nouveau commentaire ; l'ancien commentaire reste en base
  mais n'est plus présenté comme justification active.
- Affichage manager (`MissionDetailPage`) : le bloc **« Aucun matériel encodé —
  justification de l'instrumentiste »** n'apparaît que si `submittedWithoutMaterial ===
  true` pour la soumission courante — jamais sur la seule présence du commentaire.
- Une fois la mission verrouillée (`encodingLockedAt`, posé par `validate()`),
  `MissionEncodingGuard` bloque toute nouvelle transition `complete()` : ni le commentaire
  ni le flag ne peuvent plus être modifiés après verrouillage, sans mécanisme dédié
  supplémentaire.

Voir D-070 (`docs/decisions.md`) pour la décision architecturale et `docs/api.md` pour le
contrat des endpoints `submit`/`encoding/complete` et des champs de `MissionDetailDto`.
