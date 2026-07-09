# Écran — Encodage mission

## Correspondance
Route : `/missions/:id/encodage` · React : `EncodeScreen` · Layout : AppLayout (header encodage spécifique, bottom-nav conservée) · Version : v2

## Objectif
Saisir après l'intervention : heures prestées, interventions réalisées, matériel utilisé ; puis clôturer.

## Structure
1. **Header encodage** : dégradé sombre, retour ←, eyebrow « ENCODAGE MISSION », « Mission #529 » 24/800, site + chirurgien, tags (date, type). Vague animée à l'arrivée (1.1/1.35s).
2. **Barre brouillon** (chevauche −28px) : « Brouillon en cours · N interventions · M matériels » + « Enregistré à HH:MM » (mis à jour à chaque action).
3. **Card Heures prestées** : cliquable → sheet heures ; « Non renseignées » `gray-400` → valeur `07h30 → 15h30 · 8h00` une fois saisie.
4. **Section INTERVENTIONS** : accordéons (voir `components/cards.md#card-intervention`) — lignes matériel, badges Nouveau / À préciser, « + Ajouter du matériel » → wizard.
5. **« Nouvelle intervention »** : bouton outline vert 50px → sheet nom (input + CTA).
6. **CTA « Terminer l'encodage »** 54px `green-800` → sheet récapitulatif.

## Sous-flux (voir fiches dédiées)
`heures-prestees.md`, `ajout-materiel.md`, `recap-validation.md`.

## États
Brouillon (permanent) · accordéons ouverts/fermés (LCA ouvert par défaut) · heures non renseignées/renseignées · lignes « à préciser » en attente.

## Entrées
CTA hero (mission du jour), bouton Encoder (card ambre), modal jour (actions Encoder). Deux missions démo : #529 (CHU Brugmann) et #514 (Saint-Jean).

## Fidelity
La bottom-nav reste visible (elle est la sortie de secours) · l'heure du brouillon change à chaque ajout · aucune donnée perdue en quittant.
