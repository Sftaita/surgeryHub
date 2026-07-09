# profile — implémenté, sans maquette complète

> **Statut : implémenté** (`frontend/src/app/pages/instrumentist/ProfilePage.tsx`), **mais toujours sans maquette officielle** pour l'agencement général (carte identité, chips spécialités). Ce n'est pas un écran à conformité pixel-perfect vérifiée — seule la partie photo de profil est désormais couverte par une référence documentée.
>
> Si une refonte visuelle de cet écran est demandée, il faut d'abord une passe de design (maquettes + captures) avant d'improviser — cette page n'y échappe pas juste parce qu'elle existe déjà en code.

## Contexte

Profil instrumentiste (infos, spécialités, photo de profil). Le menu compte (accès à cet écran) est designé (voir `components/navigation.md`).

## Ce qui est couvert par une référence design

- **Photo de profil** : `components/avatar.md` — `AvatarUploader` en taille `lg` (56px), même composant que partout ailleurs dans l'app. Décision produit validée après le design handoff initial ; voir `design-system.md → Avatars`.

## Ce qui ne l'est toujours pas

- Carte identité (nom, email, statut actif/suspendu), disposition des chips de spécialités orthopédiques, espacements de la page — code existant, non vérifié contre une maquette. Ne pas le prendre comme référence pour d'autres écrans sans validation préalable.

## Si un besoin de refonte apparaît

Signaler le besoin plutôt que d'improviser une nouvelle disposition : ajouter une entrée dans `routes.md` avec le statut approprié, puis demander une passe de design avant toute modification visuelle au-delà de la photo de profil.
