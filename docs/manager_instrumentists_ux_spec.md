# SurgicalHub — UX Manager « Gestion des instrumentistes »

Version: v1.0  
Date: 2026-03-11  
Statut: **socle fonctionnel validé pour implémentation frontend**

---

## 1. Objet du document

Ce document formalise l’UX **manager** pour la **gestion, la consultation et l’ajout des instrumentistes** dans SurgicalHub.

Il synthétise les décisions prises pour servir de **base de mise en œuvre frontend** dans le projet actuel React/TypeScript.

Ce document couvre uniquement le périmètre suivant :

- accès manager à la ressource « Instrumentistes »
- listing des instrumentistes
- création rapide d’un instrumentiste
- gestion des affiliations site ↔ instrumentiste
- consultation d’une fiche instrumentiste via drawer
- édition des tarifs avec enregistrement automatique
- gestion du statut actif / suspendu
- visualisation du planning instrumentiste
- création de mission depuis le planning de la fiche instrumentiste
- invitation du nouvel instrumentiste à compléter son compte

Ce document **ne couvre pas** :

- l’UX instrumentiste
- le planning manager global
- la facturation détaillée
- la gestion complète des chirurgiens
- les règles backend exhaustives de persistance si elles ne sont pas encore exposées par l’API

---

## 2. Références source

Ce socle UX est aligné avec les documents métier et techniques existants du projet :

- `PRD_Gestion_Materiel_Operatoire_PWA_v6.pdf`
- `architecture.md`
- `api.md`
- `spec.md`
- `decisions.md`
- `Planning Instrumentiste v1.0 – Spécification fonctionnelle validée.md`

Principes structurants repris de ces documents :

1. application **manager-centric** pour le pilotage du planning et de la gouvernance
2. **RBAC strict** et aucun droit inféré côté frontend
3. **allowedActions[]** comme source de vérité pour les actions métier
4. architecture **mission-centric**, sans donnée patient
5. nécessité de gérer des instrumentistes **multi-sites** avec distinctions d’affiliation
6. planning pensé comme outil de **lecture / navigation**, puis ouverture du détail mission

---

## 3. Positionnement UX

### 3.1 Intention produit

L’UX manager de gestion des instrumentistes doit être :

- **rapide** à lire
- **peu chargée** visuellement
- **orientée opérationnel**
- **centrée sur la consultation rapide et l’édition immédiate**
- cohérente avec l’approche globale SurgicalHub

L’écran ne doit pas devenir un ERP dense.  
Le manager doit pouvoir :

- retrouver un instrumentiste rapidement
- comprendre immédiatement son statut
- voir ses sites
- ajuster ses paramètres clés
- consulter son planning
- créer rapidement une mission pour lui

### 3.2 Philosophie UX retenue

La logique validée est la suivante :

- **liste simple à gauche / centre**
- **détail rapide dans un drawer à droite**
- **édition inline quand c’est simple**
- **modal uniquement quand une confirmation est nécessaire**
- **calendrier intégré à la fiche** pour pilotage opérationnel

---

## 4. Place dans l’architecture de navigation manager

### 4.1 Arborescence retenue

La gestion des instrumentistes est placée dans le menu manager sous :

