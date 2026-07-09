# Écran — Login

## Informations générales
- **Nom** : Login / Connexion
- **Route** : `/login`
- **Composant React** : `LoginScreen`
- **Layout** : plein écran, sans nav, sans sidebar
- **Header utilisé** : aucun (panneau de marque propre)
- **Version** : v2 (validée) · **Auteur** : Design SurgeryHub (assist. Claude) · **Date** : 2026-07-08

## Objectif
Connexion email + mot de passe. Pas d'auto-inscription (lien « Demander une invitation »). Poser l'identité (vert profond, vagues, logo) dès le premier écran.

## Capture officielle
`mobile.png` (390px) · `desktop.png` (~909px) · annotées : `mobile-annotated.png`, `desktop-annotated.png`. **Tablette : pas de capture — ≥ 900px = layout desktop, < 900px = layout mobile (une seule bascule).** Référence exécutable : `prototypes/SurgeryHub App v2.dc.html`.

## Hiérarchie visuelle
1. Panneau de marque (dégradé + vagues + logo + titre)
2. Formulaire (feuille blanche)
3. CTA « Se connecter »
4. Liens secondaires (mot de passe oublié, invitation)

## Structure
**Mobile** : colonne. Panneau de marque : dégradé `gradient.login` (155deg #2E7D5F→#1E634A 42%→#123F30), padding 54 26 34, vagues translucides + croix filigrane (blanc .07). Rangée logo : badge blanc 46×46 r13 shadow `0 4px 14px rgba(0,0,0,.25)`, logo 34px + wordmark 20/800 blanc (« Hub » green-300). Titre 30/800 −0.02em blanc lh 1.18 ; sous-titre 14.5 blanc 80%. Feuille : blanche, radius 26 haut, shadow `loginSheet`, padding 28 24 40, contenu max 420 centré, gap champs 18.
**Desktop** : grid `minmax(420px,1fr) minmax(460px,1.1fr)`. Gauche : dégradé, padding 44 52 ; logo badge 48 r14 + wordmark 21/800 ; titre 40/800 ; 3 arguments (cercle 48 blanc 12% + icône 20 + titre 15.5/700 + desc 13.5 blanc 72%, gap 22) ; copyright 13 blanc 50%. Droite : fond blanc, formulaire centré max 400 ; « Connexion » 30/800 + sous-titre 15 muted.

## Composition
`Field` (email, password) · `Checkbox` · `Button` primaire (green-700, 54px mobile / 52px desktop) · liens texte green-700/600.

## Design Tokens utilisés
`gradient.login` · `color.green.300/600/700` · `semantic.text.*` · `radius.sheet=26` · `shadow.loginSheet` / `ctaGreen` · `font` : titres 800 tracking tight · `motion.spinner=700ms`. → `design-tokens.json`

## Responsive
- **Desktop/laptop ≥ 900** : split 2 colonnes, formulaire max 400.
- **Tablette** : paysage ≥ 900 = desktop ; portrait < 900 = mobile.
- **Mobile < 900** : colonne, feuille pleine largeur, gutter 24–26. Rien n'est masqué ; les 3 arguments desktop n'existent pas sur mobile.

## États
- Défaut · **Loading** : bouton « Connexion… » + spinner 17px 700ms (~900ms simulé) · **Mot de passe visible** (œil barré) · **Souvenir de moi** : coché par défaut · **Erreur** (à implémenter : bord `red-500` + message 13 `red-600` sous le champ — pas de capture, ne pas improviser d'autre pattern).

## Interactions
Enter soumet · œil toggle (aria-label) · checkbox + libellé cliquables · hover CTA green-800, press translateY(0.5px) · focus ring 3px sur tout.

## Accessibilité
Labels liés, inputs 16px (pas de zoom iOS), ring jamais supprimé, contrastes : blanc/green-700 ≈ 4.9:1 ✔. `aria-label` œil.

## Contraintes (intouchables)
Ordre des éléments du formulaire · checkbox cochée par défaut · badge blanc sous le logo · **pas** de bouton « code d'accès » (supprimé) · dégradé exact · radius feuille 26.

## Checklist d'acceptation
☐ Conforme à mobile.png ☐ Conforme à desktop.png ☐ Tokens respectés ☐ Composants Field/Checkbox/Button documentés ☐ Espacements (gap 18, paddings) ☐ Typo (30/40/800) ☐ Spinner 700ms ☐ Règles UX ☐ Accessibilité ☐ Aucune différence visuelle notable
