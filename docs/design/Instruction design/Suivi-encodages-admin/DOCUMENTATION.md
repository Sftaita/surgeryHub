# Spécification pixel — Suivi des encodages (admin)

Référence : `Suivi Encodages Admin.dc.html`. Valeurs **exactes** ; les demi-pixels sont volontaires.

## §1. Page
`.sec-page` : flex, fond `--gray-50`, police 14px, encre `--gray-900`.
`.sec-page__main` : `flex:1; min-width:0`, `transition: margin-right 220ms cubic-bezier(.22,1,.36,1)`.
Tiroir ouvert → `margin-right: 560px`.
Contenu : `padding:18px 28px; gap:16px`.

## §2. Barre de titre
`padding:22px 28px 16px`, fond blanc, filet bas `--gray-150`, `gap:20px`, `align-items:flex-end`.
- Titre **24px / 800**, `letter-spacing:-.02em`, `--gray-950` ; sous-titre **13px** `--gray-500`.
- Recherche : **230px × 38px**, `border-radius:10px`, bordure `--gray-200`, icône 16px.
- Segmenté période : hauteur **38px**, `padding:3px`, fond `--gray-75`, items **30px / 12.5px / 700**, actif blanc + `--shadow-xs`.
- Flèches semaine : **38×38**, `border-radius:10px`.
- Tous les contrôles de droite sont `flex:none` ; le titre garde `min-width:240px` (sinon il se casse mot à mot).

## §3. Bloc « À traiter maintenant »
`width:330px`, `border-radius:16px`, fond `--green-900`, `padding:16px 18px`, `gap:12px`, `--shadow-md`.
Eyebrow **11.5px / 800**, `letter-spacing:.09em`, `--green-300`. Nombre **44px / 800**, `letter-spacing:-.03em`. Phrase **13px / 600** `--green-200`.
Trois tuiles `flex:1`, `padding:9px 11px`, `border-radius:11px`, fond `rgba(255,255,255,.12)` : nombre **19px / 800**, libellé **11.5px / 700** (`--amber-100` retard, `--green-200` à relancer, `--blue-100` à valider).

## §4. Entonnoir de la semaine
Carte blanche, bordure `--gray-150`, `border-radius:16px`, `--shadow-sm`, `padding:16px 18px`, `gap:14px`.
Barre : `height:14px; gap:3px`, chaque segment `flex: count`, `border-radius:4px`. Couleurs : terminées `--gray-300`, à encoder `--amber-500`, en cours `--amber-100`, soumises `--blue-500`, validées `--green-500`.
Légende : pastille 9×9 `border-radius:3px`, nombre **19px / 800**, libellé **11.5px / 700** `--gray-500` indenté de 16px.

## §5. Barre d'outils
`gap:10px`, `position:relative` (ancre du popover).
Onglets : `padding:3px`, fond `--gray-100`, `border-radius:11px` ; item **32px**, `padding:0 13px`, **13px / 700** ; actif blanc + `--shadow-xs` ; compteur pastille `--red-600`/blanc **11px / 800**.
Puce de filtre actif : **32px**, `padding:0 9px 0 11px`, fond `--green-50`, bordure `--green-200`, texte `--green-800` **12.5px / 700**, nom de champ en `--green-600` / 600.
Boutons fantômes : **32px**, `padding:0 12px`, bordure `--gray-200`, **12.5px / 700**.

## §6. Panneau de filtres
Popover : `top:40px; right:128px; width:520px`, `border-radius:14px`, ombre `0 18px 44px -14px rgba(11,19,32,.35)`.
En-tête `padding:12px 14px` : titre **12.5px / 800**, pastille « N actifs » `--green-100`/`--green-800`, lien « Tout effacer » **12px / 700**.
Corps : grille **2 colonnes**, `gap:14px 18px`, `padding:12px 14px`. Option : **28px**, `padding:0 10px`, `border-radius:8px`, bordure `--gray-200` ; active → bordure `--green-500`, fond `--green-50`, texte `--green-800`, coche 13px. Compteur **11px / 700**.
Pied : `padding:11px 14px`, fond `--gray-50` ; « N missions correspondent » **12px** `--gray-500`, boutons **34px**.
Quatre familles : **SITE · ÉTAT D'ENCODAGE · INSTRUMENTISTE · ÉCART HORAIRE**.

