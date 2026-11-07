# Instructions d'intégration — Heures instrumentistes (module manager)

Tu ajoutes au manager : une carte **« Top 5 des instrumentistes »** sur le Dashboard, une page **`Facturation → Heures instrumentistes`** et un **tiroir de détail individuel**. Les données et les règles sont celles de « Mes heures » (D-179, `docs/api.md` § 49).

> **VERSION DE RÉFÉRENCE FIGÉE — révision 3, 2026-11-03.** Validée pour l'implémentation (neuf planches, décisions 1–6, convention de repli § 8). Ne pas modifier ce dossier : tout changement fait l'objet d'une révision 4 explicitement demandée, consignée au § 11. Source de vérité visuelle : le canevas « Suivi des heures — maquettes » (https://claude.ai/artifact/PxtCiGiMguGeyMBrXNip2T, privé). Artboards : **1a** carte desktop · **1b** carte mobile · **1c** états de la carte · **2a** page desktop · **2b** page mobile · **2c** états de la page · **3a** tiroir desktop · **3b** détail mobile. Historique : § 11.

---

## 0. Règle numéro un
**Le serveur calcule tout** : périmètre, statuts, durées, montants, totaux, filtres (site + recherche), pourcentages, période de référence, caractère provisoire et ordre de tri. Le front affiche (CLAUDE.md racine, D-179 § 3). Aucune somme, aucun pourcentage, aucune durée, aucune largeur de barre recalculés côté client — la ligne Total et la barre du Top 5 comprises (champ `ratio` fourni par le serveur).

## 1. Décisions validées (2026-11-03)
1. **Liste** : toutes les instrumentistes **actives**, y compris à 0h00, **plus** les instrumentistes devenues **inactives** ayant au moins une prestation (soumise ou validée) dans le mois et le site sélectionnés. Étiquette neutre « Inactive ».
2. **Moyenne sur six mois** : six mois précédant le mois consulté, limités à l'**historique observable** (convention de repli, § 8) ; un mois sans prestation à l'intérieur de cette période compte **0 h** ; période raccourcie **indiquée** (« sur 4 mois ») ; aucune période → « — » avec explication.
3. **Filtre site** : appliqué à **tous** les calculs, comparaisons au mois précédent et à la moyenne comprises.
4. **Navigation** : `Facturation → Heures instrumentistes`. « En savoir plus » ouvre cette page **sur le mois sélectionné** dans la carte.
5. **Total équipe** : heures et coûts uniquement, **aucun pourcentage d'évolution global** (cellules vides).
6. **Forfaits consultation** : valorisation indépendante des heures (D-179 § 6) ; distingués des prestations horaires dans le détail financier ; **pas de cinquième carte** d'indicateur.

Règles complémentaires validées : Top 5 **uniquement** par heures validées décroissantes · encodées = en attente + validées · **aucune heure planifiée, aucune pause** · mois en cours : comparaisons **provisoires à date équivalente** · référence nulle ou indisponible → **« — »**, jamais un pourcentage artificiel · montants = **estimations**, jamais des paiements.

