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
