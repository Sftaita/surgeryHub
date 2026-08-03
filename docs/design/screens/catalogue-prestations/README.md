# Écran — Catalogue > Prestations (refonte UX fonctionnelle)

## Informations générales
- **Nom** : Catalogue > Prestations · **Route actuelle** : `/app/m/catalogue/prestations` · **Composant React réel** : `PrestationsPage.tsx`
- **Layout** : AppLayout manager, sidebar desktop · **Version** : proposition v3 (refonte fonctionnelle — remplace l'organisation à plat actuelle "Prestations / Matériel" au sein d'une firme, sans toucher au modèle métier réel)
- **Date** : 2026-08-03 · **Statut** : **implémenté** dans l'app réelle (`PrestationsPage.tsx`) le 2026-08-03, fidèle à cette proposition — **pixel non designé** (voir "Ce qui n'est pas couvert" en fin de document, toujours d'actualité pour la finition visuelle)

## Objectif
Faire comprendre, en un coup d'œil et sans ambiguïté possible, que `/catalogue/prestations` couvre **deux univers de nature différente** :
1. **Configuration par firme** — ce que Smith & Nephew, Zimmer Biomet, Medacta… facturent et comment (`FirmServiceOffering`, `MaterialItem`).
2. **Référentiel global des interventions** — la bibliothèque clinique commune à tout SurgicalHub (`InterventionType`), qui n'appartient à aucune firme.

Aujourd'hui, l'écran réel (`PrestationsPage.tsx`) place « Prestations » et « Matériel » comme deux onglets d'une firme sélectionnée — correct — mais le référentiel des types d'intervention n'est accessible que via un bouton secondaire du header (« Gérer les types d'intervention ») qui ouvre un dialogue générique (`InterventionTypesManager` dans `InterventionTypesDialog`), sans jamais signaler qu'on quitte le contexte de la firme actuellement sélectionnée. C'est précisément l'ambiguïté que cette refonte corrige.

## Capture officielle
**Pas de capture statique — écran non encore designé pixel.** La référence est le prototype interactif fourni par l'utilisateur : `react-catalogue-prestations/` (composants React/TypeScript isolés, un fichier = une responsabilité, aucune dépendance tierce). Ouvrir `CataloguePrestationsPage.tsx` en premier pour parcourir le flux complet. Même statut que `ajout-materiel/` dans l'index de ce dossier : « flux interactif — référence = prototype, pas de capture statique ».

Le prototype a été relu et corrigé le 2026-08-03 sur deux manques identifiés face au modèle métier réel (voir « Politique délégué » et « Tarifs HTVA » ci-dessous) — ces corrections sont déjà appliquées dans les fichiers du prototype, pas seulement documentées ici.

## Modèle mental — le cœur du chantier
```
InterventionType (référentiel global, indépendant des firmes)
        │
        │  FirmServiceOffering = Firme × InterventionType
        │  (configuration commerciale propre à CETTE firme)
        │
        ├── Smith & Nephew  → forfait, politique délégué, matériel suggéré
        └── Zimmer Biomet   → forfait, politique délégué, matériel suggéré
                (différents, sur la MÊME intervention clinique)
```
Un manager ne doit jamais lire ni écrire « PTG Smith » : le libellé de l'intervention reste global (`InterventionType.name`), une prestation ne fait qu'associer une firme à ce libellé, jamais le dupliquer ni le préfixer.

## Terminologie exacte (à respecter dans toute l'UI)
| Terme | Sens | À ne pas confondre avec |
|---|---|---|
| **Firme** | Fabricant/fournisseur (Smith & Nephew, Zimmer…) | — |
| **Intervention** | Acte clinique global (ex. *Prothèse totale de genou*) | « Prestation » |
| **Référentiel des interventions** | Bibliothèque globale de toutes les interventions, commune à toutes les firmes | Le catalogue d'une firme |
| **Prestation** | Association Firme × Intervention (`FirmServiceOffering`) — porte le forfait, le matériel suggéré, la politique délégué | L'intervention elle-même |
| **Matériel** | Produit/implant rattaché à une firme (`MaterialItem`) | Le matériel *suggéré* d'une prestation (une simple liste de raccourcis, jamais une contrainte) |
| **Forfait** | Tarif `INTERVENTION_FEE` d'une prestation, toujours HTVA | Le tarif d'un matériel |

