import type { HelpTopic } from "../../types";

export const billingConfig: HelpTopic = {
  title: "Règles de facturation firmes",
  intro:
    "Configure, firme par firme, ce qui accélère l'encodage (prestations) et ce qui sera réellement facturé (tarifs) — deux choses indépendantes.",
  sections: [
    {
      heading: "Prestation",
      paragraphs: [
        "Une prestation associe une firme à un type d'intervention. Ce n'est pas un tarif : c'est ce qui permet de rattacher des matériels suggérés à ce couple firme/intervention pour accélérer l'encodage de l'instrumentiste.",
        "Une prestation peut avoir un forfait associé (un tarif fixe pour l'intervention entière, indépendant du détail du matériel utilisé) — c'est optionnel, « Définir un forfait » reste facultatif tant que vous facturez uniquement au matériel.",
      ],
    },
    {
      heading: "Matériel (tarif matériel)",
      paragraphs: [
        "Le tarif matériel fixe un prix unitaire pour un matériel précis de cette firme. Contrairement au forfait (qui couvre toute l'intervention), il s'additionne ligne par ligne selon les quantités réellement encodées.",
        "Un matériel ne peut avoir qu'un seul tarif actif à la fois, mais peut accumuler plusieurs versions dans le temps (voir Historique ci-dessous).",
      ],
    },
    {
      heading: "Période de validité",
      paragraphs: [
        "Chaque tarif a une date de début (« Valide à partir de ») et une date de fin optionnelle (« Valide jusqu'à »). Un champ de début vide veut dire « actif dès aujourd'hui » ; un champ de fin vide veut dire « sans fin prévue ».",
        "C'est cette période qui détermine automatiquement quel tarif s'applique à une mission encodée à une date donnée — vous n'avez jamais à choisir manuellement quelle version utiliser.",
      ],
    },
    {
      heading: "Historique",
      paragraphs: [
        "Chaque changement de tarif crée une nouvelle ligne d'historique plutôt que d'écraser l'ancienne. La colonne « Historique » indique combien de versions existent pour ce matériel ou cette intervention ; « Gérer les tarifs » ouvre le détail complet, du plus ancien au plus récent.",
      ],
    },
    {
      heading: "Modifier une règle",
      bullets: [
        "Il n'y a pas de « Modifier » sur le tarif actuellement en vigueur — l'action s'appelle « Remplacer à partir d'une date » : vous choisissez la date à partir de laquelle le nouveau montant s'applique, l'ancien tarif se termine automatiquement la veille.",
        "Un tarif programmé dans le futur (pas encore actif) peut lui être édité ou annulé librement, puisqu'aucune mission n'a encore pu s'appuyer dessus.",
      ],
    },
    {
      heading: "Pourquoi une règle n'est jamais écrasée",
      paragraphs: [
        "Une facture déjà émise doit toujours pouvoir être recalculée avec le tarif qui était réellement en vigueur au moment de la mission — si on écrasait un ancien montant par le nouveau, l'historique de facturation deviendrait incohérent avec ce qui a été réellement facturé.",
        "C'est pour ça que « remplacer » crée toujours une nouvelle version datée plutôt que de modifier le montant existant : chaque mission reste rattachée au tarif exact qui s'appliquait à sa date, quels que soient les changements tarifaires ultérieurs.",
      ],
    },
    {
      heading: "Bonnes pratiques",
      bullets: [
        "Définissez le tarif matériel ou le forfait avant le premier encodage réel pour cette firme — sans tarif actif, la valorisation financière de la mission échouera.",
        "Utilisez toujours « Remplacer à partir d'une date » pour un changement de prix, jamais une suppression suivie d'une recréation — vous perdriez la continuité de l'historique.",
        "Pour une hausse tarifaire connue à l'avance, programmez-la dès maintenant avec une date de début future plutôt que d'attendre le jour J.",
      ],
    },
  ],
};
