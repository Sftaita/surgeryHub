# PersonAvatar / AvatarUploader — identité visuelle d'une personne

Référence exécutable : implémentation `frontend/src/app/ui/avatar/PersonAvatar.tsx` et `AvatarUploader.tsx` (pas de prototype `.dc.html` — composant ajouté avec la fonctionnalité photo de profil, décision produit validée après le design handoff initial).

**Composant unique dans toute l'app** : jamais de MUI `Avatar` nu, jamais de pastille d'initiales recodée localement. Réutiliser `PersonAvatar` (lecture seule) ou `AvatarUploader` (édition) — ne pas dupliquer.

## Dimensions

Cercle uniquement, échelle nommée (`design-tokens.json → size.avatar`) :

| Token | px | Usage |
|---|---|---|
| `xs` | 24 | Lignes de planning, listes denses |
| `sm` | 32 | Cartes, menus |
| `md` | 40 | En-têtes de section |
| `lg` | 56 | Tiroirs (drawers), profil compact |
| `xl` | 88 | Upload : onboarding, modale de rappel |

## Couleurs

- **Photo présente** : image recadrée carrée (voir Recadrage ci-dessous), aucune teinte appliquée.
- **Pas de photo (repli initiales)** : `design-tokens.json → semantic.avatarIdentity`, 6 paires bg/fg choisies par hash déterministe du nom (`avatarColorFor`) — la même personne obtient toujours la même couleur, sans stockage serveur.
- **Exception** : le bloc utilisateur du header/sidebar de marque garde son traitement `green-100`/`green-800` (`components/navigation.md`) — propre à cet emplacement, pas la règle générale des avatars.

## AvatarUploader — interactions

- Icône caméra superposée (badge 28px, coin bas-droit, fond `white`, bord `gray-200`).
- Glisser-déposer sur l'avatar (anneau `brand.default` au survol du fichier).
- Sélection → **recadrage carré obligatoire** (`AvatarCropDialog`, zoom + recentrage) avant tout envoi — jamais d'upload d'une image non carrée.
- Chargement : anneau `CircularProgress` superposé, assombrissement du cercle.
- Suppression : icône superposée coin bas-gauche, visible uniquement si une photo/aperçu existe.
- Erreur : légende sous l'avatar (jamais un toast seul).

## Règle

Un seul composant pour représenter une personne, partout : `PersonAvatar` en lecture seule, `AvatarUploader` dès qu'une action d'édition est possible. Fallback initiales systématique — jamais de silhouette vide ni de cercle blanc nu pour une personne identifiée (réservé au slot « non assigné », voir `EmptyAvatar`).
