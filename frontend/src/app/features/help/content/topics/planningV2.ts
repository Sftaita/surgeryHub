import type { HelpTopic } from "../../types";

export const planningV2: HelpTopic = {
  title: "Planning",
  intro:
    "Le planning V2 construit les missions du mois à partir des postes chirurgiens récurrents, avant de les publier réellement aux instrumentistes.",
  sections: [
    {
      heading: "Objectif de cet écran",
      paragraphs: [
        "Le planning ne se remplit pas manuellement mission par mission : il se construit à partir des « postes chirurgiens » (onglet Postes) — un chirurgien, un site, un jour et une période récurrente (ex. tous les mardis matin). C'est cette récurrence qui génère automatiquement les missions du mois choisi.",
        "L'onglet Générer est celui que vous utiliserez le plus souvent : c'est lui qui transforme les postes récurrents en missions réelles pour un mois donné.",
      ],
    },
    {
      heading: "Prévisualiser / Générer / Déployer — trois étapes distinctes",
      bullets: [
        "Prévisualiser : calcule ce que donneraient les postes récurrents pour le(s) mois choisis, sans rien écrire en base. C'est ici que vous voyez chaque ligne annotée (OK, Mission ouverte, Conflit, Chirurgien absent...) et que vous pouvez corriger avant d'aller plus loin.",
        "Générer les missions : crée réellement les missions en base à partir de l'aperçu validé. À ce stade, rien n'est encore visible des instrumentistes.",
        "Déployer le planning : rend les missions visibles et déclenche les notifications aux instrumentistes concernés (et l'envoi des PDF si l'option est cochée). C'est la seule étape qui a un effet côté utilisateurs.",
      ],
    },
    {
      heading: "Comprendre les statuts de l'aperçu",
      bullets: [
        "OK (vert) : le poste est couvert normalement, l'instrumentiste habituel est assigné.",
        "Mission ouverte (bleu) : personne n'est assigné automatiquement — la mission partira dans le pool OPEN, ouverte à candidature libre pour les instrumentistes éligibles.",
        "Chirurgien absent (jaune) : le poste est ignoré ce jour-là car une absence chirurgien couvre la date — aucune mission n'est créée pour cette occurrence.",
        "Conflit (rouge) : un problème bloquant existe (ex. double réservation) — à corriger avant de générer.",
        "Modifié (bleu foncé) : vous avez édité cette ligne manuellement dans l'aperçu (changement d'instrumentiste, horaire, etc.) avant génération.",
      ],
    },
    {
      heading: "D'où viennent les missions OPEN",
      paragraphs: [
        "Une mission part automatiquement dans le pool OPEN quand aucun instrumentiste habituel n'est disponible au moment de la génération (statut « Mission ouverte » dans l'aperçu). Elle devient alors visible dans les offres des instrumentistes éligibles, qui peuvent la réclamer.",
      ],
    },
    {
      heading: "Modifier un planning déjà déployé",
      paragraphs: [
        "Une fois déployé, le planning est « vivant » : toute modification (changement d'instrumentiste, annulation, libération) passe par le mode Modification de l'onglet Générer, qui agit directement sur les missions existantes — ce n'est plus un cycle génération/déploiement.",
        "Ne recommencez jamais une génération sur une période déjà déployée pour « corriger » quelque chose : utilisez la modification en place, sinon vous risquez de dupliquer des missions.",
      ],
    },
    {
      heading: "Notifications envoyées",
      paragraphs: [
        "Le déploiement notifie chaque instrumentiste des missions qui lui sont assignées. Les missions ouvertes (pool) notifient les instrumentistes éligibles qu'une offre est disponible. Les alertes post-déploiement (absence, conflit, réassignation nécessaire) apparaissent dans l'onglet Alertes et ne notifient personne automatiquement tant qu'elles ne sont pas traitées.",
      ],
    },
    {
      heading: "L'onglet Alertes",
      paragraphs: [
        "Une alerte apparaît quand une situation change après coup et menace la couverture d'une mission déjà déployée : absence chirurgien ou instrumentiste, conflit d'agenda, réassignation nécessaire, ou occurrence annulée.",
        "Pour chaque alerte vous pouvez : l'acquitter (vous l'avez vue, pas encore traitée), la résoudre (le problème est réglé), l'ignorer (non pertinente), l'ouvrir au pool (en faire une mission OPEN) ou réassigner directement un autre instrumentiste.",
      ],
    },
    {
      heading: "Bonnes pratiques",
      bullets: [
        "Traitez toujours les lignes « Conflit » avant de générer — elles ne se résolvent pas toutes seules.",
        "Vérifiez les lignes « Mission ouverte » : si elles sont nombreuses sur une période, c'est souvent le signe d'un sous-effectif à anticiper plutôt qu'un cas isolé.",
        "Ne redéployez un mois déjà déployé qu'en connaissance de cause — les instrumentistes déjà notifiés recevront une nouvelle notification.",
        "Traitez les alertes ouvertes régulièrement : elles s'accumulent silencieusement si personne ne va les consulter.",
      ],
    },
  ],
};
