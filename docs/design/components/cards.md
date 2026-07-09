# Cards de liste (offres, à venir, à encoder)

## Card offre (écran Offres)
Radius 18, bord `border-subtle`, shadow-xs, padding 16. Structure :
1. Rangée : DateTile 54×58 (teintée statut) + [site 16/800 `green-700` ; zone 13 muted] + StatusPill.
2. Séparateur 1px dashed `gray-200` (marge 14px vertical).
3. Lignes détail (gap 9–10px) : horaire (icône en cercle 34px `green-50` + 14/700 tabular + durée), chirurgien (idem), tags type + spécialité.
4. Actions (si proposée, margin-top 16) : Refuser (outline, flex 1) + Prendre la mission (`green-500`, flex 1.7), 46px.
5. Si prise : bandeau `green-50` radius 11 « ✓ Ajoutée à votre planning » + lien Voir (`green-700`/800).
6. Si refusée : card opacity .55.

## Card « À venir » (accueil)
Radius 16, shadow-xs, padding 13 15. DateTile colonne texte + séparateur vertical 1px + [site 15/700, zone 12.5 muted, rangée heure 14/700 + StatusPill]. Cliquable → modal jour ; hover shadow-sm. Grid `auto-fit minmax(272px,1fr)` gap 12.

## Card « À encoder » (accueil)
Radius 16, **bord `amber-100`**, padding 13 15 : icône cercle 42px `amber-50` + [site 15/700 + pastille « À encoder » ; sous-titre 13 muted tabular] + bouton Encoder 40px `amber-600`.

## Mini-card rail (offres, accueil)
256px min, radius 16, padding 13 14 : DateTile 46×50 `green-100` + [site 14/700 `green-700`, heures 12.5 muted] + chevron `gray-300`. Rail scroll-x, gap 12, affleure les bords (marges −20px).

## Card intervention (encodage)
Radius 16, accordéon : en-tête 15/700 + point 8px `green-500` + compteur 12.5 muted + chevron pivotant (200ms) ; lignes matériel 14px séparées par dashed `gray-150` avec badges (Nouveau / À préciser) + quantité `x1` 13.5/700 `gray-500` ; pied « + Ajouter du matériel » 46px texte `green-700`/700, hover `green-50`.
