# Design System — SurgeryHub Instrumentiste

Toutes les valeurs exactes sont dans `design-tokens.json`. Ce document décrit l'usage.

## Couleurs

- **Vert de marque** `#42A882` (`green-500`) : actions primaires « prendre », identité, nav active. Hover `green-600`, pressed `green-700`.
- **Verts profonds** `green-700/800/900` : surfaces de marque (headers, login), CTAs d'encodage/validation, capsule nav active.
- **Vert menthe** `green-300/400` : accents sur fond sombre (« Hub » du wordmark, sous-titres header).
- **Fonds teintés** `green-50/100` : tuiles date, items sélectionnés, encarts totaux.
- **Ambre** : à encoder / en attente. **Bleu** : information, à venir. **Rouge** : refus, badge d'alerte. **Gris froids** : chrome et texte.
- Page `gray-50 #F5F7FA`, cartes blanches.

### Statuts de mission (paires fg/surface — jamais d'autre combinaison)
| Statut | Texte | Fond pastille | Note |
|---|---|---|---|
| Proposée | green-700 | green-100 | tuile date green-100/green-900 |
| Confirmée | green-700 | green-100 | tuile green-50/green-800 |
| En cours | green-700 | green-100 | + point pulsant green-500 |
| À encoder / En attente | amber-700 | amber-50 | |
| À venir | blue-700 | blue-100 | |
| Refusée | gray-500 | gray-100 | card opacity .55 |

## Typographie
Inter partout. Titres 800 tracking −0.02em. Écran 26px, modal 20px, card 15–17px. Eyebrows 10.5–12px/800 tracking .06–.08em UPPERCASE. Données horaires en tabular-nums. Détail : `typography/typography.md`.

## Spacing
Grille 4px. Gutter écran 20px. Gaps standard : 7 (label→champ), 10–12 (inline), 14 (rangées), 18 (champs de formulaire), 20 (sections). Colonne contenu max 720px.

## Radius
12px défaut contrôles · 16px cards liste · 18px cards riches · 20–22px menus/dialogues · 24px bandeau desktop · 26px sheets · 28px bandeau mobile · pill pour pastilles/chips.

## Shadows / Elevation
Ombres froides teintées `#16202B` (voir tokens). Cards = shadow-xs (+ bord subtil) ; hero/menus = md/lg ; sheets = xl ; bottom-nav = ombre inversée. CTAs verts portent une ombre colorée dédiée.

## Borders
Hairline 1px `gray-150` (séparation) · 1.5px `gray-200` (contrôles) · focus 1.5px `green-500` + ring 3px. Séparateurs internes de cards : **1px dashed** `gray-150/200` (signature « suture »).

## Grid & Breakpoints
Une seule bascule : **900px**. Mobile < 900 : colonne unique, gutter 20. Desktop ≥ 900 : sidebar 248px fixe + colonne centrale max 720px centrée. Grilles internes : calendrier `repeat(7, minmax(0,1fr))`, cards à venir `repeat(auto-fit, minmax(272px,1fr))`.

## Icons
Lucide outline 2px, 15–21px (voir `icons/icons.md`, mapping MUI inclus).

## Buttons
| Variante | Style | Usage |
|---|---|---|
| Primaire marque | fond green-500, blanc, radius 12–14, ombre ctaBrand | Prendre la mission, login |
| Primaire sombre | fond green-700/800, hover +1 cran | CTAs encodage, hero, sheets |
| Secondaire outline | bord 1.5px gray-200, texte gray-700 | Refuser (hover → rouge) |
| Dashed | bord 1.5px dashed green-400, fond green-50 | Déclarer une mission |
| Fantôme | transparent, texte gray-500 | Annuler / retour dans sheets |
| Ambre | fond amber-600 | Encoder (rappel) |
Hauteurs : 40 (inline), 46 (paire), 50–52 (CTA card), 54–56 (CTA écran). Press : translateY(0.5px) ou scale(.96).

