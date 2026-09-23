# Spécification pixel par pixel — écran d'encodage (option 2b)

Document de reprise à l'identique de la maquette validée (`Interventions Encodage v2.dc.html`, option **2b**).
Toutes les valeurs sont **exactes** : ne pas arrondir, ne pas « harmoniser » sur une échelle de 4px, ne pas remplacer une couleur par une teinte voisine. Les valeurs demi-pixel (16.5px, 12.5px, 10.5px, 13.5px) sont volontaires.

Sommaire : §0 prérequis · §1 **les deux zones** · §2 heures · §3 progression · §4 en-tête de liste · §5 carte intervention · §5bis **animation d'ouverture/fermeture** · §6 **défilement automatique** · §7 ligne matériel · §8 pied · §9 **4 modes d'affichage** · §10 **iOS / Android** · §11 états · §12 copie

---

## §0. Prérequis

- Police **Inter** — graisses 400 / 600 / 700 / 800.
- `font-variant-numeric: tabular-nums` sur **tous** les chiffres (heures, quantités, compteurs, totaux) : sinon les valeurs sautent en largeur quand elles changent.
- Fond d'écran : `--gray-50`. Cartes : blanc pur `#fff`.
- Tokens du design system. Valeurs de repli si indisponibles :

| Token | Hex |
| --- | --- |
| `--green-900` | #144D38 |
| `--green-800` | #1F6B4F |
| `--green-700` | #2C7D5F |
| `--green-600` | #338F6E |
| `--green-500` | #42A882 |
| `--green-300` | #8FDABF |
| `--green-200` | #BCE9D6 |
| `--green-100` | #DDF4EA |
| `--green-50` | #EFFAF5 |
| `--gray-950` | #0B1320 |
| `--gray-700` | #3A4754 |
| `--gray-600` | #5A6675 |
| `--gray-500` | #727E8C |
| `--gray-400` | #98A2AE |
| `--gray-200` | #DDE2E8 |
| `--gray-150` | #E7EBEF |
| `--gray-75` | #F1F4F7 |
| `--gray-50` | #F5F7FA |
| `--amber-500` / `-100` / `-50` / `-600` / `-700` | #F0A91B / #FBEACB / #FEF6E7 / #D2901A / #B7791F |
| `--blue-700` / `-100` / `-50` | #1B5FD0 / #D6E6FE / #EDF4FF |
| `--red-700` / `-100` / `-50` | #C62F36 / #FAD7D8 / #FDEEEE |
| `--shadow-sm` | `0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)` |
| `--shadow-md` | `0 2px 6px rgba(22,32,43,.06), 0 8px 20px rgba(22,32,43,.08)` |

Règle de couleur : **le bleu ne sert jamais à une action** — uniquement au badge de catégorie de facturation. Le rouge n'apparaît qu'au survol/appui d'une suppression. L'ambre signale exclusivement « il reste du travail ».

---

## §1. Les deux zones — séparation temps / matériel

L'écran déclare **deux choses sans rapport** : le temps passé et le matériel consommé. Elles ne doivent jamais se lire comme une seule liste. Chaque notion vit dans son **bac** (`.zone`), avec un numéro d'étape, un titre et un fond propres.

Conteneur `.enc` : `display:flex; flex-direction:column; gap:22px; width:100%; max-width:760px; margin:0 auto; padding:0 20px 24px`. Le **gap de 22px entre les deux bacs** est le double du gap interne (14px) : c'est lui qui fait la coupure.

### Bac commun
`border-radius:18px; padding:13px 13px 14px; display:flex; flex-direction:column; gap:14px`.
En-tête de bac : `display:flex; align-items:center; gap:9px; padding:0 3px` —
pastille d'étape **20×20**, `border-radius:6px`, chiffre **11.5px / 800** blanc ·
titre **11.5px / 800**, `letter-spacing:.1em`, majuscules, `flex:1` ·
méta à droite **11.5px / 700**.

