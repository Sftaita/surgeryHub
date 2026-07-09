# Sheets — Récapitulatif, Déclaration, Nouvelle intervention, Modal jour

## Récapitulatif avant validation (`EncodeRecapSheet`)
Titre + X. Lignes (icône cercle 36px + libellé 14/600 + valeur 14/700, séparées dashed) :
- **Heures** — ambre « Non renseignées » OU vert + valeur tabular.
- **Interventions** — nombre.
- **Matériel encodé** — « N lignes ».
- **Matériel non trouvé** — ambre, seulement si ≥ 1 ligne « à préciser ».
Texte d'aide 13px muted, CTA « Valider et clôturer la mission » `green-800`, fantôme « Continuer l'encodage ».
Valider → ferme tout, retour accueil (vagues kick), toast « Mission clôturée. Encodage transmis à l'établissement. »

## Déclarer une mission (`DeclareMissionSheet`)
Depuis le bouton dashed de l'accueil. Titre + X + sous-titre « Mission effectuée hors plateforme ou non prévue au planning. »
1. SelectField **Site \*** · **Chirurgien \*** · **Type** (Bloc opératoire / Stérilisation).
2. Séparateur dashed.
3. StepperRow **Date** (±1 jour, max aujourd'hui, format « Mar. 7 juillet ») · **Début** · **Fin** (±15 min) · Checkbox lendemain.
4. Encart vert **Durée** (calculée).
5. Input **Commentaire (optionnel)**.
CTA « Déclarer la mission » — validation douce : site + chirurgien requis sinon toast « Sélectionnez un site et un chirurgien. » Succès → toast « Mission déclarée. En attente de validation par l'établissement. »

## Nouvelle intervention (`NewInterventionSheet`)
Titre + X · label « Nom de l'intervention » + input 50px (placeholder « Ex. Ménisque médial ») · CTA « Ajouter l'intervention » `green-700`. Ajout → accordéon ouvert en fin de liste, brouillon horodaté.

## Modal jour (`DayModal`)
Depuis semaine / mois / cards à venir. Eyebrow « PLANNING » + titre date (« Dimanche 5 juillet ») 20/800 + X. Par mission : rangée `gray-50` radius 14 (icône map-pin cercle teinté statut + site 14.5/700 + sous-titre 13 muted tabular + StatusPill) + action contextuelle pleine largeur si applicable (En cours → « Voir le détail de la mission » vert `green-500` ; À encoder → « Encoder la mission » `amber-600` → écran encodage).
