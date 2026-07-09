# SurgeryHub — Design Handoff officiel

**Source de vérité pour l'implémentation de l'espace instrumentiste.** Claude Code doit s'y référer avant toute modification d'interface. Aucune décision visuelle ne doit être prise sans être documentée ici. Si une information manque, la signaler explicitement — ne jamais l'inventer.

Les prototypes HTML de référence (`SurgeryHub App v2.dc.html` + composants `.dc.html` à la racine du projet) sont la **référence exécutable** : en cas de doute sur une valeur, inspecter le prototype.

---

## Philosophie du design

SurgeryHub connecte des **instrumentistes de bloc opératoire indépendants** avec des établissements de soins belges. L'app est utilisée **sur smartphone, entre deux interventions ou au vestiaire du bloc** : gants, regards rapides, environnement stressant.

Le design est donc : **calme, factuel, à fort contraste, à grandes cibles tactiles**. Le vert de marque porte l'identité et les actions ; tout le reste s'efface (cartes blanches sur fond gris froid).

## Objectifs UX

1. **Zéro ambiguïté** : chaque écran répond à une question unique (Qu'est-ce que je fais aujourd'hui ? Quelles offres ? Quand suis-je pris ?).
2. **Encoder vite** : l'encodage post-intervention (matériel + heures) se fait au doigt, sans clavier quand c'est possible (steppers ±15 min, listes, quantité −/+).
3. **Toujours orienté action** : statuts colorés + un CTA principal par contexte.
4. **Confiance** : brouillon auto-enregistré, récapitulatif avant validation, feedback toast systématique.

## Principes UI

- **Signature « vagues »** : chaque header de marque porte des vagues organiques SVG qui **morphent** entre les écrans (voir `animations/animations.md`). C'est l'élément identitaire n°1.
- **Bloc-date calendrier** : les cards mission/offre affichent la date en tuile verticale teintée par statut. Élément identitaire n°2.
- **Fil « suture »** : lignes pointillées (dasharray ≈ 10 9) comme séparateurs et décor. Élément identitaire n°3.
- Cartes blanches radius 16–18px, ombres douces, séparateurs pointillés internes.
- Un seul dégradé de marque (header vert) ; pas de dégradés décoratifs ailleurs.
- Chiffres et horaires **toujours en tabular-nums** (`10h00 → 20h00`).
- Sentence case partout ; UPPERCASE réservé aux eyebrows trackés.
- Pas d'emoji sauf le 👋 du greeting.

## Responsive strategy

**Mobile-first, bascule unique à 900px** (`matchMedia('(min-width: 900px)')`).

| Breakpoint | Layout |
|---|---|
| < 900px | Mobile : header de marque plein largeur, bottom-nav ancrée, modals en bottom sheet |
| ≥ 900px | Desktop/tablette paysage : sidebar claire 248px, header de marque en carte arrondie (max 760px), modals en dialogue centré 480px |

Le contenu est plafonné à **720px** (colonne centrale) sur grand écran. Détails dans `layouts/`.

## Règles de cohérence

- Toute valeur visuelle vient de `design-tokens.json` / des variables CSS `--*`. Interdiction de coder une couleur ou un espacement en dur.
- Tout état de mission passe par les paires statut fg/surface (voir `design-system.md#statuts`).
- Tout modal utilise le même patron : overlay flouté + sheet/dialogue + `CloseButton` + CTA plein + action secondaire fantôme.
- Tout champ de formulaire utilise les composants `Field`, `SelectField`, `StepperRow`, `Checkbox` — jamais de contrôle natif nu.

## Contraintes importantes

- **Cibles tactiles ≥ 44–48px** (gants). CTAs principaux 52–56px.
- La photo d'établissement du hero vient du **backend** (`imageUrl`) ; prévoir le repli sans photo (fond dégradé vert uni) et l'état « journée libre » (illustration animée, hauteur identique 345px).
- Production : **React + TypeScript + MUI**. Les prototypes sont des références visuelles, pas du code à copier. Icônes : Lucide dans le prototype → mapper vers MUI Icons (voir `icons/icons.md`).
- Respecter `prefers-reduced-motion` (voir `animations/animations.md`).