### Zone 1 — « TEMPS DE TRAVAIL »
- Bac : fond `--gray-150`, **pas de bordure**.
- Pastille `--gray-700`, titre `--gray-700`, méta `--gray-500` (« 1 saisie »).
- Contenu : une seule carte, **entièrement neutre** — blanc, gris, **aucune touche de vert, aucun bandeau foncé**. C'est la règle qui empêche la confusion : le vert foncé appartient exclusivement aux interventions.

### Zone 2 — « MATÉRIEL PAR INTERVENTION »
- Bac : fond `--green-50`, bordure `1px solid --green-200`.
- Pastille `--green-700`, titre `--green-800`, méta `--green-700` (`2 / 3`, tabulaire).
- Contenu, dans cet ordre : **Progression → en-tête de liste (résumé + « + Intervention ») → cartes intervention (gap 16px, la plus récente en premier) → pied de validation.**

Le fond vert clair du bac relie visuellement tout ce qui concerne le matériel et détache le bloc du bac gris des heures. **Ne pas fusionner les deux bacs, ne pas les mettre côte à côte, ne pas donner le fond vert au bac des heures.**

Deux règles de flux à ne jamais inverser, dans la zone 2 :

- **Ajout en haut.** Le bouton d'ajout vit dans l'en-tête de liste. Il n'existe aucun bouton d'ajout en pied : l'utilisateur ne doit jamais descendre en bas de l'écran pour créer une intervention.
- **Liste anti-chronologique.** La dernière intervention ajoutée s'affiche en premier, la n°1 en dernier. Les **numéros ne changent pas** (ils suivent l'ordre de création) : la carte du haut peut porter le n°3. Implémentation : numéroter **avant** l'inversion (`interventions.map((it,i)=>({it,index:i+1}))` puis `.reverse()` pour le rendu).

Deux règles de flux à ne jamais inverser :

- **Ajout en haut.** Le bouton d'ajout vit dans l'en-tête de liste. Il n'existe aucun bouton d'ajout en pied : l'utilisateur ne doit jamais descendre en bas de l'écran pour créer une intervention.
- **Liste anti-chronologique.** La dernière intervention ajoutée s'affiche en premier, la n°1 en dernier. Les **numéros ne changent pas** (ils suivent l'ordre de création) : la carte du haut peut porter le n°3. Implémentation : numéroter **avant** l'inversion (`interventions.map((it,i)=>({it,index:i+1}))` puis `.reverse()` pour le rendu).

---

## §2. Carte « Heures prestées » (zone 1)

Coque **délibérément différente** des cartes intervention : blanche, posée dans le bac gris, sans bandeau coloré.

- Carte : `display:flex; align-items:center; gap:16px; padding:15px 16px; border-radius:13px; background:#fff; box-shadow:--shadow-xs`. Survol : fond `--gray-50`. Toute la carte est cliquable (ouvre la saisie).
- Icône gauche : **40×40**, `border-radius:11px`, fond `--gray-100`, horloge **20px** trait **2.2**, couleur `--gray-700`.
- Libellé « Heures prestées » : **15px / 700** `--gray-950`.
- Détail : **12.5px / 600** `--gray-500`, tabulaire — `10h45 → 18h15 · pause 45 min`. Non renseigné : « Non renseignées — appuyez pour saisir » (et pas de total).
- Bloc total (droite, aligné à droite) : valeur **24px / 800** `--gray-950`, `line-height:1`, `letter-spacing:-.02em`, tabulaire ; sous-libellé « TOTAL NET » **11px / 700** `--gray-400`, `letter-spacing:.06em`, `margin-top:3px`.
- Chevron : cercle **32×32** `--gray-100`, icône **17px** trait **2.6** `--gray-600`, pointant à **droite** (navigation, pas dépliage).

⚠ Ne jamais repeindre cette carte en vert foncé ni lui donner un chevron vers le bas : elle serait reprise pour une intervention.