```text
Dashboard
Planning
Missions
Ressources
  ├─ Instrumentistes
  ├─ Chirurgiens
  ├─ Sites
Catalogue
Facturation
4.2 Justification

Ce choix sépare correctement :

l’opérationnel quotidien (Planning, Missions)

des entités système (Ressources)

Cela évite de mélanger la gestion des personnes avec le planning global.

5. Périmètre fonctionnel manager

L’écran « Instrumentistes » doit permettre au manager de :

voir la liste des instrumentistes

filtrer et retrouver rapidement un instrumentiste

ouvrir une fiche détaillée non bloquante

créer rapidement un nouvel instrumentiste

gérer les affiliations par site

éditer les tarifs bloc / consultation

suspendre / réactiver un instrumentiste

visualiser son planning

créer une mission directement depuis son planning

inviter le nouvel instrumentiste à compléter son compte

6. Écran liste — Ressources > Instrumentistes
6.1 Objectif

La liste doit rester volontairement minimaliste.

Elle n’a pas pour but d’afficher tout le modèle métier, mais de servir de point d’entrée stable vers la fiche instrumentiste.

6.2 Colonnes retenues

La table liste contient :

Nom

Statut (Active / Suspended)

Type (Freelance / Employee)

Sites

Actions (Ouvrir)

6.3 Exemple de rendu
Nom              Statut      Type         Sites              Actions
Ole Salve        Active      Freelance    Delta, Parc        Ouvrir
Diane Morel      Active      Employee     Delta              Ouvrir
Julien Martin    Suspended   Freelance    BOSI               Ouvrir
6.4 Règles UX

La liste doit être lisible en un coup d’œil.

Aucun bloc d’information financière détaillée n’apparaît dans la liste.

Aucun mini-dashboard complexe n’apparaît dans la liste.

Les détails riches sont déplacés dans le drawer.

6.5 Filtres recommandés

Même si non arbitrés en détail lors de la séance UX, l’écran devrait prévoir à minima :

recherche texte par nom / email

filtre Statut

filtre Type

filtre Site

Ces filtres doivent rester légers et cohérents avec les autres écrans manager.

7. Ajout d’un instrumentiste
7.1 Principe retenu

L’ajout se fait via modal rapide, depuis la liste.

Le but est :

de réduire la friction d’entrée

de créer vite un profil minimal

puis de compléter la configuration dans la fiche instrumentiste

7.2 CTA principal

En haut de l’écran liste :

+ Instrumentiste
7.3 Contenu de la modal de création
Créer instrumentiste

Nom
Email
Type
  ○ Freelance
  ○ Employee

Sites autorisés
  □ Delta
  □ Parc Léopold
  □ BOSI

[ Annuler ]   [ Créer ]
7.4 Règles UX

La modal doit rester courte.

Elle ne doit pas contenir les tarifs.

Elle ne doit pas exposer les paramètres avancés.

Après création, la fiche instrumentiste peut être ouverte automatiquement dans le drawer.

7.5 Règles de flux

Après validation :

création du profil instrumentiste

création éventuelle des affiliations initiales

envoi d’une invitation au nouvel instrumentiste

refresh de la liste

ouverture optionnelle du drawer du nouvel instrumentiste

7.6 Erreurs à gérer

email déjà utilisé

données invalides

absence de type

aucun site sélectionné si le backend exige au moins une affiliation

échec d’envoi de l’email d’invitation

7.7 Comportement attendu après création

Le compte créé par manager doit suivre le flux suivant :

le compte instrumentiste est créé côté backend

un lien sécurisé est envoyé par email

l’instrumentiste doit ouvrir ce lien

il doit compléter son profil

il doit définir son mot de passe

Le manager n’a donc pas à définir lui-même le mot de passe du nouvel instrumentiste.

7.8 Feedback UX recommandé après création

Après succès de la création :

✓ Instrumentiste créé
Invitation envoyée

Si le compte est créé mais que l’email échoue :

⚠ Instrumentiste créé, mais l’invitation n’a pas pu être envoyée

Cette distinction est importante pour le manager.

8. Gestion des sites / affiliations
8.1 Modèle UX retenu

Les sites ne sont pas gérés comme un simple champ texte.
Ils sont gérés dans la fiche instrumentiste sous forme de table des affiliations.

8.2 Structure de la section
Sites
--------------------------------
Site            Type
Delta           Employee
Parc Léopold    Freelance
BOSI            Freelance

Actions disponibles :

+ Ajouter un site
Modifier
Supprimer
8.3 Formulaire d’ajout d’affiliation
Ajouter une affiliation

Site
[ sélectionner ]

Type d’affiliation
  ○ Employee
  ○ Freelance

[ Annuler ]   [ Ajouter ]
8.4 Règles UX

Un même instrumentiste peut avoir des affiliations différentes selon le site.

L’UX doit permettre explicitement le cas :

Employee sur un site

Freelance sur un autre

La liste des affiliations doit être visible d’un seul coup d’œil.

8.5 Règles métier à respecter

Le frontend doit rester compatible avec les contraintes backend d’éligibilité site ↔ instrumentiste.

Le frontend ne doit jamais déduire lui-même une autorisation métier sur base des affiliations ; il affiche et propose, le backend tranche.

9. Fiche instrumentiste — ouverture dans un drawer
9.1 Principe retenu

La fiche instrumentiste s’ouvre dans un drawer latéral droit.

Le manager ne quitte pas la liste.

9.2 Objectifs

navigation rapide entre profils

conservation du contexte liste

comparaison fluide entre instrumentistes

réduction des changements de page

9.3 Structure générale du drawer

Proposition de structure :

[Header]
Nom instrumentiste
Type principal
Statut

[Section] Sites
[Section] Tarifs
[Section] Statut
[Section] Planning
[Section] Actions secondaires
9.4 Header du drawer

Le header doit afficher au minimum :

nom complet

statut visuel (Active / Suspended)

type principal ou type dominant si disponible

Exemple :

Ole Salve
Freelance
Active
9.5 Contraintes UX

le drawer doit être scrollable

la largeur doit rester confortable desktop

le drawer ne doit pas se substituer à une page complète complexe

si une future extension devient trop volumineuse, un lien vers une page dédiée pourra être ajouté plus tard

10. Tarifs instrumentiste
10.1 Principe retenu

Les tarifs sont visibles et modifiables directement dans le drawer.

Les champs concernés sont :

Bloc opératoire

Consultation

10.2 Rendu UX
Tarifs

Bloc opératoire       [ 350 € ]
Consultation          [ 120 € ]
10.3 Mode d’édition retenu

édition inline

sans bouton Sauvegarder

enregistrement automatique après modification

10.4 Déclenchement d’enregistrement

L’autosave se déclenche sur :

perte de focus (blur)

ou validation par Enter si souhaité

10.5 Feedback visuel attendu

Pendant enregistrement :

Enregistrement…

Après succès :

✓ Enregistré

Après erreur :

⚠ Impossible d’enregistrer
[ Réessayer ]
10.6 Règles UX importantes

pas de bouton global Sauvegarder

feedback discret, non intrusif

un champ ne doit pas bloquer l’autre

l’utilisateur doit sentir une UI moderne et fluide

10.7 Règles de robustesse

debounce léger possible si besoin

rollback visuel possible en cas d’échec

état local dirty / saving / saved / error recommandé par champ

11. Statut instrumentiste — actif / suspendu
11.1 Principe retenu

Le statut est géré via un toggle simple, enrichi par une aide contextuelle et une confirmation.

11.2 Rendu UX
Statut

Active  [ ON ]   (?)

ou

Suspended  [ OFF ]   (?)
11.3 Rôle du ?

Le ? ouvre une aide contextuelle expliquant clairement les conséquences métier.

11.4 Contenu attendu du tooltip / popover d’aide
Statut de l’instrumentiste

Active :
• l’instrumentiste peut voir ses missions
• l’instrumentiste peut claim des missions
• l’instrumentiste peut déclarer des missions
• l’instrumentiste peut encoder ses missions

Suspended :
• l’instrumentiste ne peut plus claim de nouvelles missions
• l’instrumentiste ne peut plus déclarer de nouvelles missions

Les missions déjà assignées restent visibles et peuvent être terminées.
11.5 Confirmation obligatoire

Quand le manager bascule le toggle, une modal de confirmation doit apparaître.

Exemple suspension
Suspendre cet instrumentiste ?

Ole Salve sera suspendu.

Conséquences :
• Il ne pourra plus claim de nouvelles missions
• Il ne pourra plus déclarer de missions
• Il ne sera plus visible dans les listes d’attribution

Les missions déjà assignées restent inchangées.

[ Annuler ]   [ Suspendre ]
Exemple réactivation
Réactiver cet instrumentiste ?

Ole Salve pourra à nouveau :
• claim des missions
• déclarer des missions
• apparaître dans les listes d’attribution

[ Annuler ]   [ Réactiver ]
11.6 Feedback après succès
✓ Statut mis à jour
11.7 Règles UX

le toggle seul ne doit pas suffire sans confirmation

la formulation doit être en français métier clair

l’impact doit être compris avant validation

12. Planning instrumentiste dans la fiche
12.1 Principe retenu

Le manager doit voir le planning de l’instrumentiste directement dans le drawer, via FullCalendar.

Deux vues sont retenues :

vue Mois

vue Liste

12.2 Intention produit

Le planning dans la fiche sert à :

visualiser la charge de travail

repérer les jours occupés

lire le flux chronologique des missions

ouvrir rapidement une mission

créer rapidement une mission pour cet instrumentiste

12.3 Structure de section
Planning

[ Mois ] [ Liste ]

[ FullCalendar ]
12.4 Vue par défaut

La vue Mois est recommandée par défaut.

Raison :

meilleure lecture macro

cohérence avec la philosophie du planning existant

bonne visibilité dans un espace contraint

12.5 Vue Liste

La vue Liste sert à une lecture chronologique simple.

Exemple :

12/03/2026 — Delta — Dr Martin — 08:00–12:00
12/03/2026 — Parc Léopold — Dr Dupont — 13:00–18:00
14/03/2026 — BOSI — Consultation — 09:00–12:00
12.6 Interaction sur les événements

Le clic sur une mission dans le calendrier doit :

ouvrir la modal détail mission existante

ne pas introduire de logique métier spécifique au calendrier

Le calendrier reste donc un outil de lecture et d’accès, non un moteur autonome de décision.

12.7 Règles d’affichage

affichage sobre

pas de surcharge de statuts

possibilité d’indication légère pour DECLARED si nécessaire

aucune donnée patient

pas d’inférence de droit côté calendrier

12.8 Contraintes techniques UX

Comme le calendrier vit dans un drawer :

hauteur fixe et scroll interne maîtrisé

toolbar simplifiée

responsive desktop prioritaire

lien optionnel futur Ouvrir le planning complet si besoin d’extension

13. Création de mission depuis le planning instrumentiste
13.1 Principe retenu

Depuis le planning de la fiche instrumentiste, le manager peut :

cliquer un jour vide

cliquer une plage horaire vide

cliquer un bouton + Nouvelle mission

Cela ouvre une modal de création de mission préremplie avec l’instrumentiste courant.

13.2 Objectif produit

Réduire le nombre d’étapes pour affecter rapidement un instrumentiste déjà consulté.

13.3 Formulaire de création
Créer mission

Instrumentiste
Ole Salve

Site
[ sélectionner ]

Chirurgien
[ sélectionner ]

Type
○ Bloc opératoire
○ Consultation

Date
14/03/2026

Heure début
[ ]

Heure fin
[ ]

Créer la mission comme :
○ Créer et assigner
○ Créer en brouillon

[ Annuler ]   [ Créer ]
13.4 Champ prérempli obligatoire

Le champ instrumentiste est prérempli avec le profil actuellement ouvert.

Instrumentiste = profil courant
13.5 Choix de statut initial retenu

Le manager choisit entre :

Créer et assigner

Créer en brouillon

a. Créer et assigner

Usage recommandé quand le manager sait déjà que la mission doit être directement attachée à cet instrumentiste.

b. Créer en brouillon

Usage recommandé si le manager veut encore ajuster la mission avant publication / validation interne.

13.6 Valeur par défaut recommandée

La valeur par défaut recommandée est :

Créer et assigner

Raison : si le manager crée depuis la fiche d’un instrumentiste, il part généralement d’une intention d’affectation directe.

13.7 Règles UX

la création doit être rapide

le contexte instrumentiste ne doit pas être perdu

le formulaire doit rester plus léger que l’écran complet de création mission

un lien futur vers l’écran complet de création pourra exister si nécessaire

14. Invitation et complétion de compte instrumentiste
14.1 Principe retenu

Lorsqu’un manager crée un instrumentiste, le compte n’est pas considéré comme entièrement finalisé tant que l’instrumentiste n’a pas :

ouvert le lien reçu par email

complété son profil

défini son mot de passe

Le manager initie donc la création, mais le nouvel instrumentiste finalise lui-même son accès.

14.2 Intention UX

Ce flux permet :

d’éviter que le manager définisse un mot de passe à la place de l’utilisateur

de responsabiliser l’instrumentiste sur son accès

de préparer un onboarding propre

de garder une UX simple côté manager

14.3 Comportement attendu côté manager

Après création :

le manager voit que le profil a été créé

il est informé qu’une invitation a été envoyée

il n’a pas à effectuer d’action supplémentaire immédiate

Le frontend manager peut afficher un état d’information du type :

Invitation envoyée

ou, si besoin plus tard :

En attente de complétion
14.4 Comportement attendu côté instrumentiste

Le lien d’invitation ouvre un écran frontend dédié permettant à l’instrumentiste de compléter :

prénom

nom

téléphone obligatoire

mot de passe

confirmation mot de passe

photo de profil optionnelle

14.5 Cas d’erreur à prévoir côté UX

Le frontend devra prévoir les cas suivants :

lien invalide

lien expiré

compte déjà activé

erreur technique lors de la finalisation

Exemples de messages possibles :

Ce lien d’invitation n’est plus valide.
Ce compte a déjà été activé.
14.6 Positionnement dans le périmètre du document

Ce document ne décrit pas l’écran instrumentiste de complétion en détail, mais il doit bien intégrer le fait que :

la création manager inclut une invitation

la finalisation du compte se fait côté frontend via lien sécurisé

15. Non-objectifs UX explicites

Pour ce module, il est explicitement exclu de :

transformer le drawer en page ERP exhaustive

faire de l’édition calendrier complexe par drag & drop métier

déduire les droits utilisateur côté frontend

mélanger la gestion des instrumentistes avec la facturation détaillée

afficher des informations patient

16. Traduction frontend dans la structure actuelle
16.1 Constat sur l’arborescence existante

Le frontend actuel contient déjà :

app/pages/manager/MissionsListPage.tsx

app/pages/manager/MissionCreatePage.tsx

app/pages/manager/MissionDetailPage.tsx

app/features/missions/...

app/features/sites/api/sites.api.ts

Il n’existe pas encore, d’après l’arborescence fournie, de module dédié manager/instrumentists.

16.2 Structure cible recommandée

Créer un nouveau module dédié :

app/
  features/
    manager-instrumentists/
      api/
        instrumentists.api.ts
        instrumentists.types.ts
        instrumentists.requests.ts
      components/
        InstrumentistsTable.tsx
        InstrumentistsFiltersBar.tsx
        CreateInstrumentistDialog.tsx
        InstrumentistDrawer.tsx
        InstrumentistSitesSection.tsx
        AddInstrumentistSiteDialog.tsx
        InstrumentistRatesSection.tsx
        InstrumentistStatusSection.tsx
        InstrumentistPlanningSection.tsx
        CreateMissionFromInstrumentistDialog.tsx
      utils/
        instrumentists.format.ts
  pages/
    manager/
      InstrumentistsPage.tsx
16.3 Routage cible recommandé

Ajouter une route manager de type :

/manager/instrumentists

La page doit être protégée par les guards manager/admin existants.

16.4 Réutilisation attendue

Réutiliser autant que possible :

composants UI existants de modal / toast

infrastructure React Query existante

MissionDetailPage ou modal détail mission existante

APIs sites existantes pour sélection de sites si compatible

composants FullCalendar déjà intégrés ailleurs si présents dans le lot planning

16.5 Écran frontend complémentaire à prévoir

En complément de l’espace manager, prévoir un écran frontend dédié à la complétion d’invitation instrumentiste, par exemple :

/complete-account?token=...

Cet écran n’appartient pas au module manager lui-même, mais il fait partie du flux global validé.

17. État frontend recommandé
17.1 Sur la page liste

États recommandés :

filters

search

selectedInstrumentistId

isCreateDialogOpen

isDrawerOpen

17.2 Dans le drawer

États recommandés :

instrumentistDetail

sites

rates

status

planningView (month / list)

isCreateMissionDialogOpen

17.3 Pour l’autosave des tarifs

Par champ :

idle

dirty

saving

saved

error

17.4 Pour la création + invitation

États complémentaires recommandés :

createStatus

invitationSendStatus

createWarningMessage

Cela permet de distinguer :

création réussie

création réussie avec échec d’envoi email

création échouée

18. Requêtes API — attentes frontend

Cette section décrit les besoins frontend. Les endpoints exacts doivent être confirmés ou créés côté backend si absents.

18.1 Liste instrumentistes

Besoin :

récupérer la liste paginée / filtrable des instrumentistes

18.2 Détail instrumentiste

Besoin :

récupérer un détail complet compatible drawer

18.3 Création instrumentiste

Besoin :

créer un instrumentiste avec payload minimal

recevoir une information sur l’état d’envoi de l’invitation

18.4 Affiliations site

Besoin :

ajouter affiliation

modifier affiliation

supprimer affiliation

18.5 Tarifs

Besoin :

patch séparé ou endpoint dédié pour mise à jour des tarifs

18.6 Statut actif / suspendu

Besoin :

action explicite de suspension / réactivation

18.7 Planning instrumentiste

Besoin :

endpoint listant les missions d’un instrumentiste sur une fenêtre temporelle compatible FullCalendar (from, to)

18.8 Création mission depuis instrumentiste

Besoin :

création mission manager avec instrumentist_user_id prérempli

support du mode create and assign et draft

18.9 Validation d’invitation

Besoin :

vérifier qu’un token d’invitation est valide

permettre la finalisation du compte via formulaire frontend

19. Critères d’acceptation UX

La fonctionnalité est considérée comme conforme si :

le manager accède aux instrumentistes via Ressources > Instrumentistes

la liste affiche uniquement Nom / Statut / Type / Sites / Actions

le bouton + Instrumentiste ouvre une modal de création rapide

le clic sur Ouvrir affiche la fiche dans un drawer latéral

la fiche montre une table des affiliations site avec type d’affiliation

les tarifs Bloc opératoire et Consultation sont éditables inline avec autosave

le statut est géré via toggle avec aide ? et modal de confirmation

le drawer contient un planning FullCalendar avec vue Mois et vue Liste

le clic sur une mission du calendrier ouvre le détail mission existant

le clic sur un jour vide ou + Nouvelle mission ouvre une modal de création mission

la modal permet de choisir entre Créer et assigner et Créer en brouillon

la création d’un instrumentiste déclenche un envoi d’invitation

le mot de passe n’est pas défini par le manager

le nouvel instrumentiste finalise lui-même son compte via lien frontend

aucune donnée patient n’apparaît

aucune logique de droit n’est déduite côté frontend

l’UX reste sobre, lisible et compatible desktop manager

20. Proposition de séquençage d’implémentation
Lot MGR-INS-1 — Fondations

création de la route manager instrumentists

page liste vide

structure API/types

table simple

Lot MGR-INS-2 — Création rapide

bouton + Instrumentiste

modal de création

refresh liste

Lot MGR-INS-3 — Drawer fiche

ouverture drawer

chargement détail

rendu header + sections

Lot MGR-INS-4 — Affiliations site

table des affiliations

ajout / suppression / modification

Lot MGR-INS-5 — Tarifs autosave

champs inline

autosave

feedback succès / erreur

Lot MGR-INS-6 — Statut actif/suspendu

toggle

tooltip

modal confirmation

Lot MGR-INS-7 — Planning instrumentiste

intégration FullCalendar

vue mois

vue liste

ouverture mission

Lot MGR-INS-8 — Création mission depuis planning

clic jour vide

modal légère

choix Créer et assigner / Créer en brouillon

Lot MGR-INS-9 — Invitation & complétion de compte

message de confirmation après création

gestion du warning si email non envoyé

écran complete-account

gestion token invalide / expiré / déjà utilisé

21. Points ouverts à confirmer plus tard

Les points suivants restent à confirmer au moment de l’implémentation si le backend ou la politique produit ne sont pas encore figés :

endpoint exact de liste / détail instrumentistes

modèle exact de type principal si affiliations multiples

stratégie de pagination / infinite scroll

politique exacte de suspension sur les missions déjà assignées

message exact si un instrumentiste suspendu est rouvert en édition mission

niveau de détail de la vue liste FullCalendar

format d’édition des affiliations (inline vs modal)

niveau exact de visibilité du statut “invitation envoyée” dans l’UI manager

22. Résumé exécutable

Le module manager « Instrumentistes » doit être implémenté comme une liste simple avec drawer latéral riche.

Décisions clés retenues

navigation : Ressources > Instrumentistes

listing minimaliste

création via modal rapide

sites via table des affiliations

fiche dans un drawer

tarifs inline avec autosave

statut via toggle + aide + confirmation

planning FullCalendar dans le drawer

vues Mois et Liste

création mission depuis le planning

choix explicite : Créer et assigner / Créer en brouillon

invitation envoyée après création

complétion du compte par l’instrumentiste via lien frontend sécurisé

Intention finale

Donner au manager un outil :

rapide

lisible

opérationnel

non surchargé

cohérent avec l’architecture SurgicalHub

23. Nom de fichier recommandé dans le repo

Nom recommandé :

docs/manager_instrumentists_ux_v1.md

Si tu veux une granularité plus forte par lot :

docs/manager-instrumentists/manager_instrumentists_ux_v1.md
```