## 2. Règles métier (reprises, jamais réimplémentées)
| Notion | Règle · source unique |
|---|---|
| Périmètre des prestations | Missions de l'instrumentiste dont l'encodage est SOUMIS ou VALIDÉ (VALIDATED, ou CLOSED = LOCKED) — `MyHoursService::STATUSES` + `EncodingStateResolver`. Brouillons, à encoder, rejetées, annulées : jamais. |
| Statut | `EncodingStateResolver` : SUBMITTED → « En attente de validation » ; VALIDATED / LOCKED → « Validé ». |
| Durée | `MissionExecutionService::resolveEffectiveDuration()` : `ACTUAL_TIMES` (fin − début réels, minuit compris), `ACTUAL_EXPLICIT` (durée déclarée seule, début/fin « — »), `PRESENCE_SHARE` (D-156, part imputée d'une présence multi-salles, début/fin « — », étiquette « Multi-salles · part imputée »). `PLANNED` jamais retenue → 0 h, « Heures non renseignées ». |
| Montant | `FinancialCalculationService::evaluate($mission, true)` : tarif **personnel** applicable à la prestation — `HOURLY_RATE` (bloc) ou `CONSULTATION_FEE` (forfait, gardé même sans heures, n'ajoute jamais d'heures). Lecture seule, indicatif. |
| Tarif manquant | Aucune ligne instrumentiste produite → exclue du montant, comptée dans `unratedCount`. Sans heures ≠ sans tarif ; part imputée nulle (D-156) ≠ sans tarif (règles de `MyHoursService::estimate()`). |
| Mois d'appartenance | `startAt` planifié, Europe/Brussels (limite connue de D-179). |
| Évolution vs M-1 | `(validées M − validées M-1) / validées M-1`, arrondi à l'entier **par le serveur** (`round()` PHP, demi-unité loin de zéro : −12,5 → −13 ; ne jamais réarrondir côté client, `Math.round` donnerait −12), même site. M-1 = 0 → « — ». |
| Évolution vs moyenne | Moyenne des heures validées sur la période de référence (§ 8) : prestations du site filtré, période déterminée tous sites. Aucun mois de référence (`NO_REFERENCE`) ou moyenne = 0 (`ZERO_REFERENCE`) → « — » + explication. Arrondi serveur comme ci-dessus. |
| Mois en cours | Jours 1→J du mois courant contre jours 1→J de chaque mois de référence. `provisional: true`, `asOfDay: J`. Pas de mois futur. |
| Classement Top 5 | Heures validées décroissantes, puis nom croissant. Une instrumentiste à 0 h validée n'y figure pas. Jamais par coût. |
| Accès | MANAGER et ADMIN, par Voter dédié. Aucune donnée patient, aucun texte libre (commentaires, interventions, matériel), aucun tarif firme. |

## 3. Contrat API proposé (à inscrire dans `docs/api.md` avant le code)
Types complets : `src/types.ts`. Exemple : `src/fixtures.ts`.

### 3.1 Liste + totaux — page et carte
```
GET /api/manager/instrumentist-hours?month=YYYY-MM[&siteId=][&q=][&sort=validatedMinutes&direction=desc][&limit=5]
```
- `totals` porte sur le périmètre filtré (site + q), jamais sur la page/limite.
- `sites` couvre le mois entier (options du Select), indépendant du filtre.
- Tri serveur : `name`, `encodedMinutes`, `validatedMinutes`, `encodedAmount`, `validatedAmount`, `previousMonthPct`, `personalAveragePct`. Valeurs `null` **toujours en fin de liste**, quel que soit le sens ; égalité → nom croissant.
- Top 5 : `sort=validatedMinutes&direction=desc&limit=5`, sans site ni q.
- `personalAverage.monthsCounted`, `from`, `to` : période réellement utilisée (affichée « sur 4 mois » quand < 6).
- Pas de champ d'évolution dans `totals` (décision 5).

### 3.2 Détail — tiroir
```
GET /api/manager/instrumentists/{id}/hours?month=YYYY-MM[&siteId=]
```
Forme `MyHoursStatement` (D-179, `withEstimate: true`) + `instrumentist`, `comparison`, `provisional`, `asOfDay`. Réutiliser `MyHoursService::statement()` : le tiroir et « Mes heures » de la même instrumentiste doivent afficher les mêmes chiffres.

### 3.3 Sécurité et performance
- Nouveau Voter (ex. `InstrumentistHoursVoter::VIEW`) : MANAGER, ADMIN. INSTRUMENTIST et SURGEON → 403 sur les deux endpoints. Ne pas élargir `MyHoursVoter` (self-scopé par conception).
- Liste : une requête missions sur [M-6 ; M+1[ (même DQL que `MyHoursService`, sans filtre instrumentiste) + une requête instrumentistes (actives + inactives ayant presté) ; regroupement en PHP ; `evaluate()` seulement pour les missions du mois M. Ne jamais boucler `statement()` par instrumentiste.
- Tests de non-régression : Σ lignes = `totals` pour chaque combinaison site/q ; ligne de liste = tiroir = « Mes heures » de la même instrumentiste, à la minute et au centime.

## 4. Composants
| Élément | Implémentation |
|---|---|
| Module | `frontend/src/app/features/instrumentist-hours/` (api, types, composants). |
| `InstrumentistHoursTopCard` | `Paper variant="outlined"`, `borderRadius: 2` comme `RankingPreview`. 1ʳᵉ carte de « Classements », `gridColumn: { md: "span 2" }`. La carte **n'est pas cliquable en entier** (contrairement à `RankingPreview`) : seul « En savoir plus » navigue, pour garder la navigation mensuelle utilisable. |
| Barre | 8 px, fond `--gray-100`, remplissage `primary.main` #42A882, largeur = `ratio`, `aria-hidden`. |
| Évolutions | Neutres : flèche ↗/↘ `--gray-600` + valeur `--gray-800`. Pas de vert/rouge (plus d'heures n'est ni bien ni mal). `aria-label` complet. |
| Navigation mensuelle | ‹ Mois AAAA ›, mois courant par défaut, « suivant » désactivé sur le mois courant, `aria-live="polite"`. 32 px (carte desktop), 40 px (page), 44 px (mobile). |
| Indicateurs | `StatCard` existant ×4. « En attente » en `--amber-800`. |
| Filtres | `Select` site (« Tous les sites » + `sites`), `TextField` recherche (debounce 300 ms, requête serveur), « Réinitialiser les filtres » dès qu'un filtre est actif. État dans l'URL (§ 5). |
| Tableau | `Table` + `TableSortLabel` (tri serveur, `aria-sort`), ligne entière focalisable (Entrée/Espace), `TableFooter` = total serveur, cellules d'évolution vides. Défilement horizontal sous 1 000 px ; liste de cartes sous 900 px (2b). |
| Étiquette « Inactive » | `Chip` neutre (`--gray-75` / `--gray-700`), jamais rouge. |
| Tiroir | `Drawer anchor="right"` 720 px ; plein écran sous 900 px avec flèche retour (3b). |
| Piste, statut, formats | **Réutiliser** `Track`, `TrackHeads`, `StatusLabel` et `format.ts` de `features/my-hours` : aujourd'hui ces composants sont **privés** à `MyHoursView.tsx` → les extraire dans un module partagé **sans changer aucune valeur visuelle** (classes `.mh-tl*`, `.mh-status*`, `.mh-fee-tag`). |
| Clic sur une prestation | `ManagerMissionDrawer` existant (D-168, `features/missions/drawer`). Aucun nouvel écran mission. |

`src/heures-instrumentistes.css` reprend les valeurs des maquettes (tokens Surgery Hub). À l'intégration : MUI (`sx`, thème) pour le shell, la carte, les indicateurs, le tableau et le tiroir ; classes `.mh-*` existantes pour la piste et les statuts. Ne pas importer cette feuille globalement ; ne jamais poser de variables sur `:root` (même règle que D-179 § 5).

## 5. Navigation
- Route : `/app/m/billing/hours` sous `RequireManager` + `DesktopLayout`.
- Sidebar (`DesktopLayout.tsx`) : groupe « Facturation », entrée **« Heures instrumentistes »** juste après « Suivi des encodages » (icône `ScheduleOutlined`).
- URL : `?month=2026-10&siteId=3&q=mar&sort=validatedMinutes:desc&instrumentist=41`. `instrumentist` ouvre le tiroir ; Retour navigateur le ferme ; le focus revient à la ligne d'origine.
- « En savoir plus » → `/app/m/billing/hours?month=<mois de la carte>`.

## 6. Formats
- Durées `104h00`, `2h30`, `0h00` ; horaires `07h30` ; `+1j` en exposant ; « — » pour début/fin inconnus.
- Montants `≈ 3 304,00 €` pour toute estimation ; forfait `120,00 €` + étiquette « Forfait consultation ». Libellés « Coût estimé » / « Estimation indicative », mention « ni facture, ni paiement ».
- Évolutions `+8 %`, `−9 %` (signe U+2212), `0 %` sans flèche, « — » si indisponible (raison en infobulle et `aria-label`).
- Références : « vs sept. », « vs moy. 6 m », « sur 4 mois » (période raccourcie), « vs 1–3 oct. » (provisoire).

## 7. États (maquettes 1c, 2c)
| État | Rendu |
|---|---|
| Chargement | Squelettes ; au changement de filtre, anciennes valeurs atténuées (opacité 0,6) jusqu'à la réponse, jamais un mélange. |
| Mois sans prestation | Indicateurs 0h00 grisés, « Aucune prestation encodée en août 2026 », bouton « Revenir au mois en cours » (absent sur le mois courant). Les instrumentistes actives restent listées à 0h00 (décision 1). |
| Filtre sans résultat | « Aucun instrumentiste ne correspond à « dup » sur CHU Brugmann » + « Réinitialiser les filtres ». |
| Tarif manquant | « Tarif manquant » ambre (`--amber-800`) à la place du montant ; partiel : montant + « + n sans tarif ». Le classement reste sur les heures. |
| Comparaison indisponible | « — » + raison. |
| Période raccourcie | Valeur + « sur N mois ». |
| Provisoire | Puce « Provisoire · au J mois » (carte), bandeau ambre (page), colonnes « vs 1–J mois ». |
| Erreur | Encart rouge + « Réessayer ». 403 → `ForbiddenPage` existante. |

## 8. Convention de repli — historique observable (option B, validée le 2026-11-03)
Le modèle n'a **aucune date d'entrée en activité fiable** (constat sur `origin/main` c154f18 : `User` sans `createdAt` ni date d'activation, `active` booléen sans historique ; audit `USER_CREATED` seulement depuis le 2026-06-16 (84d6ed8) et pour les seules créations via l'interface ; `instrumentistOnboardingCompletedAt` nul pour les comptes anciens). **Aucune migration** : la moyenne personnelle repose sur la convention suivante.

1. **Début de l'historique observable** (`observableSince`, `YYYY-MM`) = mois d'appartenance (D-179 : `startAt` planifié, Europe/Brussels) de la **première prestation connue** de l'instrumentiste dans le périmètre D-179 (encodage soumis, validé ou clôturé selon `EncodingStateResolver` ; brouillons, à encoder, rejetées, annulées exclus), **tous sites confondus**, parmi les mois antérieurs au mois consulté.
2. Ce n'est **jamais** une date d'embauche ou d'activation. Libellés autorisés : « historique depuis oct. 2026 », « première prestation connue : juin 2026 ». Interdits : « entrée », « embauche », « activation », « ancienneté ».
3. **Période de référence** = les 6 mois civils M-6 … M-1, **restreints** aux mois ≥ `observableSince`. Le mois consulté M n'en fait **jamais** partie.
4. Tout mois de cette période sans prestation compte **0 h** (même si l'instrumentiste était inactive ce mois-là : le modèle ne permet pas de le savoir).
5. Moyenne = Σ heures validées de la période / nombre de mois de la période (`monthsCounted`). `monthsCounted < 6` → « sur N mois ».
6. Aucune prestation connue avant M → `monthsCounted = 0`, `reason = NO_REFERENCE` : « — » + « Pas d'historique avant oct. 2026 » (ou « Aucune prestation antérieure connue »). Moyenne nulle sur une période non vide → `ZERO_REFERENCE` : « — » + « Aucune heure validée sur la période de référence ».
7. **Mois en cours** : même période, chaque mois de référence limité aux jours 1→J ; libellé « Provisoire ».
8. **Filtre site** : les heures comparées (M, M-1, période) sont celles du site filtré ; `observableSince`, donc la période, reste calculé **tous sites** (filtrer ne change pas la période).
9. Comparaison vs M-1 : M-1 < `observableSince` → `NO_REFERENCE` (« — », « Pas d'historique en septembre ») ; sinon M-1 = 0 → `ZERO_REFERENCE`.

**Limite, à reprendre dans l'aide de la page et dans la décision d'intégration** : une première prestation connue ne prouve pas la date réelle d'entrée en activité. Une instrumentiste active avant SurgicalHub, ou dont les premières prestations n'ont pas été encodées, a un historique plus court que la réalité ; une période d'inactivité à l'intérieur de l'historique observable abaisse sa moyenne (mois à 0 h).

## 9. Écarts entre les maquettes et l'existant
1. **Manager sur mobile** : `DesktopLayout` utilise un `Drawer variant="permanent"` sans adaptation mobile ; il n'existe pas de shell manager mobile. Les artboards 1b, 2b, 3b décrivent le **contenu** responsive ; la barre supérieure à hamburger y est une reconstitution. Un shell manager mobile est un chantier distinct (déjà relevé comme anomalie D-165/D-166).
2. **Carte non cliquable en entier**, contrairement à `RankingPreview` (voulu, § 4).
3. **Composants privés de `MyHoursView.tsx`** (`Track`, `TrackHeads`, `StatusLabel`) à extraire avant réutilisation.
4. **D-156 postérieur à D-179** : la source `PRESENCE_SHARE` (« Multi-salles · part imputée ») apparaît dans le tiroir (maquette 3a, 20 oct.).
5. **Sidebar** : les maquettes reconstituent la sidebar ; l'entrée réelle se branche dans `DesktopLayout.tsx` (`Facturation`).
6. **Rayon des cartes** : `borderRadius: 2` = 24 px avec `shape.borderRadius: 12` (MUI 7), repris dans les maquettes.
7. **Recherche** : n'existe pas dans `MyHoursService` ; nouveau paramètre serveur `q` (nom/prénom), appliqué aux lignes et aux totaux.
8. **Détail des tarifs** : `MyHoursService::estimate()` regroupe les lignes par site × type × tarif ; le tiroir les affiche telles quelles (une ligne par site, forfaits consultation distingués), sans regroupement côté client.
9. **Aucun endpoint manager actuel** n'expose ces données ; `BillingVoter::MANAGE` et `MyHoursVoter` ne couvrent pas ce besoin → Voter dédié (§ 3.3).

## 10. Checklist de vérification (données `src/fixtures.ts`, octobre 2026)
- [ ] Tous sites : **10 lignes** (dont Laura Gillet à 0h00 et Paul Renard « Inactive »), 603h15 encodées, 538h00 validées, 65h15 en attente, 89 %, ≈ 20 975,00 € validé, ≈ 23 183,50 € encodé, 78 prestations, 9 en attente, 1 instrumentiste sans tarif.
- [ ] Ligne Total : heures et coûts, cellules d'évolution **vides**.
- [ ] Top 5 : Dubois 104h00 · Leroy 92h45 · Martin 81h30 · Peeters 76h00 · Benali 68h00 ; Janssens (52h30, sans tarif) absent.
- [ ] CHU Brugmann + « mar » : 2 lignes, 54h30 / 40h00 / 14h30 / 73 %, ≈ 1 100,00 € validé ; évolutions calculées sur Brugmann seul ; Σ lignes = total.
- [ ] Paul Renard : moyenne « sur 4 mois » (−42 %) ; Elise Wouters : « — » + « Pas d'historique avant oct. 2026 » (`NO_REFERENCE`, jamais présenté comme une date d'entrée) ; Hugo Lambert : −100 % (référence non nulle) ; Laura Gillet : « — » vs sept. (référence nulle).
- [ ] Tiroir Benali : 70h30 / 68h00 / 2h30 ; bloc 66h30 × 46,00 €/h ≈ 3 059,00 € + 3 forfaits × 120,00 € = ≈ 3 419,00 € encodé, ≈ 3 304,00 € validé ; Σ des 12 lignes = 3 419,00 €.
- [ ] 16 oct. `18h00 | 9h00 | 03h00 +1j` ; 20 oct. `— | 8h15 | —` + « Multi-salles · part imputée » ; 21 oct. « Heures non renseignées » + forfait 120,00 €, 0 h comptée.
- [ ] Mois en cours : puce provisoire, « suivant » désactivé, colonnes « vs 1–J ».
- [ ] « En savoir plus » ouvre la page sur le mois de la carte.
- [ ] Aucune heure planifiée, aucune pause, aucune donnée patient ; INSTRUMENTIST / SURGEON → 403.
- [ ] Contraste AA (≥ 4,5:1) : textes secondaires ≥ `--gray-600`, ambre texte `--amber-800`, vert texte ≥ `--green-700`.

## 11. Historique des révisions
### Révision 3 (2026-11-03) — figée
Neuf planches et corrections validées. Point bloquant levé par l'option B : convention d'historique observable (§ 8), sans migration ; `observableSince` et `reason` ajoutés au contrat (`src/types.ts`) ; fixtures alignées (Elise Wouters : `NO_REFERENCE`, historique depuis oct. 2026 ; Paul Renard : depuis juin 2026, « sur 4 mois »). D-156 confirmé : durées et parts imputées du moteur existant, aucun double comptage, aucun calcul financier indépendant.
### Révision 2 (2026-11-03) — décisions validées
Vérification navigateur du canevas (Chrome, vues Fill 1225 px et Fit, 9 artboards, console sans erreur) ; corrigé : bloc des tarifs du tiroir écrasé à hauteur nulle (enfant flex dans un conteneur `overflow: hidden` → corps du tiroir défilant, `flex-shrink: 0`), tuiles du récapitulatif trop serrées (grille 2×2, tiroir 720 px), noms de chirurgiens tronqués, en-têtes du Top 5 sur deux lignes, montant « € » renvoyé à la ligne dans l'indicateur, colonne chevron du tableau hors cadre (supprimée), carte « Top firmes » étirée, évolutions mobiles séparées de leur libellé, états 1c/2c coupés. Donnée ambiguë à l'arrondi (−12,5 %) remplacée ; règle d'arrondi serveur précisée (§ 2). Détail des tarifs aligné sur le regroupement par site de D-179. Badge « Nouveau » de la sidebar retiré (non demandé).
Décisions 1–6 et règles complémentaires intégrées. Maquettes : lignes à 0h00 et inactives, total sans évolution, période raccourcie « sur 4 mois », cas `PRESENCE_SHARE` (D-156). Ajout du point bloquant § 8 et des écarts § 9. Dépôt de ce dossier.
### Révision 1 (2026-11-03) — première conception
Carte Top 5, page complète, tiroir, états, contrat API proposé, six questions ouvertes.
