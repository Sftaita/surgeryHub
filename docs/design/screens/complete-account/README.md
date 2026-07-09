# CompleteAccountPage

Statut : Documentation partielle

Cet écran ne possède pas encore de maquette officielle complète.

Cette documentation distingue volontairement :

- les éléments validés par le design officiel ;
- les éléments actuellement implémentés dans le code ;
- les éléments restant à concevoir.

Aucune implémentation actuelle ne doit être présentée comme une décision de design.

---

## Route

### ✅ Design validé
Aucun élément validé — cette route n'apparaît dans aucune référence officielle.

### ⚙️ Implémentation actuelle
`/complete-account` — route publique, au même niveau que `/login` (hors `RequireAuth`/`RequireAppAccess`). Paramètre de requête `?token=...` obligatoire (jeton d'invitation).

### ⚠️ À valider
`docs/design/routes.md` ne couvre que l'espace instrumentiste connecté (AppLayout) ; aucune convention officielle n'existe pour les routes publiques d'onboarding. Le nommage `/complete-account` (anglais, kebab-case) n'a jamais été confronté à une convention documentée.

---

## Composant React

### ✅ Design validé
Aucun élément validé.

### ⚙️ Implémentation actuelle
`CompleteAccountPage` (export par défaut) — `frontend/src/app/pages/CompleteAccountPage.tsx`. Layout interne dédié (`PageShell`, non partagé, défini dans le même fichier).

### ⚠️ À valider
Aucun.

---

## Layout

### ✅ Design validé
Aucun élément validé.

### ⚙️ Implémentation actuelle
Plein écran, sans nav ni sidebar : fond `grey.50` centré (flex, `alignItems`/`justifyContent: center`), carte blanche unique (`background.paper`, bordure 1px `divider`, `borderRadius: 3` MUI = 24px, padding `20px` mobile / `32px` desktop), largeur max `540px`.

### ⚠️ À valider
Aucune référence ne dit si cet écran doit suivre le patron `login/README.md` (panneau de marque en dégradé, vagues, logo, split 2 colonnes en desktop) ou rester un simple formulaire centré comme actuellement. Les deux écrans partagent le même statut fonctionnel (« plein écran, sans nav ») mais des traitements visuels aujourd'hui très différents, jamais réconciliés.

---

## Objectif

### ✅ Design validé
Aucun élément validé.

### ⚙️ Implémentation actuelle
Finaliser un compte instrumentiste après invitation d'un manager : renseigner identité (prénom/nom pré-remplis depuis l'invitation), téléphone, mot de passe, informations professionnelles optionnelles (société/TVA, pour les freelances), et être incité — sans obligation — à ajouter une photo de profil.

### ⚠️ À valider
Aucun.

---

## Flux utilisateur

### ✅ Design validé
Aucun élément validé.

### ⚙️ Implémentation actuelle
1. Arrivée via lien d'invitation contenant `?token=...`.
2. Vérification du jeton (`checkInvitation`) : chargement (spinner + texte) → puis l'un de : lien manquant, lien invalide, lien déjà utilisé (redirection silencieuse vers `/login`), lien expiré, ou lien valide.
3. Si valide : formulaire affiché, prénom/nom pré-remplis depuis l'invitation.
4. Bloc **photo de profil mis en avant en premier**, avant les champs d'identité : ajouter une photo (sélection + recadrage via `AvatarUploader`) ou cliquer « Continuer sans photo » (déplace le focus clavier sur le champ Prénom, ne saute aucune étape).
5. Complétion des champs obligatoires (téléphone, mot de passe, confirmation) et optionnels (société, TVA).
6. Soumission (`completeInvitation`, multipart incluant la photo si choisie) :
   - Succès → écran de confirmation + bouton « Se connecter ».
   - Conflit (HTTP 409, compte déjà activé entre-temps) → redirection silencieuse vers `/login`, sans message.
   - Autre erreur → message affiché en haut du formulaire (`Alert` MUI), le formulaire reste rempli.