## Cards
Blanches, radius 16–18, shadow-xs, padding 13–18px. Signature : tuile date verticale à gauche OU photo bandeau 186px avec voile vert. Séparateur interne dashed. Nom du site en `green-700` sur les offres.

## Inputs
`Field` : label 13/700 gray-700, contrôle 50–54px, bord 1.5 gray-200, radius 12–14, icône gauche green-600, placeholder gray-400, focus bord vert + ring. `SelectField` : même boîte + chevron pivotant, liste dépliante inline (options 48px, sélection green-50 + coche). `StepperRow` : label 74px + boutons −/+ 46px + valeur 19/800 tabular centrée.

## Tables
Pas de tables sur mobile — listes de cards. Desktop : conserver les cards (pas de conversion en table sans design dédié).

## Badges & Tags
Badge compteur : pill rouge `red-600`, blanc 10.5–11.5/800, bord 2px blanc, min 17px. Tags : pill 24px, `green-100/green-800` (type) ou outline gray (spécialité). StatusPill : pill 24–26px fg/surface du statut.

## Avatars

**Mis à jour — la photo de profil est désormais un concept du design** (décision produit validée ; composants `PersonAvatar`/`AvatarUploader`, voir `components/avatar.md`).

- **Cercle**, tailles nommées dans `design-tokens.json → size.avatar` : `xs` 24px (listes denses, planning), `sm` 32px (cartes, menus), `md` 40px (en-têtes de section), `lg` 56px (tiroirs, profil compact), `xl` 88px (upload : onboarding, modale de rappel).
- **Avec photo** : image recadrée en carré, mêmes tailles.
- **Sans photo (repli)** : pastille d'initiales, couleur déterministe par hash du nom — voir `design-tokens.json → semantic.avatarIdentity` (6 paires bg/fg, même personne = même couleur partout). Le header/sidebar de marque (bloc utilisateur, `components/navigation.md`) garde son traitement propre `green-100`/`green-800` (ou blanc/green-800 sur fond vert) : c'est un repli spécifique à cet emplacement, pas la règle générale.
- **Édition (`AvatarUploader`)** : icône caméra superposée (badge 28px, bas-droite), glisser-déposer, recadrage carré avant envoi (zoom + recentrage), état de chargement (anneau superposé), suppression (icône bas-gauche, si une photo/aperçu existe), erreur en légende sous l'avatar.

## Modals
Mobile : bottom sheet radius 26 haut, slide-up 300ms. Desktop : dialogue centré 480px radius 22, pop 220ms. Overlay `rgba(11,19,32,.52)` + blur 3px. Structure : titre 20/800 + `CloseButton` 40px → contenu → CTA plein 52px → action fantôme 44px. Max-height 80vh, scroll interne.

## Calendars
Semaine : 7 chips 66px (aujourd'hui = capsule green-900 + liseré blanc 2px, point mission 5px). Mois : grille 7 col, cellules 44px radius 10, aujourd'hui capsule sombre, jour à mission fond green-50 (amber-50 si à encoder) + point, hors-mois gray-300, légende sous la grille. Jour cliquable ⇢ modal jour.

## Search
Champ 46px avec icône loupe gauche (wizard matériel). Filtre en direct, pas de bouton rechercher.

## Filtres
Chips pill 38px : actif fond `gray-900`→(offres) `green-900` blanc + liseré blanc 2px ; inactif blanc ombre xs texte gray-600.

## Empty states
Journée libre : illustration SVG animée aux couleurs de marque + titre 17/800 + texte muted + CTA. Planning vide : bandeau info `blue-50` avec lien. Recherche vide : texte gray-400 13.5px + bouton « Je ne trouve pas le matériel ».

## Loading
Bouton en cours : spinner anneau 17px blanc 700ms + libellé (« Connexion… »). Pas de skeletons designés à ce stade (à signaler si besoin).

## Errors
Validation douce par toast (« Sélectionnez un site et un chirurgien. »). Toast : fond gray-900, blanc 14/600, radius 12, bottom ~98px, 2.8s. Jamais de rouge agressif plein écran.
