# Overlay — Ajouter du matériel (wizard 3 étapes)

## Informations générales
- **Nom** : Ajouter du matériel · **Route** : overlay sur `/missions/:id/encodage` (contexte : une intervention) · **Composant React** : `MaterialWizard`
- **Layout** : SheetModal + stepper d'étapes · **Version** : v2 · **Date** : 2026-07-08

## Objectif
Ajouter une ligne de matériel : Marque → Matériel → Détails (quantité, commentaire). Voie de secours « Je ne trouve pas le matériel ».

## Capture officielle
**Pas de capture statique** — le flux est interactif : la référence est le prototype (`prototypes/SurgeryHub App v2.dc.html`, encodage → « + Ajouter du matériel »). Ne pas implémenter sans l'avoir parcouru.

## Hiérarchie visuelle
1. Titre + CloseButton — 2. Stepper d'étapes (Marque / Matériel / Détails) — 3. Contenu d'étape — 4. Actions.

## Structure
Patron SheetModal. Stepper : cercles 30 (fait `green-600`+coche · actif `green-600`+numéro · à venir blanc + inset ring 1.5 `gray-200`), libellés 12/600, connecteurs 2px, colonnes 76px. Titre d'étape « Étape N/3 – … » 14.5/700.
**Étape 1** : recherche 46 (loupe) · « MARQUES RÉCENTES » + chips outline 34 · « TOUTES LES MARQUES » + rangées 50 (séparées 1px `gray-100`) · Annuler.
**Étape 2** : encart `gray-50` marque sélectionnée + lien Changer · recherche 46 · « RÉSULTATS » : rangées nom 14/600 + marque 12 `gray-400` + bouton **+** rond 34 outline `green-500` · vide : « Aucun résultat… » · bouton « Je ne trouve pas le matériel » 48 outline (hover ambre).
**Étape 3** : encart matériel (icône package 44 `green-100`) · Quantité* : −/+ 48 + valeur 19/800 (min 1) · Commentaire (optionnel) 48 · CTA « Ajouter à l'intervention » 52 `green-700` · Retour.

## Composition
SheetModal · CloseButton · WizardSteps · SearchInput · Chips · ListRows · QuantityStepper · Field · Button.

## Design Tokens utilisés
Cercles `green-600` · rangées séparées `gray-100` · bouton + outline `green-500` · hover ambre `amber-50/600/700`. → `design-tokens.json`

## Responsive
Mobile sheet / desktop dialogue 480. Les listes scrollent dans le sheet (max-height 80vh).

## États
Étape 1/2/3 · recherche filtrante en direct · résultats vides · « je ne trouve pas » → ajout immédiat d'une ligne « Matériel non trouvé — à préciser » (badge ambre) + fermeture + toast · ajout normal → badge « Nouveau », accordéon ouvert, compteurs/horodatage mis à jour + toast.

## Interactions
Chips récentes = raccourci étape 2 · « Changer » = retour étape 1 (marque conservée nulle) · Retour étape 3→2 · X ferme à toute étape sans rien ajouter.

## Accessibilité
Étapes annoncées (« Étape 2 sur 3 ») · boutons + avec aria-label (« Ajouter {matériel} ») · recherches labellisées.

## Contraintes (intouchables)
3 étapes exactement · voie « non trouvé » toujours disponible à l'étape 2 · quantité min 1 · aucune saisie libre de nom de matériel (sauf voie « non trouvé » qui reste générique).

## Checklist d'acceptation
☐ Conforme au prototype (flux complet) ☐ stepper d'étapes exact ☐ recherche en direct ☐ voie « non trouvé » ☐ badges Nouveau/À préciser ☐ compteurs + horodatage ☐ accessibilité ☐ aucune différence notable
