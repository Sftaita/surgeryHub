# Suivi des encodages (admin) — React

Refonte de `/app/m/billing/encodings` et de la page de détail mission, d'après la maquette validée `Suivi Encodages Admin.dc.html`. React + CSS, **zéro dépendance** (icônes SVG inline), style 100 % tokens du design system Surgery Hub.

> **Intégration : lire `CLAUDE.md`** — installation, règles à ne pas casser, checklist.
> **Valeurs pixel : `DOCUMENTATION.md`** — chaque composant au pixel, responsive, états.

## Ce qui change

| Avant | Après |
| --- | --- |
| 6 compteurs de même poids (dont « Anomalies 0 ») | Un bloc de décision « À traiter maintenant · 8 » + un entonnoir de semaine en barre segmentée |
| 6 menus déroulants dans un grand cadre vide | Une ligne d'outils : onglets, puces de filtres actifs, bouton Filtres (panneau à 4 familles avec compteurs), Exporter |
| Tableau aux cellules qui retournent à la ligne, actions coupées à droite | Lignes de 72px, colonnes fixes, écart horaire en jauge, chevron d'ouverture |
| Pas de repère temporel | **Groupement par jour**, en-tête collant : date, nombre de missions, heures planifiées, alerte retard |
| « Pas encore calculable » répété | `—` / « à valider » / montant |
| Détail = page séparée, 4 cartes label/valeur, scroll interminable | **Tiroir de 560px** : la liste reste visible et la colonne se rétrécit — on enchaîne les validations |
| « 1 1 » sans en-tête | `×3` lisible, interventions groupées par firme, badge « Délégué présent » avec icône |
| Aucune action | Pied collant : « Relancer » + « Valider l'encodage » (désactivé tant que l'encodage est en brouillon) |

## Montage

```tsx
import { EncodingsPage } from '@/features/encodings';
import { mockRows, mockFilters, mockFunnel, dayLabel } from '@/features/encodings/mockData';

<EncodingsPage
  rows={rows}                  // MissionRow[] — déjà filtrées/triées côté appelant
  filterGroups={filters}
  funnel={mockFunnel}
  periodLabel="7 jours"
  dayLabel={dayLabel}          // "lun. 7 sept." → "Lundi 7 septembre"
  onToggleFilter={(group, option) => toggleFilter(group, option)}
  onClearFilters={clearFilters}
  onRelance={(id) => sendReminder(id)}
  onValidate={(id) => validateEncoding(id)}
/>
```

Sous-composants réutilisables : `MissionsTable`, `MissionRowItem`, `MissionDrawer`, `FiltersPanel`, `AttentionCard`, `WeekFunnel`, `HoursGauge`.

## Fichiers
```
CLAUDE.md            instructions d'intégration (à lire en premier)
DOCUMENTATION.md     spécification pixel par pixel
src/
  index.ts           barrel
  types.ts           MissionRow, Intervention, MaterialLine, EncodingStep, FilterGroup
  tokens.ts          STATE_TONE, GAP_BAR / GAP_FG / GAP_INK, initials()
  EncodingsPage.tsx  page complète (barre de titre, résumé, outils, tableau, tiroir)
  AttentionCard.tsx  bloc vert « À traiter maintenant »
  WeekFunnel.tsx     entonnoir de la semaine
  MissionsTable.tsx  tableau groupé par jour (en-têtes collants)
  MissionRowItem.tsx une ligne / une carte en mobile
  HoursGauge.tsx     jauge d'écart horaire
  MissionDrawer.tsx  tiroir de détail (= feuille plein écran en mobile)
  FiltersPanel.tsx   panneau de filtres (popover desktop / feuille basse mobile)
  encodings.css      tout le style + les deux bascules responsive
  mockData.ts        jeu de données de la maquette
```

Prérequis : tokens du design system chargés (`tokens/colors.css`, `effects.css`, `fonts.css` — Inter). Le repli de tokens du package `handoff-encodage-interventions` convient si besoin.
