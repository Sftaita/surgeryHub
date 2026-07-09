# Layout mobile (< 900px)

## Structure verticale
1. **Header de marque** (hors encodage) : plein largeur, dégradé `brandHeader`, coins bas 28px, padding `12px 20px 56px`. Contient : rangée logo (logo 36px + wordmark 16/800) + cloche 40px (fond blanc 12%, badge point rouge) + avatar 38px blanc ; puis titre 26/800 blanc + sous-titre 14.5 `green-300` (ces deux-là animés à chaque navigation). Vagues SVG en fond (3 couches, morphing par onglet).
2. **Contenu** : colonne unique, gutter 20px, chevauche le header de **−34px**, padding bas 130px (dégage la nav).
3. **Bottom nav** : ancrée, fond blanc, coins hauts 22px, ombre `bottomNav`, padding `10px 14px + safe-area`. 3 items 58px radius 16 (icône 21 + libellé 11) ; actif = bloc `green-800` blanc/700 ; badge rouge sur Offres.

## Écran encodage (enfant)
Header propre : dégradé `encodeHeader`, coins bas 26px, bouton retour ← 40px (blanc 12%), eyebrow « ENCODAGE MISSION » `green-300`, titre « Mission #N » 24/800, méta + tags pill blancs 14%. Vague animée à l'arrivée. Contenu chevauche de −28px. La bottom-nav reste visible.

## Modals
Bottom sheets : `position:fixed` bas, radius 26px haut, padding `20px 20px 24px + safe-area`, max-height 80vh, scroll interne, slide-up 300ms. Overlay pleine page flouté.

## Divers
- Toast centré bas (~98px du bas, au-dessus de la nav).
- Rails horizontaux (offres accueil) : scroll-x, marges négatives −20px pour affleurer les bords, scrollbar masquée.

---

# Layout desktop / tablette paysage (≥ 900px)

## Structure
- **Sidebar** 248px fixe (sticky, 100vh) : fond blanc, bord droit `border-subtle`, padding `24px 14px 14px`. Logo 42px + wordmark 18/800 (gray-900 + « Hub » green-600). Nav : items 48px radius 13, gap 6, actif fond `green-50` texte `green-800`/800 ; Aujourd'hui, Planning, Offres (badge rouge), Messages, Notifications, Profil. Bas : séparateur + bloc utilisateur (avatar 38 `green-100`, nom 14/700, rôle 12, chevron) → menu compte (232px, radius 14, shadow-lg, ancré bas-gauche : Mon profil / Se déconnecter rouge).
- **Colonne centrale** : max 720px centrée ; le header de marque devient une **carte** radius 24, max 760px, margin-top 22px, padding `22px 26px 58px`.
- **Modals** : dialogues centrés `min(480px, 100%)`, radius 22, padding 24, pop 220ms.

## Tablette
- ≥ 900px de large (paysage, iPad Pro) : layout desktop.
- < 900px (portrait) : layout mobile. C'est voulu — une seule bascule.
