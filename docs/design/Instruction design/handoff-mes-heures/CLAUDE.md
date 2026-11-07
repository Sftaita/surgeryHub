# Instructions d'intégration — Mes heures (app instrumentiste)

Tu ajoutes un écran **« Mes heures »** à l'app instrumentiste. Il affiche les heures du mois : encodées, validées, liste des prestations et estimation financière facultative.

> **VERSION DE RÉFÉRENCE FIGÉE — révision 3, 2026-10-31.** Validée pour l'implémentation. Ne pas modifier ce dossier : tout changement fait l'objet d'une révision 4 explicitement demandée, consignée au § 9.

**Révision 3 — 2026-10-31.** Source de vérité visuelle : le canevas « Mes heures — maquettes » (https://claude.ai/artifact/UM3L8SuFx6bnAjT3RhwgYc, privé — à partager depuis le menu Share). Artboards : **1a** mobile payé à l'heure · **1b** mobile sans tarif · **1f** mobile filtré par site · **1d** mois vide · **1c** desktop payé à l'heure · **1c'** desktop sans tarif filtré · planche « Piste heures · minuit · statuts · contrastes ». La maquette de navigation 1e de la révision 1 (`Mes Heures.dc.html`) reste valable pour le § 5. Branche l'écran sur ces maquettes, ne le redessine pas. Historique des révisions : § 9.

---

## 0. Règle numéro un
**Aucune valeur visuelle ne change.** Les valeurs de `mes-heures.css` sont reprises 1:1 de la maquette. Tu modifies seulement les chemins d'import, l'adaptateur de données (`toPrestation`), le routing et la navigation (§ 5).

## 1. Installation
```
src/features/mes-heures/   ← copier ./src (sauf example.*)
```
- `mes-heures.css` est importé par `MesHeures.tsx`. Les classes ont le préfixe `.mh-`. Aucune couleur codée en dur : tout passe par des variables CSS.
- Aucune dépendance. Il faut la police Inter et les tokens du design system Surgery Hub en **variables CSS** (`--green-*`, `--gray-*`, `--amber-*` dont `--amber-800`, `--shadow-sm`, `--border-subtle`, `--border-default`, `--surface-page`, `--focus-ring`).
- ⚠ **Le frontend ne définit aujourd'hui aucune de ces variables** (thème MUI + constantes en dur dans les composants). Sans elles, l'écran s'affiche sans couleurs. `src/tokens.css`, **généré** depuis `docs/design/design-tokens.json`, les fournit toutes : l'importer **une fois, globalement** (ex. dans `main.tsx`), ou le remplacer par une feuille de tokens commune si elle existe d'ici là. Ne pas l'éditer à la main : régénérer depuis le JSON.
- `--amber-800: #8A5A12` fait désormais partie de `design-tokens.json` (`color.amber.800`). Note : le token sémantique `semantic.status.enAttente.fg` pointe toujours vers `amber.700` (3,4:1 sur `amber-50`) ; ce module ne l'utilise pas. Corriger ce token global est une décision séparée.
- TypeScript : vérifié avec les options de `frontend/tsconfig.app.json` (strict, `noUnusedLocals`, `noUnusedParameters`, `moduleResolution: Bundler`, `jsx: react-jsx`) et `@types/react` 19 : 0 erreur. Le frontend doit déclarer les imports `*.css` (c'est le cas avec `vite/client`).
- Route proposée : `/heures`.

## 2. Architecture
```
                           ┌─ listSites() ─────────▶ puces de site (mois ENTIER)
API ──toPrestation()──▶ Prestation[]
     (à écrire)            └─ filterBySite(filtre) ─▶ items ─┬─ summarize() ─▶ cartes + estimation
                                                             └──────────────▶ liste / tableau
                              (purs, testés)
```
Compteurs, montants et liste sont calculés **sur le même ensemble filtré**. Seules les puces viennent du mois entier.
`MesHeures` est un composant contrôlé pour le mois : c'est la page qui gère `year`, `month` et le fetch. Le filtre par site et l'ouverture du détail des tarifs sont des états internes ; le filtre est réinitialisé à chaque changement de mois.

## 3. L'adaptateur — seul code métier à écrire
`src/example.toPrestation.ts` montre la forme attendue, mais **les noms de champs API y sont supposés**. Réécris-le avec les vrais champs :

| Champ `Prestation` | Sens |
|---|---|
| `missionId` | id pour ouvrir le détail mission existant |
| `start`, `end` | heures **réellement encodées** (ISO), jamais l'horaire planifié. La durée en découle : `durationMinutes()` = fin − début, +24 h si la fin tombe avant le début (passage de minuit) |
| `surgeonName` | « Dr Prénom Nom » |
| `siteId`, `siteName`, `siteShortName?` | `siteShortName` sert aux puces de filtre (« Brugmann ») |
| `status` | `pending` (soumis, en attente du manager) · `validated` (confirmé par le manager) |
| `rateCents` | tarif horaire **personnel** de l'instrumentiste pour **cette** prestation, en centimes, `null` s'il n'y en a pas |

SurgicalHub ne gère **aucune pause** et n'en gérera pas : aucun champ, aucun libellé, aucune modification backend.

La prop `hourly` vient du **profil** de l'instrumentiste (type de rémunération), pas des prestations.

L'API ne doit renvoyer que les missions dont l'encodage est **soumis ou validé** pour le mois demandé. Les brouillons et les missions planifiées ou non encodées sont exclus (`toPrestation` renvoie `null` pour eux).

## 4. Les règles à ne pas casser
1. **Encodées = en attente + validées.** Deux compteurs principaux : **Heures encodées** et **Heures validées** (confirmées par le manager). Jamais d'heures planifiées ni de prévisions dans ce module.
2. **Durées en `82h30`**, horaires en `07h30 → 16h00`, montants en `3 482,75 €`. Toujours utiliser `format.ts`, et `durationMinutes()` pour toute durée.
3. **Le filtre par site s'applique à tout** : cartes, estimation, détail des tarifs, liste et total du tableau. Les puces sont dans le bandeau, au-dessus des cartes. Le périmètre est rappelé à côté du titre de la liste (« 3 prestations · CHU Brugmann ») et, sur desktop, dans le sous-titre du bandeau. Plus de sous-total vert.
4. **Le filtre par site n'apparaît qu'à partir de 2 sites** dans le mois (`showSiteFilter(listSites(...))`).
5. **La section financière est absente quand `hourly=false`.** Pas de section vide ni de mention « tarif non paramétré ». Elle est aussi masquée quand le mois est vide.
6. **L'estimation porte toujours le libellé « ESTIMATION INDICATIVE »**, le signe `≈` et la mention « ni salaire net, ni facture, ni paiement ». Ne jamais afficher de tarif firme, de montant facturé à un tiers ni de statut de paiement.
7. **Le tarif est appliqué par prestation** (`amountCents` = durée de la prestation × son tarif). Il peut varier selon le site ou la prestation ; le détail est regroupé par (site, tarif). Une prestation sans tarif est exclue de l'estimation et signalée par une ligne grise.
8. **Statuts discrets** : un point de 7px suivi du texte. Texte `--amber-800` sur point `--amber-500` pour « En attente de validation », `--green-700` sur point `--green-500` pour « Validé ». Sur desktop, ils sont en pastille 26px. Pas d'icône.
9. **Piste horaire neutre** : chaque prestation se lit **DÉBUT | DURÉE TOTALE | FIN** (en-têtes « TOTAL » sur mobile), avec les heures encodées seules. La durée est une cellule bordée `1.5px currentColor` avec horloge, qui occupe tout l'espace entre début et fin. **Aucune couleur** de comparaison (pas de vert/orange, pas de piste planifiée). Trois informations seulement, rien d'autre dans la cellule de durée. Fin le lendemain → `+1j`.
10. **Chaque ligne ouvre le détail mission EXISTANT** (`onOpenMission`). Ne pas créer de nouvel écran de détail.
11. **Aucune donnée patient.**
12. **Le mois courant est sélectionné par défaut.** La flèche « suivant » est désactivée sur le mois courant. On ne va pas dans le futur.
13. **Liste triée du plus récent au plus ancien.**
14. **Contraste AA (4,5:1) pour tout texte** : `--amber-800` (pas `--amber-700`) pour le texte en attente, `--gray-600` minimum pour les textes secondaires, titres de colonnes et compteurs de puces, bandeau en dégradé `--green-900 → --green-800`. Voir § 9.

## 5. Navigation (maquette 1e, option A recommandée)
Les barres de navigation dessinées sur le canevas sont des **reconstitutions** : à l'intégration, utiliser les vrais composants de navigation SurgicalHub.
Modifier `handoff-instrumentiste-nav` :
- Dans `icons.tsx`, ajouter `ClockIcon` (copie de `src/Icons.tsx`).
- Dans `BottomNav.tsx`, étendre le type de clé à `'today' | 'planning' | 'offers' | 'hours'` et ajouter `{ key: 'hours', label: 'Heures', icon: <ClockIcon /> }` en **4ᵉ position**.
- Dans `SidebarRail.tsx`, ajouter la même clé avec le libellé **« Mes heures »** (`<ClockIcon size={19} />`), juste **après « Offres »**.
- Ne pas toucher au style : l'item actif garde la pastille `--green-800` sur mobile et `--green-50` sur le rail.

Option B, complémentaire : sur « Aujourd'hui », sous « À venir », ajouter une carte raccourci « Mes heures · octobre — 82h30 encodées · 58h15 validées ». Elle utilise `summarize()` sur le mois courant, tous sites.

## 6. Bandeau
Comme l'écran d'Encodage, `MesHeures` rend **son propre bandeau** vert, avec le titre, la navigation mensuelle et les puces de site. Masque le bandeau générique du shell pour cette route. Sur mobile, passe la rangée logo + avatar existante via `headerTop`.

## 7. Ne fais pas
- Calculer une durée autrement qu'avec `durationMinutes()` (fin − début, passage de minuit compris).
- Ajouter une notion de pause, sous quelque forme que ce soit.
- Calculer les cartes ou l'estimation sur le mois entier quand un site est sélectionné.
- Colorer la piste horaire, ou y ajouter des heures planifiées.
- Afficher l'estimation dans les lignes de la liste mobile (desktop seulement, colonnes Tarif / Estimation).
- Ajouter des graphiques : la barre de 4px est le seul indicateur visuel.
- Afficher la section financière avec des valeurs à 0 €.

## 8. Checklist de vérification
- [ ] `summarize.test.ts` passe (20 cas).
- [ ] Octobre 2026 (fixtures), tous sites : 82h30 encodées, 58h15 validées, 24h15 en attente, 71 %.
- [ ] Instrumentiste payé à l'heure, tous sites : ≈ 3 482,75 € (encodées) et ≈ 2 445,50 € (validées), avec 4 lignes de tarif.
- [ ] Filtre « Brugmann » : 3 lignes, cartes 25h45 encodées / 17h15 validées / 8h30 en attente / 67 %, estimation ≈ 1 081,50 € / ≈ 724,50 €, 1 ligne de tarif, total du tableau 25h45.
- [ ] Instrumentiste sans tarif : aucune trace de section financière, ni sur mobile ni sur desktop (pas de 3ᵉ carte, pas de colonnes Tarif / Estimation).
- [ ] m-1251 (16 oct.) affiche `08h00 | 10h00 | 18h00`. Une prestation 22h00 → 03h00 affiche `22h00 | 5h00 | 03h00 +1j`.
- [ ] Aucune valeur de la piste n'est colorée ; aucune heure planifiée n'apparaît nulle part.
- [ ] Un mois vide affiche 0h00 en gris, le message « Aucune prestation encodée en … » et le bouton « Revenir au mois en cours » (absent si le mois vide est le mois courant). Pas de puces.
- [ ] La flèche « suivant » est désactivée sur le mois courant.
- [ ] Une ligne ouvre le détail mission existant.
- [ ] Sur 360px, « En attente de validation » ne chevauche pas l'horaire.
- [ ] Contraste : aucun texte sous 4,5:1 (vérifier au minimum « En attente de validation » sur blanc et sur `--amber-50`, les compteurs de puces, `month__hint` sur le bandeau).
- [ ] Sur mobile, la rangée de puces de site défile horizontalement (sans barre visible) jusqu'à la dernière puce, qui reste cliquable et applique le filtre.
- [ ] `tokens.css` est chargé : `getComputedStyle(document.documentElement).getPropertyValue('--amber-800')` renvoie `#8A5A12`.
- [ ] La bascule mobile/desktop se fait à 900px, comme la nav.
- [ ] La bottom nav a 4 onglets, avec « Heures » en dernier ; « Mes heures » est après « Offres » dans le rail.

## 9. Historique des révisions

### Gel de la révision 3 (2026-10-31)
- `color.amber.800 = #8A5A12` ajouté à `docs/design/design-tokens.json` ; `mes-heures.css` utilise `var(--amber-800)` sans repli codé en dur.
- Ajout de `src/tokens.css` (généré) : le frontend n'expose aucune variable CSS de tokens.
- `Icons.tsx` et `example.MesHeuresPage.tsx` : import `React` inutilisé retiré (`noUnusedLocals`).
- Contrôle navigateur du composant réel (fixtures + `tokens.css`, Chrome) : desktop 1225 px, mobile 390 px et 360 px, filtre Brugmann et Saint-Jean, défilement des puces, sans tarif, mois vide, prestation 22h00 → 03h00 (+1j, 5h00, 210,00 €). Aucune erreur console, aucun débordement horizontal, aucune mention de pause ni d'heure planifiée.
- 20 tests Vitest verts.

### Révision 3 (2026-10-31) — suppression des pauses
SurgicalHub ne gère pas de pause et n'en gérera pas. Retirés : `minutes` et `pauseMinutes` de `Prestation`, `pauseLabel`, les tests et la planche « Pause ». La durée est désormais **calculée** : `durationMinutes()` = fin − début encodés, +24 h si la fin tombe avant le début. Fixture m-1251 revenue à 08h00 → 18h00. Totaux inchangés (82h30 / 58h15, ≈ 3 482,75 € / ≈ 2 445,50 €). Aucune modification backend.

### Révision 2 (2026-10-31) — changements validés
| # | Sujet | Avant | Après |
|---|---|---|---|
| 1 | Pause | — | *(remplacé par la révision 3 : aucune pause)* |
| 2 | Couleur de la durée | — | neutre, confirmée : le statut est porté par son point |
| 3 | Navigation | barres dessinées | reconstitutions, à remplacer par les vrais composants |
| 4 | Filtre par site | liste seule + sous-total vert | cartes, estimation, tarifs, liste et total ; puces dans le bandeau ; sous-total supprimé. API : `summarizeMonth`/`viewBySite` → `listSites` + `filterBySite` + `summarize` |
| 5 | Contraste | `amber-700` (3,6:1), `gray-500` (4,1:1), `gray-400`, bandeau jusqu'à `green-500` | `amber-800` #8A5A12 (5,9:1 ; 5,5:1 sur `amber-50`), `gray-600` (≥ 5,6:1), dégradé `green-900 → green-800` (green-100 ≥ 5,6:1) |
| 6 | Montants | — | confirmés : par prestation, indicatifs, encodées et validées |
| 7 | Heures | — | confirmées : aucune heure planifiée ; encodées = en attente + validées ; validées = 2ᵉ compteur |
| — | Piste horaire | `07h30 → 16h00` + durée à droite | piste DÉBUT \| DURÉE TOTALE \| FIN, reprise du bloc « Heures » du Suivi des encodages, sans planifié ni couleurs |
