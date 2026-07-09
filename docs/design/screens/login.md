# Écran — Login

## Correspondance
Route : `/login` · React Component : `LoginScreen` · Layout : plein écran (sans nav) · Sidebar : non · Header : non · Responsive : split 900px · Version : v2 (validée)

## Objectif
Connexion email + mot de passe. Pas d'auto-inscription : lien « Demander une invitation ». Poser l'identité de marque dès le premier écran.

## Structure — Mobile
1. **Panneau de marque** (haut, flex none) : dégradé `gradient.login`, vagues translucides + croix médicale filigrane (blanc .07). Padding 54 26 34. Rangée logo : badge blanc 46×46 radius 13 (logo 34px, shadow) + wordmark 20/800 blanc (« Hub » `green-300`). Titre « Bienvenue sur\nSurgeryHub » 30/800 blanc, sous-titre « Connectez-vous à votre espace personnel » 14.5 blanc 80%.
2. **Feuille formulaire** (flex 1) : blanche, radius 26 haut, shadow `loginSheet`, padding 28 24 40, contenu max 420 centré : Field E-mail (icône mail) · Field Mot de passe (cadenas + œil) · rangée Checkbox « Se souvenir de moi » (cochée) + lien « Mot de passe oublié ? » · CTA « Se connecter » 54px `green-700` · footer « Vous n'avez pas de compte ? / Demander une invitation ».

## Structure — Desktop
Grid `minmax(420px,1fr) minmax(460px,1.1fr)`.
- Gauche : même dégradé, padding 44 52. Logo badge 48 + wordmark 21. « Bienvenue sur SurgeryHub » 40/800 + sous-titre 16. **3 arguments** (cercle 48px blanc 12% + icône 20 blanche + titre 15.5/700 + desc 13.5 blanc 72%) : Planning intelligent / Offres en temps réel / Sécurisé & fiable. Copyright 13 blanc 50%.
- Droite : fond blanc, formulaire centré max 400 : « Connexion » 30/800 + « Accédez à votre espace personnel » 15 muted + mêmes champs (CTA 52px).

## États
- Défaut · Loading : bouton « Connexion… » + spinner 700ms (~900ms) · Erreur (à implémenter : bord rouge + message sous champ) · Mot de passe visible (œil barré).

## Interactions
Enter soumet · œil toggle · checkbox/libellé cliquables · liens `green-700`/600.

## Fidelity — intouchable
Ordre des éléments du formulaire · checkbox cochée par défaut · badge blanc sous le logo (le logo a besoin d'un fond clair) · pas de bouton « code d'accès » (supprimé).