## Architecture de navigation
Deux destinations de **poids visuel différent**, jamais un troisième onglet à plat (`TopNav.tsx`) :

```
┌─────────────────────────────────────────────┐
│  🏢 Firmes                    📖 Référentiel  │  ← TopNav : 2 destinations,
│  Configuration commerciale    Bibliothèque    │     pas 3 onglets égaux
│  par firme                    clinique globale │
└─────────────────────────────────────────────┘
```

Sous le `TopNav`, un **bandeau de contexte permanent** (`ContextBanner.tsx`) rappelle en toutes circonstances où on se trouve :
- Vue Firmes, firme sélectionnée : `CONFIGURATION FIRME · [logo] Smith & Nephew`
- Vue Référentiel : `RÉFÉRENTIEL GLOBAL · Commun à toutes les firmes — aucune configuration commerciale ici.`

Le logo/nom de la firme sélectionnée **disparaît entièrement** en vue Référentiel — c'est le signal le plus fort de sortie de contexte (§13/§25 du brief).

## Composition (composants du prototype ↔ rôle)
`TopNav` · `ContextBanner` · `FirmSidebar` (recherche + liste avatars) · `FirmHeader` (avatar 52px + nom + « Gérer le logo ») · `LogoModal` · `Tabs` (Prestations | Matériel, scopées à la firme) · `PrestationsTab` + `PrestationCard` · `PrestationConfigModal` · `AddPrestationSheet` · `NewInterventionModal` (double usage : création directe depuis le référentiel, ou inline depuis `AddPrestationSheet`) · `MaterialsTab` + `MaterialRow` · `MaterialModal` · `ReferentielTable` · `ReferentielDetail` · `EditInterventionModal` · `MergeModal` · `FirmAvatar` (implémentation unique du logo/fallback-initiales, réutilisée partout).

---

## Écrans et flux

Pour chaque écran : **Objectif · Contexte (FIRME/GLOBAL) · Informations visibles · Actions · Entrée · Sortie · Erreurs.**

### 1. Écran d'entrée — sélecteur de firmes (`FirmSidebar`)
- **Objectif** : choisir la firme à configurer, sans jamais dépendre uniquement du nom pour l'identifier.
- **Contexte** : FIRME (aucune firme encore sélectionnée = état neutre, mais on est déjà dans la destination « Firmes »).
- **Informations visibles** : recherche texte libre · pour chaque firme : logo/avatar (fallback initiales), nom, nombre de prestations actives.
- **Actions** : sélectionner une firme · rechercher · « + Nouvelle firme ».
- **Entrée** : arrivée sur `/catalogue/prestations`, destination « Firmes » active par défaut.
- **Sortie** : sélection d'une firme → écran 4 (espace firme).
- **Erreurs** : aucune firme ne correspond à la recherche → « Aucune firme trouvée. » (pas de CTA de création depuis la recherche — la création de firme est un flux séparé, hors périmètre de ce chantier).

### 2. Bascule Firmes / Référentiel (`TopNav`, transversal)
- **Objectif** : rendre la distinction FIRME/GLOBAL la première chose que l'utilisateur voit et manipule, avant même le contenu.
- **Contexte** : transversal — la bascule elle-même n'appartient à aucun des deux contextes.
- **Informations visibles** : deux destinations avec titre + sous-titre explicatif (« Configuration commerciale par firme » / « Bibliothèque clinique globale, commune à toutes les firmes »), poids visuel volontairement différent d'un système d'onglets classique.
- **Actions** : basculer de destination.
- **Entrée** : visible en permanence en haut de l'écran, quel que soit l'état.
- **Sortie** : change la destination affichée ; la firme précédemment sélectionnée reste mémorisée pour un retour rapide (pas de perte de contexte).
- **Erreurs** : aucune.