---

## §3. Bloc progression

- Carte : blanc, `border:1px solid --gray-150`, `border-radius:16px`, `box-shadow:--shadow-sm`, `padding:16px 18px`, colonne `gap:13px`.
- Compteur : `2 / 3` en **30px / 800** / `--green-800`, `letter-spacing:-.02em`, tabulaire ; libellé « interventions encodées » **13px / 700** / `--gray-500` sur la même ligne de base (`align-items:baseline; gap:7px`). Si tout est encodé, le libellé devient « Mission complète ».
- Pilule points (droite) : hauteur **28**, `padding:0 11px`, `border-radius:999px`, fond `--green-100`, texte `--green-800` **12.5px / 800**, icône trophée **14px**, libellé `+{15 × encodées} pts`.
- Jauge : **un segment par intervention**, `flex:1; height:8px; border-radius:99px`, `gap:6px`. Encodée = `--green-500`, sinon `--gray-200`, `transition: background 200ms`.
- Ligne d'incitation : **13px / 600** / `--gray-600`, icône **16px** — horloge `--amber-600` si incomplet, coche `--green-600` si complet.
  - Incomplet : « Encore N intervention(s) et la mission est complète. »
  - Complet : « Tout est encodé. Vous pouvez valider la mission. »

Comptage : une intervention compte comme encodée **dès qu'elle a au moins un matériel**. Supprimer le dernier matériel fait reculer la jauge — c'est voulu, c'est le levier d'incitation.

---

## §4. En-tête de liste

Ligne `display:flex; align-items:center; gap:12px`.

- `INTERVENTIONS` — **12px / 800** / `--green-700`, `letter-spacing:.08em`, majuscules.
- Résumé `3 interventions · 5 matériels` — **12.5px** / `--gray-500`, tabulaire, `flex:1`. Le second nombre est la somme des **unités**, pas des références.
- Bouton d'ajout (droite, `flex:none`) : hauteur **40**, `padding:0 15px 0 12px`, `border-radius:12px`, fond `--green-600` (survol `--green-700`), `box-shadow:--shadow-sm`, icône **+ 19px** trait **2.8**, libellé « Intervention » **13.5px / 700** blanc. Pas de bordure.

---

## §5. Carte intervention

- Coque : `background:#fff; border-radius:16px; overflow:hidden; box-shadow:--shadow-md`.
- Bandeau cliquable : `background:--green-800; padding:14px 16px; display:flex; align-items:center; gap:14px`. Survol `--green-900`. Toute la surface est la cible.
- **Pastille de statut** (gauche, 34×34) :
  - Encodée : `border-radius:999px`, fond blanc, coche **19px** trait **3**, couleur `--green-700`.
  - Vide : `border-radius:10px`, fond `rgba(255,255,255,.16)`, bordure `1.5px dashed rgba(255,255,255,.55)`, **numéro** 15px / 800 blanc, tabulaire.
- Titre : **16.5px / 700** / `#fff`, `line-height:1.3`, `text-wrap:pretty`. Les noms longs passent sur deux lignes — **jamais d'ellipse**, le nom d'intervention est un acte médical, il doit être lisible en entier.
- Compteur : **12.5px / 600** / `--green-300`, tabulaire — `2 matériels · 4 unités` (références · unités) ou « Aucun matériel encodé ».
- **Pilule statut** : hauteur **26**, `padding:0 11px`, `border-radius:999px`, **11.5px / 800**, majuscules, `letter-spacing:.03em`.
  - Encodée : fond `rgba(255,255,255,.16)`, texte `--green-200`, libellé « Complété ».
  - Vide : fond `--amber-500`, texte `--gray-950`, libellé « À compléter ».