### ⚠️ À valider
L'ordre « photo avant identité » est un choix d'implémentation non confronté à une maquette. Aucune réflexion UX documentée sur l'abandon en cours de flux (pas de sauvegarde de brouillon, contrairement au principe « brouillon auto-enregistré » du design system pour l'encodage).

---

## Responsive

### ✅ Design validé
Aucun élément validé.

### ⚙️ Implémentation actuelle
Un seul point de bascule interne, local à cet écran : la paire Prénom/Nom passe de `column` (< 600px, breakpoint MUI `xs`) à `row` (≥ 600px, `sm`). Le reste (carte centrée max 540px, padding réduit en mobile) est identique à toutes les largeurs. Pas de bottom-nav, pas de sidebar, pas de bascule mobile/desktop comme sur les écrans de l'app connectée.

### ⚠️ À valider
Le design system impose une bascule unique à 900px (`matchMedia('(min-width: 900px)')`, voir `docs/design/README.md`) pour tout le reste de l'application. Cet écran utilise un breakpoint différent (600px, valeur par défaut MUI) et une logique locale jamais confrontée à cette règle générale. Non tranché si c'est acceptable (écran hors app, avant connexion) ou à harmoniser.

---

## États

### ✅ Design validé
Aucun élément validé.

### ⚙️ Implémentation actuelle
- Chargement (vérification du jeton) : `CircularProgress` + texte « Vérification du lien… ».
- Lien manquant ou invalide : `Alert severity="error"`.
- Lien déjà utilisé : aucun rendu — redirection silencieuse vers `/login`.
- Lien expiré : `Alert severity="warning"`.
- Formulaire (défaut) : tous les champs actifs.
- Soumission en cours (`isPending`) : tous les champs et boutons désactivés, libellé du bouton principal → « Activation en cours… ».
- Erreur de soumission (hors 409) : `Alert severity="error"` au-dessus du bouton principal.
- Succès : `Alert severity="success"` + bouton « Se connecter ».

### ⚠️ À valider
Aucun de ces états n'a de rendu visuel officiellement validé — ce sont les styles par défaut des composants `Alert`/`CircularProgress` de MUI, jamais comparés à une maquette ni à la règle du design system « validation douce par toast, jamais de rouge agressif plein écran » (`design-system.md#Errors`).

---

## Composants utilisés

### ✅ Design validé
`AvatarUploader` (taille `xl`, 88px) — voir `components/avatar.md`. Seul composant de cet écran couvert par une référence design.

### ⚙️ Implémentation actuelle
`TextField`, `Button`, `Alert`, `Divider`, `Stack`, `CircularProgress`, `Typography`, `Box` — composants MUI utilisés nus, sans wrapper maison.

### ⚠️ À valider
Le design system impose, pour l'espace instrumentiste connecté, les composants partagés `Field`/`SelectField`/`StepperRow`/`Checkbox` et interdit « tout contrôle natif nu » (`docs/design/README.md#Règles-de-cohérence`). Cet écran (public, avant authentification) ne les utilise pas. Non tranché si c'est une exception assumée (écran hors app) ou un écart à corriger.

---

## Design Tokens

### ✅ Design validé
Taille d'avatar `size.avatar.xl` (88px) et palette de repli `semantic.avatarIdentity`, via `AvatarUploader`/`PersonAvatar` — voir `design-tokens.json`.

### ⚙️ Implémentation actuelle
Le reste de l'écran utilise des valeurs MUI par défaut ou codées en dur dans le composant : `borderRadius: 3` (24px), paddings `p: 2` / `p: 2.5` / `p: 4`, `bgcolor: "grey.50"`, `maxWidth: 540`. Aucune de ces valeurs ne référence `design-tokens.json` (`radius.*`, `space.*`, `color.gray.*`).

