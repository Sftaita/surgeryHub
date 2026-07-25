import type { HelpTopic } from "../../types";

export const materialCatalogue: HelpTopic = {
  title: "Catalogue matériel",
  intro:
    "Le référentiel de tout le matériel (implants, instruments, consommables) que les instrumentistes peuvent encoder sur une mission, organisé par firme fournisseur.",
  sections: [
    {
      heading: "Créer un matériel",
      paragraphs: [
        "Un matériel appartient toujours à une firme (« marque ») — sélectionnez-la avant tout, elle conditionne dans quelles missions ce matériel pourra être proposé. Le nom, l'unité (pièce, unité...) et le statut Implant sont les seuls champs vraiment obligatoires ; la référence est facultative mais fortement recommandée.",
        "Le statut Implant n'est pas cosmétique : il distingue le matériel réellement implanté du reste (instruments, consommables), ce qui sert à d'autres écrans en aval (facturation, statistiques).",
      ],
    },
    {
      heading: "Recherche",
      paragraphs: [
        "La recherche filtre à la fois sur le nom du matériel et sur le nom de la firme — inutile de connaître le nom exact, taper le nom de la marque suffit souvent à retrouver toute sa gamme.",
      ],
    },
    {
      heading: "Marques (firmes)",
      paragraphs: [
        "La « marque » ici, c'est la firme partenaire (voir l'aide de l'écran Firmes). Un matériel ne peut pas changer de firme une fois qu'il a été utilisé dans une vraie mission — si vous vous êtes trompé de firme à la création et que le matériel n'a jamais servi, vous pouvez encore le corriger via Modifier.",
      ],
    },
    {
      heading: "Références",
      paragraphs: [
        "La référence (référence fabricant / catalogue) doit être unique pour une même firme — deux matériels de firmes différentes peuvent partager la même référence sans problème, mais pas deux matériels de la même firme.",
      ],
    },
    {
      heading: "Matériel suggéré",
      paragraphs: [
        "Le matériel suggéré ne se configure pas ici mais depuis « Règles de facturation firmes » (une prestation = firme + type d'intervention), où vous choisissez quels matériels de cette firme apparaissent en priorité pour ce type d'intervention.",
        "C'est une suggestion, jamais une contrainte : l'instrumentiste reste libre d'ajouter n'importe quel autre matériel de la firme pendant l'encodage.",
      ],
    },
    {
      heading: "Impact pendant l'encodage des instrumentistes",
      paragraphs: [
        "Quand un instrumentiste choisit un type d'intervention avec une firme principale, les matériels suggérés pour ce couple firme/intervention s'affichent en premier pour accélérer la saisie.",
        "Si le matériel dont l'instrumentiste a besoin n'existe pas encore dans le catalogue, il peut faire une demande directement depuis l'écran d'encodage — cette demande atterrit dans « Demandes matériel » où vous pouvez la valider (ce qui crée le matériel) ou l'ignorer.",
      ],
    },
    {
      heading: "Bonnes pratiques",
      bullets: [
        "Renseignez toujours la référence — elle sert de repère fiable pour l'instrumentiste et évite les doublons.",
        "Traitez régulièrement « Demandes matériel » : un matériel manquant non traité pousse les instrumentistes à improviser en commentaire libre, ce qui complique la facturation ensuite.",
        "Ne dupliquez pas un matériel existant sous un nom légèrement différent — cherchez d'abord par firme.",
      ],
    },
  ],
};
