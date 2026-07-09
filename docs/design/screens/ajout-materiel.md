# Sheet — Ajouter du matériel (wizard 3 étapes)

## Correspondance
Overlay sur l'encodage · React : `MaterialWizard` · Patron : SheetModal + stepper d'étapes

## Objectif
Ajouter une ligne de matériel à une intervention : Marque → Matériel → Détails.

## Étape 1/3 — Marque
Recherche 46px (loupe, filtre en direct) · eyebrow « MARQUES RÉCENTES » + chips outline 34px (Arthrex, Smith+Nephew, Stryker, DePuy Synthes) · eyebrow « TOUTES LES MARQUES » + liste rangées 50px (nom 14.5/600 + chevron, séparées 1px `gray-100`, + « Autres marques ») · fantôme Annuler.

## Étape 2/3 — Matériel
Encart `gray-50` « Marque sélectionnée : {marque} » + lien **Changer** (→ étape 1) · recherche 46px · eyebrow « RÉSULTATS » + rangées : nom 14/600 + marque 12 `gray-400` + bouton **+** rond 34px outline vert (→ étape 3) · vide : « Aucun résultat pour cette recherche. » · bouton outline « **Je ne trouve pas le matériel** » (hover ambre) → ajoute directement une ligne « Matériel non trouvé — à préciser » (badge À préciser) et ferme.

## Étape 3/3 — Détails
Encart matériel sélectionné (icône package 44px `green-100`) · **Quantité \*** : boutons −/+ 48px + valeur 19/800 (min 1) · **Commentaire (optionnel)** : input 48px · CTA « Ajouter à l'intervention » `green-700` · fantôme « Retour » (→ étape 2).

## Stepper d'étapes
Cercles 30px : fait = vert + coche · actif = vert + numéro · à venir = blanc + ring gris ; connecteurs 2px ; libellés Marque / Matériel / Détails.

## Effets
Ajout → ligne avec badge « Nouveau », accordéon ouvert, compteurs + horodatage brouillon mis à jour, toast.
