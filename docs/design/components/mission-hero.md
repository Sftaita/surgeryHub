# MissionHero — card « Mission du jour »

Capture : `screens/aujourdhui/*.png` · **Hauteur totale fixe : 345px** (les deux états).

## État avec mission
1. **Photo** 186px (`imageUrl` backend, `center/cover`) + voile `linear-gradient(180deg, rgba(11,19,32,.15), rgba(20,77,56,.82))`. Posés dessus : pastille « MISSION DU JOUR » (fond `rgba(20,77,56,.9)`, 10.5/800 tracking .06em) à gauche, StatusPill « En cours » à droite (point pulsant) ; en bas : site 18/800 blanc (text-shadow léger) + adresse 13px blanc 85% + DateTile blanche 48×52.
2. **Corps** (padding 14px 18px 18px, flex column) : ligne horaires (horloge 19px `gray-800` + `07h30 → 15h30` 21/800 tabular + durée 14 `gray-400`) ; ligne chirurgien (icône + 14.5/600) ; **CTA « Encoder la mission »** 52px `green-800` + chevron, calé en bas (`margin-top:auto`).
3. Card : radius 18, shadow-md, overflow hidden.

## État journée libre (aucune mission)
Même hauteur : zone illustrée 186px (fond `freeDayCard`) — illustration SVG animée (parasol sway 5s, soleil `#F6C56B` float 4.5s, 2 vagues dash défilantes 6/9s, oiseaux) ; corps centré : « Aucune mission aujourd'hui » 17/800 + texte muted 13px + CTA « Voir les offres disponibles » 52px `green-800`.

## Repli sans photo
`imageUrl` absent → remplacer la photo par le dégradé `encodeHeader` uni (mêmes 186px, mêmes pastilles).

## Zones intouchables
Hauteur 345px · voile sur photo · CTA en bas.
