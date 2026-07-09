# Règles UX — SurgeryHub Instrumentiste

## Règles absolues
1. **Cibles tactiles ≥ 44px**, CTAs principaux 52–56px — l'app s'utilise avec des gants.
2. **Un CTA principal par contexte**, toujours en vert plein, toujours en bas du flux visuel.
3. **Jamais de saisie clavier quand un stepper/liste suffit** : heures ±15 min, quantités −/+, sélections en liste. Le clavier reste pour email, mot de passe, commentaires, noms.
4. **Feedback systématique** : toute action qui change des données déclenche un toast et/ou une mise à jour visible immédiate (badge, planning, compteurs).
5. **Brouillon jamais perdu** : l'encodage affiche « Brouillon en cours · Enregistré à HH:MM » ; l'heure se met à jour à chaque modification.
6. **Récapitulatif avant tout engagement** : la clôture de mission passe obligatoirement par l'écran récapitulatif.
7. **Statuts = couleur + libellé**, jamais la couleur seule.
8. **Décisions réversibles visibles** : une offre refusée reste affichée (grisée) ; une offre prise montre « Ajoutée à votre planning » + lien.

## Comportements attendus
- Login : Enter soumet ; bouton passe en « Connexion… » + spinner (~900ms simulé) ; « Se souvenir de moi » coché par défaut.
- Prendre/refuser une offre met à jour : badge nav, liste offres, planning (semaine, mois, à venir), rail accueil — sans rechargement.
- Le modal jour s'ouvre depuis : chips semaine, cellules mois, cards « À venir » — uniquement si le jour a des missions.
- Les actions du modal jour sont contextuelles : En cours → Encoder ; À encoder → Encoder ; Confirmée/À venir → aucune action.
- « Je ne trouve pas le matériel » crée une ligne « Matériel non trouvé — à préciser » (badge À préciser) et apparaît dans le récapitulatif.
- Déclarer une mission : date bornée à aujourd'hui (on déclare du passé/du jour), site + chirurgien obligatoires (toast sinon).
- Nuit : « Se termine le lendemain (après minuit) » — affiche « (+1j) » et calcule la durée sur deux jours. Optionnel, décoché par défaut.
- Sortie d'encodage (retour, validation) et arrivée : les vagues du header s'animent (voir animations).

## Zones à ne jamais modifier
- La hauteur des deux états de la card « Mission du jour » (345px) — aucun décalage de layout en basculant.
- L'ordre des onglets de nav et leurs icônes.
- Le patron des modals (titre / X / contenu / CTA / fantôme).
- Les paires fg/surface des statuts.

## Hiérarchie visuelle (par écran)
Header de marque (contexte) → carte/action principale → sections secondaires séparées par eyebrows + fil pointillé. Une seule photo par écran (hero). Le vert plein est réservé à ce qui est actionnable ou actif.
