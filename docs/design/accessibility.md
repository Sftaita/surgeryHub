# Accessibilité — SurgeryHub Instrumentiste

## Contrastes
- Texte principal `gray-900` sur blanc : ≈ 15:1. Texte muted `gray-500` sur blanc : ≈ 4.6:1 (réservé au texte ≥ 13px secondaire).
- Blanc sur `green-700` (CTAs) : ≈ 4.9:1 ✔ · Blanc sur `green-500` : ≈ 2.9:1 — **réservé aux textes ≥ 15px bold** (grands caractères) ; ne pas utiliser green-500 pour du petit texte blanc.
- `green-300` sur vert profond (sous-titres header) : réservé aux textes ≥ 14px/600 ; vérifié ≥ 3:1 sur green-800/900.
- Ne jamais poser de texte < 14px sur la photo du hero sans le voile dégradé (`heroPhotoOverlay`).

## Focus
- Ring visible 3px `--focus-ring` (vert 32%) sur tout élément interactif. **Jamais de `outline: none` sans remplacement.**
- Champs : bord passe à `green-500` 1.5px + ring.
- L'ordre de focus suit l'ordre visuel ; dans un modal, le focus est piégé (trap) et revient au déclencheur à la fermeture.

## Navigation clavier
- Enter soumet le login et valide les champs de saisie.
- Échap ferme modals/sheets (à implémenter en production ; le prototype ferme par X et overlay).
- Steppers : boutons −/+ focusables ; prévoir aussi flèches haut/bas en production.
- Onglets de nav : parcourables au Tab, activables Enter/Espace.

## ARIA
- Tous les boutons icône portent `aria-label` explicite : « Fermer », « Notifications », « Afficher le mot de passe », « Moins 15 minutes », « Jour précédent »…
- Nav : `role="navigation"` + `aria-current="page"` sur l'onglet actif.
- Modals : `role="dialog"` + `aria-modal="true"` + `aria-labelledby` (titre).
- Badge compteur : inclure le nombre dans un libellé accessible (« 3 offres en attente »).
- Toasts : `role="status"` (aria-live polite).
- Statuts : le libellé texte est toujours présent (jamais la couleur seule).

## Zones tactiles & tailles minimales
- Minimum absolu 44×44px ; standard 46–48px ; CTAs 52–56px.
- Espacement min entre cibles adjacentes : 8px (steppers : 12px).
- Texte minimal : 11px uniquement pour micro-méta (mois de la tuile date) ; corps ≥ 13px ; inputs 16px (évite le zoom iOS).

## Mouvement
- `prefers-reduced-motion: reduce` : désactiver morphing des vagues, pulse, illustration animée, slides de sheets (remplacer par fade court ou apparition directe). Conserver les changements d'état instantanés.
