Planning Instrumentiste v1.0
Spécification fonctionnelle validée

1. Positionnement produit
   1.1 Objectif

Le Planning Instrumentiste est un calendrier personnel minimaliste, pensé pour le contrôle opérationnel quotidien.

Il doit permettre à l’instrumentiste de répondre immédiatement à quatre questions :

Est-ce que je travaille aujourd’hui ?

Où ?

Avec qui ?

À quelle heure ?

1.2 Périmètre

Le composant est :

strictement dédié à l’instrumentiste

indépendant du futur agenda manager

centré sur la lecture, la navigation et la consultation

non orienté création

non orienté action métier directe

Aucune logique manager n’est intégrée dans cette version.

1.3 Philosophie produit

Le planning est conçu comme un outil de lecture rapide, pas comme un cockpit complexe.

Il doit rester :

neutre visuellement

peu chargé

orienté usage mobile

centré sur le repérage temporel

cohérent avec le détail mission existant

Le calendrier ne remplace pas la page détail mission ; il sert à repérer puis ouvrir.

2. Structure générale
   2.1 Modes disponibles

Un toggle horizontal permet de basculer entre :

Mes missions

Offres

Les missions OPEN ne sont jamais mélangées aux missions personnelles.

2.2 Vues disponibles

Deux vues sont proposées :

Mois

Semaine

La vue Mois est la vue par défaut.

2.3 Vue par défaut

À chaque nouvel accès à /instrumentist/planning :

le mode par défaut est Mes missions

la vue par défaut est Mois

la date par défaut est aujourd’hui

Le planning revient systématiquement sur cette configuration à l’ouverture.

2.4 Logique macro / micro

Le composant sépare deux niveaux de lecture :

Vue Mois = lecture macro, repérage global

Vue Semaine = lecture micro, lecture horaire détaillée

Cette séparation fait partie de la philosophie UX du module.

3. Vue Mois
   3.1 Rôle

La vue Mois est la vue principale du planning.

Elle doit permettre de voir rapidement :

quels jours contiennent des missions

la répartition mensuelle de l’activité

les jours à surveiller

les jours comportant un conflit potentiel

3.2 Grille

La grille mensuelle suit les règles suivantes :

hauteur fixe des cases

rendu visuellement stable

une seule mission visible par case au premier niveau

indication +X si d’autres missions sont présentes ce jour-là

jour actuel légèrement mis en évidence

pas de surcharge visuelle

3.3 Missions affichées dans la case

Chaque case de jour affiche au maximum une mission visible.

Le libellé doit rester court, orienté usage réel, par exemple à partir de :

heure de début

chirurgien

site si pertinent et si la place le permet

Le but n’est pas d’afficher toute l’information dans la case, mais de permettre un repérage rapide.

3.4 Missions multi-jours

Une mission est affichée uniquement le jour de début.

Il n’y a :

aucune duplication sur les jours suivants

aucun étalement visuel sur plusieurs cases

aucune barre multi-jours

La vue Mois reste un outil de repérage, pas une timeline détaillée.

3.5 Statuts visuels

Le planning reste volontairement neutre visuellement.

Règle générale :

pas de différenciation visuelle lourde par statut

pas de texte statut systématique dans les cases

pas de codage couleur agressif par workflow

Exception : mission DECLARED

La mission DECLARED reçoit une indication discrète :

desktop : badge léger À valider

mobile : icône discrète

le libellé complet reste visible uniquement dans le détail mission

Cette exception existe parce que DECLARED correspond à un état métier particulier utile à signaler.

3.6 Conflits horaires
Définition

Un conflit correspond à un chevauchement temporel entre deux missions.

Le calcul est effectué :

tous sites confondus

sur tous les statuts sauf OPEN

Affichage en vue Mois

Le conflit n’est pas affiché sur chaque mission.

Il est affiché au niveau de la case du jour uniquement, sous forme discrète :

⚠

cliquable

sans dramatisation visuelle

Comportement au clic

Le clic ouvre une explication contextualisée du type :

Conflit entre mission Delta (Dr X) 08:00–12:00
et mission Parc (Dr Y) 11:30–15:00

Le conflit est présenté comme une aide à la lecture, pas comme une alerte bloquante.

3.7 Mois vide

Si le mois ne contient aucune mission :

la grille reste affichée normalement

aucun message vide n’est affiché dans la grille

L’interface reste sobre.

4. Vue Semaine
   4.1 Rôle

La vue Semaine est la vue détaillée.

Elle sert à :

lire les horaires plus finement

voir la structure de la semaine

comprendre visuellement les chevauchements

accéder rapidement au détail mission

4.2 Grille horaire

La vue Semaine suit les règles suivantes :

amplitude 00h–24h

scroll vertical

positionnement initial sur une plage utile, centrée autour de 08h–18h

