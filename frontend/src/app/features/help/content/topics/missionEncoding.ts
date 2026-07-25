import type { HelpTopic } from "../../types";

export const missionEncoding: HelpTopic = {
  title: "Encodage d'une mission",
  intro:
    "Ce que vous encodez ici (heures, interventions, matériel) alimente directement le calcul financier de la mission — un encodage incomplet bloque la facturation, pas seulement le suivi.",
  sections: [
    {
      heading: "Démarrer une mission",
      paragraphs: [
        "« Démarrer l'encodage » est une étape optionnelle : elle passe la mission en « Encodage en cours » pour signaler que vous avez commencé à travailler dessus. Vous pouvez tout aussi bien encoder directement sans cliquer dessus — la première modification (intervention, matériel, heures) fait implicitement la même chose.",
      ],
    },
    {
      heading: "Encoder les horaires",
      paragraphs: [
        "Les heures prestées se règlent par pas de 15 minutes (début, fin, pause éventuelle) — pas de saisie libre au clavier, pour éviter les erreurs de frappe sur un horaire qui sert ensuite au calcul financier.",
        "Si la mission se termine après minuit, cochez l'option « jour suivant » plutôt que de saisir une heure de fin antérieure à l'heure de début.",
      ],
    },
    {
      heading: "Encoder le matériel",
      paragraphs: [
        "Chaque intervention encodée peut recevoir ses propres lignes de matériel. Si vous avez choisi une firme principale pour l'intervention, les matériels suggérés pour ce couple firme/intervention apparaissent en premier — mais vous pouvez ajouter n'importe quel autre matériel de la même firme, la suggestion n'est jamais une contrainte.",
        "Chaque ligne matériel prend une quantité et un commentaire optionnel (utile pour préciser un lot, une taille, une particularité).",
      ],
    },
    {
      heading: "Ajouter un matériel absent du catalogue",
      paragraphs: [
        "Si le matériel utilisé n'existe pas encore dans le catalogue, ne l'inventez pas en commentaire libre sur une autre ligne : faites une « demande de matériel » directement depuis l'écran d'encodage. Elle part au manager, qui peut la valider (le matériel est alors créé et rattaché) ou la classer sans suite.",
        "De la même façon, si le type d'intervention lui-même n'existe pas dans le référentiel, vous pouvez faire une « demande de nouveau type » — l'intervention ne peut pas être créée tant que ce type n'existe pas, c'est un référentiel fermé.",
      ],
    },
    {
      heading: "Terminer une mission",
      paragraphs: [
        "« Terminer l'encodage » ouvre un récapitulatif (heures, nombre d'interventions, lignes de matériel) avant de soumettre. Une fois soumise, la mission passe en attente de validation manager et n'est plus modifiable de votre côté.",
        "Si le manager rejette l'encodage, la mission revient automatiquement modifiable avec son motif de rejet affiché en haut de l'écran — corrigez ce qui est demandé puis re-soumettez, sans devoir tout ressaisir.",
      ],
    },
    {
      heading: "Bonnes pratiques",
      bullets: [
        "Encodez au fil de l'eau plutôt qu'en fin de journée sur plusieurs missions à la fois — moins d'oublis sur le matériel réellement utilisé.",
        "Ne laissez jamais une intervention sans aucune ligne de matériel si du matériel a réellement été utilisé — c'est ce qui alimente la facturation à la firme.",
        "Relisez le récapitulatif avant de soumettre : une fois soumis, seul un rejet manager permet de rouvrir l'encodage.",
      ],
    },
  ],
};