- Chevron : cercle **34×34** `rgba(255,255,255,.14)`, icône **18px** trait **2.6** blanche, rotation **180°** à l'ouverture, `transition: transform 200ms cubic-bezier(.22,1,.36,1)`.
- Corps (visible ouvert) : matériel `padding:6px 16px 0` sur blanc.
- Encart d'incitation (intervention vide) : `margin:12px 0 2px; padding:12px 14px; border-radius:12px`, fond `--amber-50`, bordure `1px solid --amber-100`, icône alerte **18px** `--amber-700`, texte **13px / 600** `--amber-700` : « Ajoutez le matériel utilisé pour valider cette intervention. »
- Barre d'actions : `display:flex; align-items:center; gap:10px; padding:12px 16px 14px`.
  - « Ajouter du matériel » : `flex:1`, hauteur **46**, `border-radius:12px`, fond `--green-600` (survol `--green-700`), texte **14px / 700** blanc, icône **+ 17px** trait **2.6**.
  - « Modifier » : hauteur **46**, `padding:0 14px`, bordure `1px solid --gray-200`, fond blanc, **13.5px / 600** `--gray-700` (survol fond `--gray-75`).
  - Corbeille intervention : **46×46**, bordure `--gray-200`, icône **16px** `--gray-500` ; survol fond `--red-50`, texte `--red-700`, bordure `--red-100`.

---

## §5bis. Animation d'ouverture / fermeture

- Le corps de carte est **toujours monté** ; il est enveloppé dans `.ivc__reveal` (`overflow:hidden`) dont la `max-height` passe de 0 à la hauteur mesurée.
- Mesure : `ResizeObserver` sur le contenu (`.ivc__body`), recalculée à l'ouverture, quand le matériel change et au redimensionnement — un titre peut passer sur deux lignes en rotation d'écran. Repli `window.addEventListener('resize')` si `ResizeObserver` est absent.
- Transitions : `max-height 300ms cubic-bezier(.22, 1, .36, 1)` sur `.ivc__reveal` ; `opacity 200ms ease 40ms` + `transform 260ms cubic-bezier(.22, 1, .36, 1) 40ms` sur `.ivc__body` (translation de `-6px` vers 0) ; chevron `transform: rotate(180deg)` en `260ms`.
- Carte fermée : `aria-hidden` sur le corps et `tabIndex={-1}` sur ses boutons — pas de piège au clavier.
- `prefers-reduced-motion: reduce` : toutes ces transitions sont neutralisées.

**Interdits** — `grid-template-rows: 0fr→1fr` (non interpolé iOS < 17 / Chrome < 107), animation de `height:auto` (impossible), `max-height` forfaitaire (clippe et retarde la fermeture), démontage conditionnel du corps (rien à animer en fermeture).

---

## §6. Défilement automatique (comportement demandé)

**Intention** : après un appui sur un bandeau, le haut de la carte concernée doit se retrouver en haut de la zone visible, avec une animation de déroulement rapide. L'utilisateur ne cherche jamais où il en est.

### Règles

