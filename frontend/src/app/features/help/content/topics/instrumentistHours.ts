import type { HelpTopic } from "../../types";

export const instrumentistHours: HelpTopic = {
  title: "Heures instrumentistes",
  intro:
    "Heures encodées et validées de chaque instrumentiste pour un mois, avec le coût estimé des prestations au tarif personnel de chacune.",
  sections: [
    {
      heading: "Heures prises en compte",
      bullets: [
        "Heures encodées = heures soumises en attente de validation + heures validées.",
        "Heures validées = missions validées ou clôturées.",
        "Seules les heures réellement encodées comptent : jamais l'horaire planifié, aucune pause. Une prestation multi-salles compte la part imputée à chaque salle, jamais la présence deux fois.",
        "Une mission appartient au mois de son début planifié (heure de Bruxelles).",
      ],
    },
    {
      heading: "Coûts estimés",
      paragraphs: [
        "Le coût est une estimation du moteur financier au tarif personnel en vigueur à chaque prestation : tarif horaire pour le bloc, forfait pour la consultation. Ce n'est ni une facture ni un paiement.",
        "« Tarif manquant » signale des prestations qui ne peuvent pas être valorisées faute de tarif personnel ; leurs heures restent comptées.",
      ],
    },
    {
      heading: "Évolutions",
      bullets: [
        "Les pourcentages comparent les heures validées au mois précédent et à la moyenne personnelle des six mois précédents, sur le même site si un site est filtré.",
        "La moyenne ne commence qu'au mois de la première prestation connue de l'instrumentiste dans SurgicalHub (« sur 4 mois » si l'historique est plus court). Ce n'est pas une date d'entrée en activité : une première prestation connue ne la prouve pas.",
        "Les mois sans prestation à l'intérieur de cet historique comptent pour 0 heure ; le mois consulté n'entre jamais dans sa propre moyenne.",
        "« — » : aucune référence disponible ou référence à 0 heure, jamais un pourcentage artificiel.",
        "Mois en cours : comparaison provisoire à date équivalente (du 1er au jour d'aujourd'hui de chaque mois).",
      ],
    },
  ],
};