### 3. Espace d'une firme — vue d'ensemble (`FirmHeader` + `Tabs`)
- **Objectif** : ancrer visuellement « je configure cette firme, et seulement elle » avant d'afficher Prestations/Matériel.
- **Contexte** : FIRME.
- **Informations visibles** : avatar (52px) + nom de la firme · lien « Gérer le logo » · sous-navigation `Prestations | Matériel`.
- **Actions** : ouvrir la gestion du logo · basculer entre les deux sous-onglets · revenir au sélecteur de firmes.
- **Entrée** : depuis le sélecteur de firmes (écran 1), ou depuis une navigation Référentiel → firme (écran 14).
- **Sortie** : vers l'onglet Prestations (écran 4) ou Matériel (écran 9).
- **Erreurs** : aucune.

### 4. Onglet Prestations (`PrestationsTab` + `PrestationCard`)
- **Objectif** répond exactement à : *Quelles interventions cette firme prend-elle en charge et selon quelles règles commerciales ?*
- **Contexte** : FIRME.
- **Informations visibles**, par prestation : libellé + code de l'intervention (jamais un nom composite du type « PTG Smith ») · statut actif/inactif · **forfait HTVA** (ou « Tarif à définir » si `feeApplicable=true` sans règle, ou « Pas de forfait » si `feeApplicable=false` — jamais confondus) · badge **Délégué** si `representativePresenceRelevant=true` (tooltip résumant l'effet : neutralise le forfait / le matériel / aucun effet) · matériels suggérés (chips) · lien « Voir dans le référentiel → ».
- **Actions** : « Ajouter une prestation » (CTA principal) · icône Modifier par ligne · lien vers le référentiel.
- **Entrée** : depuis l'espace firme (écran 3).
- **Sortie** : Modifier → écran 5. Ajouter → écran 6. Voir dans le référentiel → écran 14 (détail global).
- **Erreurs / état vide** : « Aucune prestation renseignée pour cette firme. » + CTA « Ajouter une prestation ».

