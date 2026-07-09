# Navigation

## BottomNav (mobile)
Ancrée bas, fond blanc, **coins hauts 22px**, ombre `0 -8px 28px rgba(22,32,43,.14)`, padding `10px 14px + env(safe-area-inset-bottom)`, z-index 300.
3 items flex:1, 58px, radius 16, colonne : icône 21px + libellé 11px, gap 3.
- Actif : fond `green-800`, blanc, 700, ombre `0 5px 14px rgba(20,77,56,.4)`, transition 200ms marque.
- Inactif : transparent, `gray-500`, 600.
- Badge rouge sur Offres (voir status-pill.md).
Variante tweak « flottante » : barre détachée (bottom 12, radius 20, blur) — non retenue par défaut.

## Sidebar (desktop ≥ 900px)
248px, sticky 100vh, fond blanc, bord droit `border-subtle`, padding 24 14 14.
- Logo 42px + wordmark 18/800 (« Hub » `green-600`).
- Items 48px radius 13 gap 6, icône 19 + libellé 14 : actif fond `green-50` texte `green-800`/800 ; inactif `gray-600`/600, hover `gray-75`. Ordre : Aujourd'hui, Planning, Offres (+badge), Messages, Notifications, Profil.
- Bas : séparateur 1px + bloc utilisateur 100% (avatar 38 `green-100`/`green-800`, nom 14/700, rôle 12 muted, chevron) → ouvre le **menu compte** : 232px, radius 14, shadow-lg, padding 8, ancré bas-gauche (desktop) / haut-droit (mobile via avatar header) ; items 42px radius 10 : Mon profil (gris), Se déconnecter (`red-600`, hover `red-50`).

## BrandHeader (header de marque)
Voir `layouts/layouts.md` + `animations/animations.md` (vagues, titres). Mobile : plein largeur coins bas 28 ; desktop : carte radius 24 max 760px. Dégradé `brandHeader`. Contenu chevauche de −34px.

## Header encodage
Dégradé `encodeHeader` (plus sombre), retour ← 40px blanc 12%, eyebrow `green-300`, titre « Mission #N » 24/800, tags pill blanc 14% (date, type). Vague d'arrivée animée (#5 des animations).