lecture chronologique claire

4.3 Chevauchements

Les chevauchements sont visibles structurellement par le rendu calendrier :

missions affichées côte à côte si overlap

aucune icône ⚠

aucun message d’alerte spécifique dans la grille semaine

Le conflit ne doit pas être “sur-signalisé” dans cette vue, car la structure même de la grille le montre déjà.

4.4 Statuts visuels

Comme en vue Mois :

interface globalement neutre

pas de surcharge de statuts

exception légère pour DECLARED si nécessaire

détail complet visible dans la modal mission

4.5 Interaction

Le clic sur une mission ouvre la modal détail mission existante.

La vue Semaine ne contient pas d’action métier directe.

5. Résumé mensuel
   5.1 Positionnement

Un résumé mensuel est affiché au-dessus du calendrier.

Il synthétise le mois actuellement sélectionné.

5.2 Contenu

Le résumé contient :

nombre total de missions du mois

total d’heures du mois

5.3 Règle de calcul du nombre de missions

Le nombre de missions est calculé sur les missions dont le startAt appartient au mois sélectionné.

5.4 Règle de calcul des heures

Le total d’heures est calculé selon la règle suivante :

mission non SUBMITTED → utiliser les heures planifiées

mission SUBMITTED → utiliser les heures encodées

si hours = null ou hours = 0 → compter 0

VALIDATED ne change pas la règle de calcul

Le calcul repose sur le mois du startAt.

5.5 Filtre site

Un filtre site est disponible uniquement sur desktop.

Caractéristiques :

persistance locale

applicable aux modes Mes missions et Offres

le résumé mensuel reflète le filtre actif

Ce filtre n’est pas prioritaire sur mobile.

6. Navigation
   6.1 Desktop

Sur desktop, la navigation comprend :

flèches précédent / suivant

bouton Aujourd’hui

changement de mois clair

accès simple à la vue Semaine

6.2 Mobile

Sur mobile, la navigation privilégie l’expérience gestuelle :

swipe horizontal pour changer de période

interaction fluide

priorité donnée à la simplicité

En mobile :

pas de bouton Aujourd’hui visible en permanence

la navigation doit rester légère

6.3 Cohérence de navigation

Les règles de navigation sont :

en vue Mois : navigation par mois

en vue Semaine : navigation par semaine

retour systématique à la vue Mois lors d’un nouvel accès

7. Données et performance
   7.1 Chargement

Le calendrier charge :

le mois courant

le mois précédent

le mois suivant

Le chargement utilise les paramètres backend :

from

to

Le lazy load au-delà de cette fenêtre est autorisé.

7.2 Mise à jour

Le calendrier doit se mettre à jour automatiquement après :

claim

declare

submit

changement de statut

Le détail mission reste la source d’action ; le calendrier doit simplement se synchroniser ensuite.

7.3 Données affichées

Le calendrier n’affiche jamais de données patient.

Il se limite aux informations nécessaires à la lecture opérationnelle.

8. Mode offline
   8.1 Comportement

En mode offline :

affichage des données en cache

consultation en lecture seule

aucun indicateur permanent envahissant

8.2 Signalement

Un message n’est affiché que lorsqu’une action nécessite le réseau.

Le planning lui-même ne doit pas être surchargé par un état offline permanent.

9. Interactions interdites

Le calendrier :

ne permet pas de déclarer une mission directement

ne permet pas de créer une mission

ne bloque aucune action métier

n’infère aucun droit

allowedActions reste l’unique source de vérité pour les actions disponibles dans le détail mission.

Le calendrier sert à naviguer, pas à décider des droits.

10. Philosophie UX validée

Le planning instrumentiste v1.0 obéit aux principes suivants :

interface neutre

vue Mois prioritaire

vue Semaine secondaire mais utile

peu de bruit visuel

pas de mise en avant excessive des statuts

pas de dramatisation des conflits

séparation claire entre lecture globale et lecture détaillée

consultation rapide avant tout

En résumé :

Mois = repérage global

Semaine = compréhension fine

Détail mission = logique métier

11. Critères fonctionnels de validation

La fonctionnalité est considérée comme conforme si :

le planning s’ouvre par défaut en Mes missions / Mois / aujourd’hui

la vue Mois affiche une grille stable avec une mission visible par jour et +X si nécessaire

une mission multi-jours n’est affichée que sur son jour de début

les conflits apparaissent uniquement au niveau de la case jour en vue Mois

la vue Semaine affiche les chevauchements structurellement sans ⚠

le résumé mensuel affiche le nombre de missions et le total d’heures

le filtre site desktop fonctionne et impacte le résumé

le détail mission existant est réutilisé sans duplication

aucune donnée patient n’apparaît

aucune action métier directe n’est ajoutée au calendrier

aucun droit n’est inféré côté calendrier