### 5. Modifier / Nouvelle prestation (`PrestationConfigModal`)
- **Objectif** : configurer tout ce qui est propre à CETTE firme pour une intervention déjà choisie — jamais l'identité de l'intervention elle-même.
- **Contexte** : FIRME (l'intervention est affichée en lecture seule : code + libellé, avec un lien de sortie « Voir dans le référentiel → », jamais un champ éditable ici).
- **Informations visibles** : intervention concernée (lecture seule) · **Facturation** : bascule « Forfait facturable pour cette prestation » — si activée, champ de saisie du forfait (HTVA) ; si désactivée, note explicative (« Aucun forfait ne sera facturé… décision volontaire, distincte d'un tarif manquant ») · **Matériels suggérés** : chips ajoutables/retirables · **Présence d'un délégué** *(ajouté lors de la revue — absent du prototype d'origine)* : bascule « La présence d'un délégué a un effet sur cette prestation », et si activée, deux sous-options « Neutralise le forfait de cette prestation » / « Neutralise le matériel facturable de cette firme » — copie et sémantique strictement identiques à `OfferingDetailDialog` de l'app réelle, voir §Politique délégué ci-dessous · statut actif/inactif.
- **Actions** : Enregistrer · Voir dans le référentiel (sortie latérale, sans perdre le formulaire en cours) · Annuler/fermer.
- **Entrée** : depuis « Modifier » (écran 4) avec une prestation existante, ou depuis la fin du flux « Ajouter une prestation » (écran 6/7) avec une prestation vide.
- **Sortie** : Enregistrer → retour à l'onglet Prestations, prestation mise à jour.
- **Erreurs** : aucune validation bloquante autre que la cohérence forfait/feeApplicable (un forfait saisi n'est retenu que si `feeApplicable=true`).

### 6. Ajouter une prestation — « Choisir dans le référentiel » (`AddPrestationSheet`)
- **Objectif** : rattacher une intervention **déjà existante ou à créer** du référentiel à la firme courante — jamais une recherche qui navigue hors du contexte firme.
- **Contexte** : FIRME (recherche **contextuelle** dans le référentiel — une sheet, pas une navigation vers l'écran Référentiel global, pour ne jamais faire perdre le fil de la tâche).
- **Informations visibles** : champ de recherche · résultats (code + libellé) filtrés en direct.
- **Actions** : sélectionner un résultat → Ajouter · si déjà configurée pour cette firme → « Ouvrir » (voir écran 8) · en bas, toujours visible : « + Ajouter « {recherche} » au référentiel ».
- **Entrée** : CTA « Ajouter une prestation » (écran 4).
- **Sortie** : sélection d'une intervention non encore configurée → ouvre `PrestationConfigModal` vide (écran 5), firme conservée. Création d'une nouvelle intervention → écran 7.
- **Erreurs / état vide** : « Aucune intervention correspondante. » (le CTA « + Ajouter au référentiel » reste toujours disponible en dessous du champ de recherche, pas seulement dans cet état vide).

### 7. Intervention absente du référentiel → création inline (`NewInterventionModal`, mode `returnTo: addPresta`)
- **Objectif** : permettre de créer l'intervention globale manquante **sans quitter** le flux de création de prestation pour la firme en cours.
- **Contexte** : **bascule explicite vers GLOBAL le temps de ce sous-écran**, même si déclenché depuis un contexte firme — le texte le dit noir sur blanc.
- **Informations visibles** : notice permanente « Cette intervention sera ajoutée au **référentiel global** et pourra ensuite être utilisée par toutes les firmes. » · Code * · Libellé * (pré-rempli avec le texte recherché) · statut actif.
- **Actions** : Ajouter au référentiel · si un doublon potentiel est détecté (voir §Détection des doublons) : « Utiliser cette intervention » / « Créer quand même ».
- **Entrée** : depuis « + Ajouter au référentiel » (écran 6) ou depuis l'écran Référentiel (écran 16, entrée directe).
- **Sortie** : création réussie → **retour automatique** au flux d'origine avec la firme conservée et la nouvelle intervention pré-sélectionnée (ouvre directement `PrestationConfigModal` vide, écran 5) — le manager ne recommence jamais le parcours depuis le début.
- **Erreurs** : code/libellé manquant → « Indiquez un code et un libellé. » · code déjà présent dans le référentiel → « Ce code existe déjà dans le référentiel. » · doublon détecté → voir §Détection des doublons (bloque uniquement la validation immédiate, jamais la possibilité de créer quand même).

### 8. Prestation déjà existante (cas particulier de l'écran 6)
- **Objectif** : empêcher tout doublon `Firme × Intervention` (contrainte réelle `UNIQUE(firm_id, intervention_type_id)`).
- **Contexte** : FIRME.
- **Informations visibles** : « Déjà configurée » à côté du résultat de recherche correspondant.
- **Actions** : « Ouvrir » → ouvre directement `PrestationConfigModal` pré-rempli (écran 5) sur la prestation existante.
- **Entrée** : sélection d'une intervention pour laquelle la firme a déjà une prestation active.
- **Sortie** : écran 5, mode édition.
- **Erreurs** : aucune — c'est déjà la résolution de l'erreur potentielle (pas de doublon créé).

### 9. Onglet Matériel (`MaterialsTab` + `MaterialRow`)
- **Objectif** : *Quel matériel cette firme propose-t-elle et à quel tarif ?*
- **Contexte** : FIRME — regroupé au même niveau que Prestations (même firme, même sous-navigation), jamais un espace séparé.
- **Informations visibles** : tous les matériels de la firme (pas seulement ceux ayant déjà un tarif) · référence · **tarif HTVA actuel** ou « Tarif à définir » (jamais « Non facturable » déduit de l'absence de tarif) · recherche.
- **Actions** : « Nouveau matériel » · une **seule** action « Modifier » par ligne (plus de `Définir un tarif`/`Ajouter un tarif`/`Non facturable` concurrents).
- **Entrée** : depuis l'espace firme (écran 3).
- **Sortie** : Modifier/Nouveau → écran 10.
- **Erreurs / états vides** : aucun matériel du tout → « Aucun matériel renseigné pour cette firme. » + CTA « Nouveau matériel ». Recherche sans résultat → « Aucun matériel trouvé. ».

### 10. Modifier / Nouveau matériel (`MaterialModal`)
- **Objectif** : un point d'entrée unique pour l'identification ET la tarification d'un matériel.
- **Contexte** : FIRME.
- **Informations visibles** : nom * · référence · tarif (€ HTVA, optionnel — absence = « Tarif à définir », jamais recalculé en « non facturable »).
- **Actions** : Enregistrer.
- **Entrée** : écran 9.
- **Sortie** : retour à l'onglet Matériel, ligne mise à jour.
- **Erreurs** : nom manquant → « Indiquez au moins le nom du matériel. ».

### 11. Gestion du logo de firme (`LogoModal`, depuis `FirmHeader` ou le sélecteur)
- **Objectif** : ajouter/remplacer/supprimer/prévisualiser le logo — propriété exclusive de `Firm`, jamais dupliquée par écran.
- **Contexte** : FIRME (le logo reste une propriété de la firme, indépendamment de la destination active — voir §12 réutilisation).
- **Informations visibles** : aperçu actuel (grand format, 120px) ou fallback initiales · rappel explicite : « réutilisé partout où [la firme] apparaît (sélecteur, référentiel, factures…), jamais dupliqué par écran. »
- **Actions** : Ajouter (si aucun logo) / Remplacer (si logo existant) · Supprimer (si logo existant).
- **Entrée** : lien « Gérer le logo » (`FirmHeader`, écran 3) ou action équivalente depuis un futur espace d'administration des firmes.
- **Sortie** : mise à jour immédiate de l'avatar partout où la firme est affichée (sidebar, cartes prestations, détail référentiel…).
- **Erreurs** : à définir avec le vrai endpoint d'upload (formats acceptés, taille max) — non spécifié par le prototype (`FileReader`/data URL, à remplacer par un vrai stockage CDN).

### 12. Réutilisation du logo (transversal, pas un écran)
Le logo (`FirmAvatar`) doit être la **seule implémentation** du rendu logo/fallback-initiales, utilisée sans exception dans : le sélecteur de firmes (écran 1), le bandeau de contexte (écran 2/3), les lignes du référentiel et son détail (écrans 16/17), et tout futur écran de facturation firme. Ne jamais réimplémenter le fallback initiales ailleurs.

### 13. Écran Référentiel — liste (`ReferentielTable`)
- **Objectif** répond à : *Quelles interventions cliniques existent dans SurgicalHub ?*
- **Contexte** : **GLOBAL** — aucun logo ni nom de firme n'apparaît en tant qu'élément principal (seule une pile d'avatars miniatures indique le nombre de firmes utilisatrices, sans droit d'édition depuis ici).
- **Informations visibles** : code · libellé · nombre de firmes utilisatrices (avatars empilés + compteur) · nombre de missions · statut actif/inactif · recherche.
- **Actions** : « Nouvelle intervention » · Modifier (par ligne) · ouvrir le détail (clic sur la ligne).
- **Entrée** : bascule `TopNav` → Référentiel.
- **Sortie** : clic sur une ligne → écran 14 (détail). Modifier → écran 17. Nouvelle intervention → écran 7 (mode direct, `returnTo` absent).
- **Erreurs / états vides** : référentiel totalement vide → « Aucune intervention dans le référentiel. » + CTA « Créer la première intervention ». Recherche sans résultat → « Aucune intervention correspondante. » + CTA « + Ajouter au référentiel ».

### 14. Détail d'une intervention globale (`ReferentielDetail`)
- **Objectif** : voir l'identité clinique d'une intervention et toutes les firmes qui l'utilisent, avec un accès direct à chacune.
- **Contexte** : GLOBAL.
- **Informations visibles** : code + statut · libellé · « Utilisée par N firme(s) » — pour chacune : logo, nom, forfait HTVA de cette firme (ou « Pas de forfait »/« Tarif à définir »), statut actif/inactif de la prestation.
- **Actions** : Modifier (identité globale, écran 17) · Fusionner… (écran 18) · par firme : « Ouvrir chez cette firme → ».
- **Entrée** : clic sur une ligne du référentiel (écran 13), ou lien « Voir dans le référentiel » depuis une prestation firme (écran 4/5).
- **Sortie** : « Ouvrir chez cette firme » → bascule automatiquement vers la destination Firmes, sélectionne cette firme, et ouvre directement sa prestation correspondante (`PrestationConfigModal`, écran 5) — navigation Référentiel → Firme complète en un clic (§16 du brief).
- **Erreurs / état vide** : aucune firme ne configure encore cette intervention → « Aucune firme ne configure encore cette intervention. » (pas un état d'erreur — une intervention nouvellement créée est légitimement dans cet état).

### 15. Navigation inverse — firme → référentiel (transversal, déjà couvert par les écrans 4/5)
Depuis une prestation firme, le lien « Voir dans le référentiel → » (visible sur `PrestationCard` et dans `PrestationConfigModal`) bascule vers l'écran 14, jamais vers un mode d'édition de l'identité globale — `PrestationConfigModal` n'expose de toute façon aucun champ permettant de modifier `code`/`name` de l'intervention (lecture seule stricte), donc aucune modification accidentelle n'est possible par construction.

### 16. Créer une intervention globale — entrée directe (`NewInterventionModal`, sans `returnTo`)
- **Objectif** : identique à l'écran 7, mais déclenché directement depuis le Référentiel (pas de retour vers une firme à la fin).
- **Contexte** : GLOBAL.
- **Informations visibles/Actions/Erreurs** : identiques à l'écran 7.
- **Entrée** : « Nouvelle intervention » (écran 13) ou « Créer la première intervention »/« + Ajouter au référentiel » (états vides de l'écran 13).
- **Sortie** : création réussie → retour à la liste du référentiel, nouvelle ligne visible.

### 17. Modifier une intervention globale (`EditInterventionModal`)
- **Objectif** : ne jamais exposer, depuis cet écran, un champ qui n'appartient pas au référentiel.
- **Contexte** : GLOBAL.
- **Informations visibles** : code (lecture seule, immuable) · libellé * · statut actif.
- **Actions** : Enregistrer.
- **Entrée** : « Modifier » (écran 13 ou 14).
- **Sortie** : retour à l'écran d'origine, libellé/statut mis à jour partout où l'intervention est référencée (toutes les prestations des firmes en dépendent par lien, jamais par copie).
- **Erreurs** : libellé vide → non soumis (bouton inactif tant que le libellé est vide).

### 18. Fusion de deux interventions (`MergeModal`)
- **Objectif** : nettoyer un doublon historique du référentiel — **jamais accessible ailleurs que depuis le détail d'une intervention globale**.
- **Contexte** : GLOBAL strict. Ne doit jamais apparaître dans une prestation firme ni dans le matériel.
- **Informations visibles** : intervention conservée (fixe) · sélecteur de l'intervention absorbée · une fois choisie : nombre total de firmes concernées, nombre total de missions liées · si des firmes ont une prestation active sur les deux interventions → liste des conflits, chacun avec un choix explicite (garder le forfait de la conservée ou de l'absorbée).
- **Actions** : sélectionner l'intervention absorbée · résoudre chaque conflit · Confirmer la fusion (désactivé tant qu'un conflit n'a pas de résolution explicite).
- **Entrée** : « Fusionner… » (écran 14).
- **Sortie** : confirmation → l'intervention absorbée disparaît du référentiel actif, ses prestations/missions sont rattachées à l'intervention conservée.
- **Erreurs** : le backend réel (`InterventionTypeMergeService`, Task 11) peut refuser la fusion avec un **409** si une firme a une prestation sur les deux types sans résolution — le prototype anticipe déjà ce cas en bloquant la confirmation tant que `resolutions` n'est pas complet pour chaque firme en conflit ; en production, afficher le message d'erreur structuré du backend (liste des firmes en conflit) si un conflit apparaît entre le chargement de l'écran et la confirmation (concurrence).

---

## Politique délégué — corrigée lors de la revue (2026-08-03)

**Constat** : le prototype d'origine ne configurait, par prestation, que `forfait`/`active`/`suggestedMaterials`. La politique commerciale « présence d'un délégué » (D-092, `docs/decisions.md`) — pourtant **déjà implémentée et en production** dans l'écran réel (`OfferingDetailDialog`, `PrestationsPage.tsx`) — était absente de cette proposition. Corrigé directement dans le prototype (`PrestationConfigModal.tsx`, `types.ts`, `mockData.ts`), avec la **même copie et la même sémantique** que l'écran réel — voir écran 5 ci-dessus pour le détail. Rappel du modèle métier exact (`FirmServiceOffering`, quatre indicateurs, jamais lus par le moteur de résolution tarifaire lui-même, seulement par `FinancialCalculationService` en aval) :
- `feeApplicable` : un forfait est-il seulement attendu pour cette prestation ?
- `representativePresenceRelevant` : la présence d'un délégué a-t-elle un effet sur la facturation de *cette* prestation ?
- `representativeSuppressesInterventionFee` / `representativeSuppressesOwnMaterialFees` : si oui, lequel (l'un, l'autre, ou les deux) ?

La présence *effective* du délégué à une mission donnée reste une donnée factuelle encodée par l'instrumentiste (`MissionIntervention.representativePresent`) — **hors périmètre de cet écran**, qui ne configure que la règle, jamais le fait.

## Tarifs HTVA — corrigé lors de la revue (2026-08-03)
Tous les montants affichés dans ce module (forfaits de prestation, tarifs matériel) sont des tarifs commerciaux B2B, jamais un prix consommateur. `formatCurrency()` suffixe désormais systématiquement « € HTVA » ; libellés de champ alignés (« Forfait…(€ HTVA) », « Tarif (€ HTVA) »). Distinct de « Pas de forfait » (`feeApplicable=false`, nouveau helper `formatForfait()`) et de « Tarif à définir » (montant simplement pas encore défini) — trois états jamais confondus entre eux.

## Détection des doublons (référentiel)
Réutilise la détection de similarité déjà livrée côté backend (Task 11 — `InterventionTypeSimilarityService`, endpoint `GET /api/intervention-types/similar`). Le prototype simule ceci avec `findSimilarIntervention()` (normalisation + sous-chaîne) — **à remplacer en production par un appel réel à cet endpoint**, jamais réimplémenté côté frontend. Ne bloque jamais la création : toujours un choix explicite entre « Utiliser cette intervention » et « Créer quand même ».

## États vides — récapitulatif
| Contexte | Message | Action |
|---|---|---|
| Firme sans prestation | Aucune prestation renseignée pour cette firme. | Ajouter une prestation |
| Firme sans matériel | Aucun matériel renseigné pour cette firme. | Nouveau matériel |
| Référentiel vide | Aucune intervention dans le référentiel. | Créer la première intervention |
| Recherche référentiel sans résultat | Aucune intervention correspondante. | + Ajouter au référentiel |
| Recherche matériel/prestation sans résultat (dans une firme) | Aucun matériel trouvé. / (liste de résultats simplement vide) | — |
| Aucune firme trouvée (sélecteur) | Aucune firme trouvée. | — |
| Intervention sans firme utilisatrice | Aucune firme ne configure encore cette intervention. | — (pas un état d'erreur) |

## Erreurs importantes (transversal)
- **Doublon `Firme × Intervention`** : jamais un doublon créé — toujours redirigé vers « Ouvrir » la prestation existante (écran 8).
- **Doublon référentiel** (code exact) : « Ce code existe déjà dans le référentiel. », bloquant.
- **Doublon référentiel** (similarité) : jamais bloquant, toujours un choix explicite (écran 7/16).
- **Conflit de fusion** (une firme a une prestation active sur les deux interventions) : bloque uniquement la confirmation, jamais une fusion partielle silencieuse — voir écran 18.
- **Édition accidentelle de l'identité globale depuis une firme** : rendue impossible par construction (`PrestationConfigModal` n'expose aucun champ `code`/`name` d'intervention).

## Contraintes (intouchables)
- Une prestation n'affiche et ne modifie **jamais** un nom composite type « PTG Smith » — le libellé de l'intervention reste unique et global.
- Le logo est une propriété de `Firm` uniquement — jamais stocké/dupliqué par prestation, matériel ou facture.
- La fusion n'est accessible **que** depuis le détail d'une intervention globale — jamais depuis une prestation ou du matériel.
- Créer une intervention ne demande **jamais** firme/tarif/matériel/délégué — ces champs n'existent pas sur ce formulaire.
- Absence de tarif (forfait ou matériel) ≠ non facturable — toujours trois états distincts : montant défini / « Tarif à définir » / « Pas de forfait » (ce dernier uniquement pour les forfaits, via `feeApplicable`).
- La détection de doublons référentiel ne bloque jamais la création — suggestion uniquement.
- Tous les montants affichés sont HTVA, toujours explicitement suffixés.

## Ce qui n'est pas couvert par cette passe (à traiter séparément)
- **Pixel design** (dimensions/espacements/couleurs/typographie exacts) — volontairement hors périmètre, voir consigne utilisateur. Pas de `pixel-audit.md` pour cette raison (à la différence de `ajout-materiel/`, dont le contenu est déjà validé pixel).
- ~~**Endpoint d'upload du logo**~~ — **fait** (2026-08-03) : `Firm.logoPath` (migration `Version20260803150000`), `FirmLogoStorage` (mirrors `ProfilePictureStorage`), `POST`/`DELETE /api/firms/{id}/logo`. Stockage local (`public/uploads/firm-logos/`), pas un CDN — même mécanisme que les photos de profil existantes, cohérent avec l'infrastructure actuelle plutôt qu'une nouvelle dépendance.
- **Sélecteur de matériel suggéré réel** — stub dans le prototype (« + Ajouter » ajoute un texte factice) ; à remplacer par un vrai sélecteur du catalogue matériel de la firme (le vrai backend a déjà `POST .../service-offerings/{id}/suggested-materials`, voir `docs/api.md`).
- **Permissions/rôles** — non traité, hérite du gate `BillingVoter::MANAGE` déjà en place sur les endpoints réels.

## Checklist d'acceptation
☐ TopNav à 2 destinations de poids différent (jamais 3 onglets à plat) ☐ ContextBanner permanent, logo firme absent en vue Référentiel ☐ FirmAvatar unique, réutilisée partout ☐ nom d'intervention jamais composite ☐ Ajouter une prestation → recherche contextuelle → doublon détecté sans blocage → création inline avec retour automatique au flux ☐ prestation déjà existante → jamais de doublon, toujours « Ouvrir » ☐ une seule action Modifier par matériel ☐ politique délégué présente et fidèle à `OfferingDetailDialog` réel ☐ tous les montants suffixés HTVA ☐ Référentiel : liste avec compteurs firmes/missions, détail avec firmes+logos+lien direct vers la prestation ☐ fusion uniquement depuis le référentiel, jamais de fusion automatique, conflits bloquants explicites ☐ tous les états vides listés ☐ aucune différence notable avec le prototype `react-catalogue-prestations/`