## §7. Tableau
Carte : `border-radius:16px`, bordure `--gray-150`, `--shadow-sm`.
En-tête : **40px**, fond `--gray-75`, **11.5px / 800**, `letter-spacing:.06em`, `--gray-500`.
Colonnes : heure **52** · instrumentiste **172** · chirurgien/site `flex:1` · encodage **186** · heures **150** · finance **110** (aligné à droite) · chevron **26**. `gap:14px`, `padding:0 18px`.
En-tête de jour : **34px**, fond `--gray-75`, `position:sticky; top:0` — date **12px / 800** `--gray-800`, compteur et heures **11.5px / 700** `--gray-500`, alerte `--red-700` **11.5px / 800`.
Ligne : **72px**, filet bas `--gray-150`, survol `--gray-50`, sélection fond `--green-50` + `inset 3px 0 0 --green-600`.
- Heure **14px / 800** tabulaire ; avatar 30×30 `--gray-100` initiales **11px / 800**.
- Nom **13.5px / 700** ; chirurgien **13.5px / 600** `--gray-800` ; site **12px** `--gray-500`, ellipse.
- Pastille d'état **24px**, `padding:0 10px` ; « retard » pastille `--red-50`/`--red-700` avec horloge 12px — les deux en `white-space:nowrap`.
- Jauge : « 8h45 » **13.5px / 800**, « / 10h00 » **12px** `--gray-400`, piste 5px `--gray-150`, remplissage `GAP_BAR`.
- Finance **13.5px / 800** : `—` en `--gray-300`, « à valider » en `--blue-700`, montant en `--gray-950`.

## §8. Tiroir
`width:560px`, fond `--gray-50`, ombre `-24px 0 60px -20px rgba(11,19,32,.45)`.
En-tête blanc `padding:16px 20px 0` : titre **19px / 800**, pastille d'état, pastille retard. Sous-titre **12.5px** `--gray-500`.
Bandeau personnes : `padding:13px 20px 14px`, avatars 28px (instrumentiste en `--green-100`/`--green-800`), nom **12.5px / 700**, rôle **11px** `--gray-500`.
Corps : `padding:16px 20px; gap:14px`.
- **Heures** : carte blanche `border-radius:14px`, `padding:14px 16px 16px`. Deux barres de **22px** (`border-radius:7px`) : planifié fond `--gray-200`, réel remplissage `GAP_BAR` largeur = `realPct`, libellé interne **11.5px / 700** — encre `GAP_INK` (sombre sur ambre). Libellés de gauche 62px, totaux 44px alignés à droite. Écart **12.5px / 800** en `GAP_FG`. Note **12px / 600** avec icône info.
- **Frise** : trois colonnes `flex:1`, barre 4px (`--green-500` faite / `--gray-200` à venir), libellé **11.5px / 800**, horodatage **11px** `--gray-400`.
- **Interventions** : en-tête bordé `padding:13px 16px 11px` + résumé « 3 interventions · 6 références · 6 unités ». Liste `overflow-y:auto`, `gap:10px`, cartes `flex:none` (sinon elles se compriment et coupent la dernière référence). En-tête d'intervention : pastille verte 22px, nom **13.5px / 700**, firme **11.5px** `--gray-500`, badge « Délégué présent » (icône personne 13px, `--blue-50`/`--blue-100`/`--blue-700`). Ligne matériel : nom **13px / 700**, référence **11.5px** `--gray-500`, quantité `×N` en pastille **22px** `--gray-75` bordée.
- **Vide** : encart ambre `--amber-50`/`--amber-100`, texte `--amber-700` **12.5px / 600**.
Pied : blanc, filet haut, `padding:13px 20px` — titre **12.5px / 800**, sous-titre **11.5px** `--gray-500`, « Relancer » fantôme **40px**, CTA **40px** `padding:0 18px` **13.5px / 800** (vert actif / `--gray-150`+`--gray-400` désactivé / `--green-100`+`--green-800` validé).

## §9. Responsive
**≥ 1101px** — état de référence ci-dessus.
**≤ 1100px** — `margin-right` annulé ; tiroir plein écran (`left:0; top:24px; border-radius:18px 18px 0 0`) ; filtres en feuille basse (`inset:auto 0 0 0`, `border-radius:22px 22px 0 0`, une colonne) ; résumé empilé, `AttentionCard` pleine largeur.
**≤ 720px** — titre 20px, recherche en 3ᵉ position pleine largeur, flèches masquées ; tableau en cartes : en-tête masqué, `.sec-row` en carte (`border-radius:14px`, `padding:12px 13px`, `row-gap:9px`), ordre heure+IBODE+état / chirurgien / jauge+montant, chevron et site masqués ; onglets `flex:1` ; puces de filtres masquées ; pied de tiroir avec `env(safe-area-inset-bottom)` ; options de filtre 36px, bouton d'application 48px.
**`prefers-reduced-motion`** — transition de largeur neutralisée.

## §10. Copie
« Suivi des encodages » · « À TRAITER MAINTENANT » · « missions demandent une action de votre part » · « en retard » / « à relancer » / « à valider » · « AVANCEMENT DE LA SEMAINE » · « Toutes les missions » / « À traiter » / « Par instrumentiste » · « Filtres » / « Tout effacer » / « N missions correspondent » / « Appliquer » / « Voir les N missions » · « HEURE / INSTRUMENTISTE / CHIRURGIEN · SITE / ENCODAGE / HEURES / FINANCE » · « À encoder » / « En cours » / « Soumis » / « Validé » / « retard » · « à valider » · « HEURES » / « Planifié » / « Réel » · « Brouillon » / « Soumis » / « Validé » · « INTERVENTIONS & MATÉRIEL » · « Délégué présent » · « Aucun matériel encodé — l'instrumentiste n'a pas encore ouvert son encodage. » · « Relancer » · « Valider l'encodage » / « Encodage validé ».