### ⚠️ À valider
Aucun mapping n'existe entre ces valeurs codées en dur et les tokens officiels. À faire si cet écran doit un jour se conformer à la règle « toute valeur visuelle vient de design-tokens.json — interdiction de coder une couleur ou un espacement en dur ».

---

## Accessibilité

### ✅ Design validé
Aucun élément validé pour cet écran spécifiquement. Le bouton d'upload de `AvatarUploader` porte un `aria-label` (« Ajouter une photo de profil » / « Changer la photo de profil ») — hérité du composant partagé, voir `components/avatar.md`.

### ⚙️ Implémentation actuelle
Champs `TextField` avec `label`/`helperText`/`error` (association native MUI). Champ mot de passe sans bouton « afficher/masquer », contrairement à `LoginPage` (voir `screens/login/README.md`). Aucun `aria-live` sur les messages d'erreur de soumission ou de validation de champ.

### ⚠️ À valider
Aucun audit d'accessibilité officiel n'a été réalisé sur cet écran (contrastes, ordre de tabulation, annonce des erreurs aux lecteurs d'écran, taille tactile des contrôles).

---

## UX

### ✅ Design validé
Aucun élément validé.

### ⚙️ Implémentation actuelle
Photo mise en avant avant les champs d'identité, avec échappatoire explicite (« Continuer sans photo ») qui déplace le focus plutôt que de masquer une étape. Aide contextuelle sous chaque bloc (mentions « Optionnel »). Erreurs de champ affichées inline sous chaque `TextField`. Redirection silencieuse (sans message affiché) si le lien a déjà été utilisé ou en cas de conflit 409.

### ⚠️ À valider
Le principe du design system « validation douce par toast [...] jamais de rouge agressif plein écran » (`design-system.md#Errors`) n'est pas appliqué ici : les erreurs utilisent des `Alert` MUI pleine largeur du formulaire, pas des toasts. Non tranché si c'est intentionnel pour un écran hors app (avant connexion, pas de système de toast disponible dans ce contexte) ou un écart à corriger.

---

## Contraintes

### ✅ Design validé
Aucune contrainte officiellement déclarée pour cet écran (contrairement à `login/README.md#Contraintes`).

### ⚙️ Implémentation actuelle
Le jeton vient uniquement du paramètre d'URL `?token=` (aucune saisie manuelle). Prénom/nom pré-remplis depuis l'invitation restent éditables (pas de lecture seule). La photo n'est jamais envoyée séparément : elle est intégrée au `FormData` de `completeInvitation` — contrairement au mode « auto » de `AvatarUploader` utilisé ailleurs (upload immédiat vers `/api/me/profile-picture`), ici le fichier est simplement conservé en état local jusqu'à la soumission du formulaire complet.

### ⚠️ À valider
Aucune contrainte n'est « intouchable » par absence de maquette — tout reste ouvert à une future passe de design.

---

## Points restant à concevoir

- Maquette complète (mobile + desktop) de l'écran, absente à ce jour.
- Alignement ou non avec le design system existant : bascule responsive à 900px (au lieu de 600px), composants `Field`/`SelectField` (au lieu de `TextField` nu), gestion des erreurs par toast (au lieu d'`Alert` pleine largeur).
- Traitement visuel des 5 états liés au jeton (manquant, invalide, expiré, déjà utilisé, erreur réseau) — actuellement des `Alert` MUI par défaut, jamais validés visuellement.
- Décision sur le layout général : formulaire centré simple (actuel) vs. panneau de marque façon `login/README.md`.
- Audit d'accessibilité complet (contraste, navigation clavier, annonce des erreurs).
- Cohérence des tokens `radius`/`space`/`color` avec `design-tokens.json` (actuellement valeurs MUI par défaut ou codées en dur).
- Décision produit : l'ordre « photo en premier » doit-il être conservé une fois une maquette produite ?
