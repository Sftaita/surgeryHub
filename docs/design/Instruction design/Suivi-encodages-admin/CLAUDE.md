# Instructions d'intégration — pour Claude Code (VS Code)

Tu intègres la page admin **Suivi des encodages** (liste + tiroir de détail) dans l'app React Surgery Hub. Ce dossier est une **copie conforme** d'une maquette validée : la brancher, pas la redessiner.

---

## 0. Règle numéro un
**Aucune valeur visuelle ne change.** Les tailles à la demi-décimale (13.5px, 11.5px, 12.5px), les hauteurs (72px de ligne, 40px d'en-tête, 34px d'en-tête de jour, 560px de tiroir) et les couleurs sont validées. `DOCUMENTATION.md` fait foi.

Tu ne modifies que : les chemins d'import, le branchement des données/callbacks, et le filtrage/tri (aujourd'hui fait par l'appelant).

## 1. Installation
```
src/features/encodings/   ← copier ./src
```
Aucune dépendance (les icônes sont des SVG inline dans `Icon.tsx`). Police **Inter**, chiffres en `tabular-nums` (classe `.tabular`, déjà posée partout où il y a des nombres).

## 2. Architecture
```
EncodingsPage                      .sec-page (+ .sec-page--drawer quand le tiroir est ouvert)
├── barre de titre                 recherche · période segmentée · navigation semaine
├── AttentionCard + WeekFunnel     décision d'abord, statistiques ensuite
├── barre d'outils                 onglets · puces de filtres actifs · Filtres · Exporter
│   └── FiltersPanel               popover desktop / feuille basse mobile
├── MissionsTable                  groupé par jour, en-têtes collants
│   └── MissionRowItem → HoursGauge
└── MissionDrawer                  détail : heures, frise, interventions, pied d'action
```
Composants **purs** : aucun appel réseau, aucun état métier. Seuls états internes : id ouvert, onglet, période, ouverture du panneau de filtres.

## 3. Les règles à ne pas casser

1. **Le tiroir rétrécit la colonne, il ne la recouvre pas.** `.sec-page--drawer .sec-page__main { margin-right: 560px }`. C'est le cœur de la proposition : l'admin garde la liste sous les yeux et enchaîne les validations. Ne le remplace pas par une modale centrée.
2. **Groupement par jour obligatoire.** En-tête collant avec date longue, nombre de missions, heures planifiées, et alerte rouge si le jour contient du retard. Le tri à l'intérieur d'un jour suit l'heure de début.
3. **Le rouge est réservé au retard et à l'anomalie.** « À encoder » est **ambre**, pas rouge (la capture d'origine mettait tout en rouge : plus rien ne ressortait). Le bleu ne sert qu'à l'information (« Soumis », « à valider », badge délégué), jamais à une action.
4. **Encre lisible sur les jauges.** Le libellé posé sur la barre ambre est en `--gray-950` (`GAP_INK`), blanc uniquement sur rouge et vert. Ne pas uniformiser en blanc : contraste insuffisant.
5. **Le bouton de validation est désactivé tant que `cta !== 'on'`** (encodage en brouillon ou déjà validé). L'état vient des données, jamais d'un clic optimiste.
6. **Les filtres annoncent leur résultat avant application** (« 6 missions correspondent », « Voir les 6 missions » en mobile). Chaque option porte son compteur — une option à 0 reste visible mais non pertinente.
7. **Quantités lisibles** : `×3`, jamais deux nombres nus côte à côte.

## 4. Responsive — desktop-first, deux bascules

C'est un poste de travail : le bloc de base est le desktop, puis deux bascules CSS.

| Palier | Cible | Ce qui change |
| --- | --- | --- |
| base ≥ 1101px | **Ordinateur** | Colonne rétrécie par le tiroir (560px), tableau à colonnes fixes, filtres en popover ancré sous le bouton |
| ≤ 1100px | Tablette paysage / petite fenêtre | Le tiroir devient une **feuille plein écran** (le contenu ne se rétrécit plus), les filtres passent en **feuille basse**, le résumé s'empile |
| ≤ 720px | **Smartphone** | Le tableau devient une **pile de cartes** (`.sec-table__head` masqué, `.sec-row` en carte de 14px de rayon) : heure + IBODE + état sur une ligne, chirurgien dessous, jauge + montant en bas. Onglets pleine largeur, puces de filtres masquées (elles vivent dans la feuille), pied de tiroir avec `env(safe-area-inset-bottom)` |

Cibles tactiles en mobile : ≥ 36px (options de filtre), 48px (bouton d'application). Jamais de scroll horizontal sur le tableau — c'est la raison de la bascule en cartes.

## 5. Accessibilité & plateformes
- Chaque ligne est un `<button>` : navigable au clavier, `focus-visible` en anneau vert 3px (repli `@supports` pour `color-mix`).
- Le tiroir est un `<aside role>` avec `aria-label`; à l'intégration, ajoute un piège de focus et la fermeture sur `Escape` (l'app décide de sa mécanique de modale).
- `prefers-reduced-motion` : la transition de largeur est neutralisée.
- Pas de `100vh` ; le tiroir est `position: fixed` avec `inset` — safe-area gérée en mobile.

## 6. Checklist de recette
- [ ] Ouvrir une mission rétrécit la colonne, la liste reste lisible (Encodage / Heures / Finance visibles).
- [ ] Les en-têtes de jour restent collés au défilement.
- [ ] « À encoder » + « retard » tiennent sur une ligne, sans retour à la ligne.
- [ ] Le libellé de la barre « Réel » est lisible sur ambre.
- [ ] Le panneau de filtres affiche les compteurs et le nombre de résultats avant application.
- [ ] À 1100px : le tiroir passe en feuille ; à 720px : le tableau passe en cartes, aucun scroll horizontal.
- [ ] « Valider » reste désactivé sur un encodage en brouillon.
- [ ] Clavier : chaque ligne est atteignable, l'anneau de focus vert est visible.

Toute divergence volontaire avec `DOCUMENTATION.md` doit être **signalée**, pas appliquée en silence.