| Action | Comportement |
| --- | --- |
| J'ouvre une intervention | Le haut de **cette** carte monte en haut de l'écran (moins l'offset), animation 320ms |
| Je clique sur une **autre** intervention | La précédente se ferme (accordéon exclusif) et le haut de la **nouvelle** carte monte en haut, même animation |
| Je **ferme** l'intervention ouverte | On remonte pour que le **haut de la liste** (en-tête INTERVENTIONS) soit en haut de l'écran |
| `prefers-reduced-motion: reduce` | Positionnement instantané, sans animation |

### Paramètres exacts
- Durée : **320ms**. Easing : `easeOutQuint` → `1 - (1-t)^5` (départ franc, arrivée douce ; c'est ce qui donne la sensation « rapide »).
- Offset au-dessus de la carte : **12px** (`SCROLL_OFFSET`). **Si l'app a une barre sticky, ajouter sa hauteur à cette constante**, sinon la carte se cale sous la barre.
- La cible est mesurée **après** le dépliage : deux `requestAnimationFrame` imbriqués avant de calculer, sinon iOS mesure la hauteur d'avant.
- Une seule animation à la fois : le `requestAnimationFrame` en cours est annulé avant d'en lancer une autre (appuis rapides successifs).
- Le conteneur défilant est résolu dynamiquement : premier ancêtre en `overflow-y:auto|scroll` réellement débordant, sinon la fenêtre. Indispensable pour un affichage en modal/tablette où la liste défile dans un panneau.

### ⚠ Ne pas utiliser `scrollIntoView`
`element.scrollIntoView({ behavior: 'smooth', block: 'start' })` est **interdit ici** :
- iOS Safari < 15.4 ignore l'objet d'options → saut brut, sans animation.
- Certaines WebViews Android (Chrome < 61, WebViews embarquées de l'app) l'ignorent aussi ou animent de façon erratique.
- Impossible de compenser un en-tête sticky : la carte se cale **sous** la barre.

Le fichier `src/useCardScroll.ts` fait le calcul et l'animation à la main (`requestAnimationFrame` + `window.scrollTo(0, y)` / `container.scrollTop`), ce qui est fiable sur les deux plateformes.

---

## §7. Ligne matériel

- `display:flex; align-items:center; gap:12px; padding:13px 0; border-bottom:1px solid --gray-150`. La **dernière ligne garde sa bordure** : elle sépare du bloc d'actions.
- Chip fabricant : **38×38**, `border-radius:10px`, fond `--green-50`, bordure `1px solid --green-200`, texte **10.5px / 800** `--green-800` — 2 à 3 initiales (`S&N`, `ARX`, `GM`), dérivées du fabricant si non fournies.
- Nom : **15px / 700** `--gray-950`.
- Badge catégorie à côté du nom : hauteur **19**, `padding:0 8px`, `border-radius:999px`, fond `--blue-50`, bordure `--blue-100`, texte `--blue-700` **10.5px / 800** majuscules, `letter-spacing:.03em`.
- Méta : **12px** `--gray-500`, format `Fabricant · Référence`.
- **Stepper** : pilule `background:--gray-75; border:1px solid --gray-200; border-radius:11px; padding:3px; gap:2px`.
  - Boutons **32×32**, blanc, bordure `1px solid --gray-200`, `border-radius:8px`, glyphes `−` / `+` **16px / 700** `--gray-700` ; survol bordure `--green-600` + texte `--green-700`.
  - Valeur `x3` : **15px / 800** `--gray-950`, `min-width:38px`, centrée, tabulaire.
  - `−` désactivé à 1 (`opacity:.45; cursor:default`) : pour retirer, on utilise la corbeille.
- Corbeille ligne : **34×34**, `border-radius:9px`, bordure `1px solid --gray-200`, icône **15px** `--gray-400` ; survol fond `--red-50`, texte `--red-700`, bordure `--red-100`.

---

## §8. Pied de validation

`display:flex; align-items:center; gap:14px; padding:14px 16px; border-radius:16px; background:--green-900; box-shadow:--shadow-md`.

- Titre : `Terminer l'encodage · 2/3` — **15px / 800** blanc, tabulaire.
- Sous-titre : **12.5px / 600** `--green-300` — même phrase que l'incitation du bloc progression.
- Bouton « Valider » : hauteur **46**, `padding:0 20px`, `border-radius:12px`.
  - Actif : fond blanc, texte `--green-900` **14.5px / 800**, coche **17px** trait **2.8** ; survol `--green-50`.
  - Inactif : fond `rgba(255,255,255,.14)`, texte `rgba(255,255,255,.55)`, `cursor:default`, attribut `disabled`, sans icône.

---

## §9. Responsive — mobile-first, 4 paliers

`interventions.css` est écrit **mobile-first** : le bloc de base est le smartphone portrait, les paliers `min-width` n'ajoutent que de la respiration. Ne jamais le réécrire en `max-width`.

`.enc` : `display:flex; flex-direction:column; gap:18px; width:100%; max-width:760px; margin:0 auto; padding:0 16px 20px`.

### Base — smartphone portrait (≤ 599px), le cas principal
- Bacs : `border-radius:16px; padding:12px 11px 13px`. `.enc` `gap:18px`.
- Heures : `gap:12px; padding:13px`, total **21px**, « TOTAL NET » **masqué**.
- Progression : `padding:14px 15px`, compteur **26px**.
- Bandeau de carte : `gap:10px; padding:13px; flex-wrap:wrap` ; la pilule de statut passe **sous** le titre (`order:3; margin-left:44px`) ; titre **15.5px**.
- Corps : `padding:6px 13px 0`. Actions : `padding:12px 13px 13px; flex-wrap:wrap`, « Ajouter du matériel » `flex:1 1 100%; order:-1` (pleine largeur, en premier).
- Ligne matériel : `flex-wrap:wrap; row-gap:8px`, info `flex:1 1 60%` → stepper et corbeille passent sous le nom si la référence est longue.
- Pied : `flex-wrap:wrap`, bouton Valider en pleine largeur.
- Cibles : **32px** stepper, **34px** corbeille de ligne, **40px** + Intervention, **46px** CTA.

### ≥ 600px — smartphone paysage, petite tablette
`.enc` `max-width:680px; gap:20px; padding:0 20px 24px`. Bacs `border-radius:18px; padding:13px 13px 14px`. Heures `gap:16px; padding:15px 16px`, total **24px**, « TOTAL NET » **visible**. Progression `padding:16px 18px`, compteur **30px**. Bandeau `gap:14px; padding:14px 16px; flex-wrap:nowrap`, pilule de retour à droite, titre **16px**. Corps `6px 16px 0`, actions `12px 16px 14px` sur une ligne. Ligne matériel `padding:12px 0; flex-wrap:nowrap`. Pied sur une ligne.

### ≥ 900px — tablette paysage, laptop
`.enc` `max-width:720px`. Bandeau `padding:15px 18px`, titre **16.5px**. Corps `6px 18px 0`, actions `12px 18px 15px`. Ligne matériel `padding:13px 0`.

### ≥ 1280px — ordinateur
`.enc` `max-width:760px; gap:22px`. Bandeau `padding:16px 20px`, titre **17px**. Corps `8px 20px 0`, actions `14px 20px 16px`. Ligne matériel `padding:14px 0`, nom **15.5px**.
La souris existe : « Modifier » et la corbeille d'intervention sont à `opacity:.7` et passent à **1** au survol de la carte (`.ivc:hover`). Le survol est un bonus — **jamais** le seul moyen d'atteindre une action.
**Une seule colonne, centrée, 760px maximum.** Pas de passage en deux colonnes : l'encodage est séquentiel, une grille multiplie les erreurs d'attribution du matériel.

### Écran court — `max-height:480px` en paysage
`.enc` `gap:14px`, progression `padding:12px 15px; gap:10px` compteur **24px**, bandeau `padding:11px 14px`. **Aucune cible tactile n'est réduite.**

---

## §10. Compatibilité iOS / Android

| Point | Règle |
| --- | --- |
| Défilement animé | Implémentation manuelle `requestAnimationFrame`, **jamais** `scrollIntoView({behavior:'smooth'})` (voir §6) |
| `scroll-behavior: smooth` en CSS | À **ne pas** mettre sur `html`/`body` : conflit avec l'animation JS sur Android, et perturbe la restauration de position sur iOS |
| Zoom auto sur champ | Toute saisie ≥ **16px** de police ; `-webkit-text-size-adjust:100%` sur `.enc` |
| Flash gris à l'appui | `-webkit-tap-highlight-color: transparent` sur `.enc` et ses boutons ; le retour visuel vient du survol/appui explicite |
| Double-tap zoom | `<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">` au niveau app |
| Encoche / barre gestuelle | Si le pied de validation est rendu en position fixe, ajouter `padding-bottom: env(safe-area-inset-bottom)` |
| `100vh` | À éviter sur mobile (barre d'adresse iOS) : le conteneur d'encodage défile dans le flux, sans hauteur imposée |
| `position: sticky` + `overflow:hidden` | Les cartes utilisent `overflow:hidden` pour le rayon ; **ne pas** rendre un enfant sticky dedans (ignoré sur iOS) |
| `color-mix()` (focus ring) | Supporté iOS 16.4+ / Chrome 111+. Repli : `box-shadow: 0 0 0 3px rgba(66,168,130,.32)` |
| `text-wrap: pretty` | Amélioration progressive : ignoré sur anciens WebViews, aucun impact fonctionnel |
| Rebond élastique iOS | Ne pas verrouiller `overscroll-behavior` sur le conteneur : l'animation de scroll se recale déjà via `min/max` |
| Chevron animé | `transform: rotate()` uniquement (accéléré GPU) |
| Focus ring | `color-mix()` avec repli `@supports` → `rgba(66,168,130,.32)` |
| Dépliage de carte | `max-height` mesurée + `opacity` (§5bis). **Pas** de `height` animée (reflow permanent, saccadé sur Android bas de gamme), **pas** de `grid-template-rows: 0fr→1fr` (non interpolable iOS < 16) |
| Fermeture sur Safari | Reflow forcé (`void el.offsetHeight`) entre la pose de la hauteur mesurée et le passage à 0, sinon la transition est ignorée |

---

## §11. États & interactions

| Interaction | Effet attendu |
| --- | --- |
| Appui sur un bandeau | Déplie cette intervention, **ferme toutes les autres**, scrolle la carte en haut (§6) |
| Appui sur le bandeau déjà ouvert | Referme, scrolle en haut de liste |
| Chargement de l'écran | La **dernière** intervention sans matériel est ouverte ; si tout est encodé, celle du haut |
| « + Intervention » (en-tête) | Ouvre la saisie ; la nouvelle carte s'insère **en haut**, ouverte, statut « À compléter » |
| `+` / `−` | Quantité ±1, minimum 1 ; mise à jour immédiate du compteur de carte, du résumé de liste, de la jauge et du pied |
| Corbeille ligne | Retire la référence. Si c'était la dernière : la carte repasse « À compléter », la jauge recule, l'encart ambre apparaît, le pied se désactive |
| Toutes encodées | Pilules « Complété », jauge pleine, pied actif, « Tout est encodé. » |
| Clavier / lecteur d'écran | `aria-expanded` sur les bandeaux, `aria-live="polite"` sur la quantité, `role="progressbar"` + `aria-valuenow` sur la jauge, libellés `aria-label` sur toutes les icônes seules |
| Focus visible | `box-shadow: 0 0 0 3px color-mix(in srgb, var(--green-500) 32%, transparent)`, pas d'`outline` |
| `prefers-reduced-motion` | Transitions désactivées **et** défilement instantané |

Aucune sauvegarde manuelle : chaque callback écrit immédiatement, comme le reste de l'écran d'encodage.

---

## §12. Copie

Français, casse phrase, aucun emoji. Libellés **exacts** :

« TEMPS DE TRAVAIL » · « 1 saisie » · « MATÉRIEL PAR INTERVENTION » · « Heures prestées » · « TOTAL NET » · « Non renseignées — appuyez pour saisir » · « interventions encodées » · « Mission complète » · « Encore N intervention(s) et la mission est complète. » · « Tout est encodé. Vous pouvez valider la mission. » · « INTERVENTIONS » · « Intervention » (bouton d'ajout) · « Complété » · « À compléter » · « Aucun matériel encodé » · « N matériels · N unités » · « Ajoutez le matériel utilisé pour valider cette intervention. » · « Ajouter du matériel » · « Modifier » · « Supprimer l'intervention » (title) · « Retirer ce matériel » (title) · « Terminer l'encodage · x/n » · « Valider »
