import type { HelpTopic } from "../../types";

export const firms: HelpTopic = {
  title: "Firmes partenaires",
  intro:
    "Une firme est la société fournisseur du matériel utilisé en intervention — c'est le point central autour duquel s'organisent le catalogue matériel et les règles tarifaires.",
  sections: [
    {
      heading: "Rôle d'une firme",
      paragraphs: [
        "Une firme représente un fournisseur (ex. Stryker, Zimmer Biomet...). Elle porte l'identité de facturation : email de facturation, emails en copie, représentant commercial, pays. C'est vers ce contact que partent les factures générées pour cette firme.",
        "Désactiver une firme (plutôt que la supprimer) la retire des sélections pour les nouvelles missions, sans casser l'historique des factures et lignes déjà générées.",
      ],
    },
    {
      heading: "Relation avec les matériels",
      paragraphs: [
        "Chaque matériel du catalogue appartient à exactement une firme — c'est un lien obligatoire, pas une catégorie facultative. Vous ne pouvez créer un matériel sans d'abord avoir la firme correspondante.",
        "Un matériel peut changer de firme tant qu'il n'a jamais été utilisé dans une vraie ligne de mission ; une fois utilisé, la firme devient immuable pour préserver la cohérence de la facturation déjà émise.",
      ],
    },
    {
      heading: "Relation avec les prestations",
      paragraphs: [
        "Une prestation (« Règles de facturation firmes ») associe une firme à un type d'intervention — c'est un accélérateur de saisie : quand l'instrumentiste encode ce type d'intervention pour cette firme, les matériels suggérés apparaissent automatiquement.",
        "La prestation ne restreint jamais le matériel réellement utilisable pendant l'encodage — elle facilite juste la saisie, elle n'impose rien.",
      ],
    },
    {
      heading: "Impact sur la facturation",
      paragraphs: [
        "Les tarifs (forfait d'intervention, tarif par matériel) sont définis par firme dans « Règles de facturation firmes ». C'est cette combinaison firme + matériel/intervention qui détermine ce qui sera facturé lors de la génération d'une facture pour cette firme.",
        "Une firme sans aucun tarif actif ne bloque pas l'encodage, mais empêchera la valorisation financière des lignes correspondantes tant qu'un tarif n'est pas défini.",
      ],
    },
    {
      heading: "Bonnes pratiques",
      bullets: [
        "Renseignez l'email de facturation dès la création — c'est ce qui évite de devoir corriger des factures déjà parties.",
        "Ne supprimez une firme que si elle n'a jamais servi ; sinon désactivez-la.",
        "Vérifiez que chaque type d'intervention réellement facturé pour une firme a bien un forfait défini avant le premier encodage — pas après.",
      ],
    },
  ],
};
