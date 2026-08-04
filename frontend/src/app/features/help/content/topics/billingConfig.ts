import type { HelpTopic } from "../../types";

export const billingConfig: HelpTopic = {
  title: "Prestations",
  intro:
    "Deux espaces distincts : Firmes (configuration commerciale, propre à chaque firme) et Référentiel (bibliothèque clinique globale, commune à toutes les firmes).",
  sections: [
    {
      heading: "Firmes vs Référentiel",
      paragraphs: [
        "« Firmes » configure ce qui est propre à une firme donnée : ses prestations, son matériel, son logo. « Référentiel » est la bibliothèque clinique globale des interventions, partagée par toutes les firmes — aucune configuration commerciale ne s'y trouve.",
        "Le bandeau sous les deux boutons rappelle en permanence dans quel contexte vous êtes : « CONFIGURATION FIRME · {nom} » ou « RÉFÉRENTIEL GLOBAL ».",
      ],
    },
    {
      heading: "Prestation",
      paragraphs: [
        "Une prestation associe une firme sélectionnée à une intervention du référentiel : forfait éventuel, matériels suggérés, statut actif/inactif, et politique de présence d'un délégué.",
        "Le libellé et le code affichés sur une prestation sont toujours ceux de l'intervention du référentiel — jamais une donnée dupliquée propre à la firme.",
      ],
    },
    {
      heading: "Ajouter une prestation",
      bullets: [
        "Le bouton « Ajouter une prestation » ouvre une recherche dans le référentiel global, triée alphabétiquement.",
        "Si l'intervention existe déjà pour cette firme, elle apparaît avec l'étiquette « Déjà configurée » et un lien « Ouvrir » vers sa configuration existante — jamais de doublon créé par erreur.",
        "Si l'intervention n'existe pas encore dans le référentiel, saisissez son nom puis choisissez « Ajouter … au référentiel » : elle est créée globalement puis rattachée à la firme en cours dans le même geste.",
        "Avant toute création, une vérification de doublon approximatif est effectuée — si une intervention proche existe déjà, vous pouvez l'utiliser directement au lieu de créer un doublon.",
      ],
    },
    {
      heading: "Forfait — trois états, jamais confondus",
      bullets: [
        "Un montant chiffré (« 191,00 € HTVA ») : un tarif est actif pour cette prestation.",
        "« Tarif à définir » : un forfait est prévu, mais aucun montant n'a encore été configuré.",
        "« Pas de forfait » : décision volontaire — cette prestation n'a jamais de forfait, distinct d'un tarif simplement pas encore défini.",
      ],
    },
    {
      heading: "Présence d'un délégué",
      paragraphs: [
        "Configure si la présence d'un délégué de la firme a un effet commercial sur cette prestation précise, et lequel : neutralise le forfait, neutralise le matériel facturable de la firme, ou aucun effet.",
        "La présence effective du délégué est encodée par l'instrumentiste au moment de la mission — ici, on configure seulement si cette présence a un effet, jamais l'inverse.",
      ],
    },
    {
      heading: "Matériel",
      paragraphs: [
        "Le tarif matériel fixe un prix unitaire pour un article précis de cette firme, ligne par ligne selon les quantités réellement encodées — contrairement au forfait, qui couvre toute l'intervention en un montant fixe.",
        "« Nouveau matériel » ne demande que l'identification (nom, unité, référence, implant ou non) : le tarif se configure ensuite depuis « Modifier », avec le même historique de versions que le forfait.",
        "Un matériel sans tarif actif affiche « Tarif à définir », jamais masqué ni confondu avec « non facturable ».",
      ],
    },
    {
      heading: "Historique et remplacement de tarif",
      paragraphs: [
        "Chaque changement de tarif (forfait ou matériel) crée une nouvelle ligne d'historique plutôt que d'écraser l'ancienne — une facture déjà émise reste toujours calculable avec le tarif réellement en vigueur à la date de la mission.",
        "Il n'y a pas de « Modifier » sur le tarif en vigueur : l'action s'appelle « Remplacer à partir d'une date », l'ancien tarif se termine automatiquement la veille. Un tarif programmé dans le futur, lui, peut être librement édité ou annulé.",
      ],
    },
    {
      heading: "Référentiel — l'intervention elle-même",
      paragraphs: [
        "Le tableau du Référentiel liste toutes les interventions cliniques : code, libellé, nombre de firmes utilisatrices, statut. Cliquer sur une ligne ouvre son détail — jamais une modale, toujours dans le même espace.",
        "« Modifier » depuis le Référentiel ne touche qu'aux champs globaux (libellé, spécialité, statut) — le code est immuable, et aucun tarif ni firme n'apparaît sur ce formulaire.",
        "Le détail d'une intervention liste les firmes qui l'utilisent avec leur forfait respectif, et « Ouvrir chez cette firme → » bascule directement vers sa configuration dans l'espace Firmes.",
      ],
    },
    {
      heading: "Bonnes pratiques",
      bullets: [
        "Définissez le tarif matériel ou le forfait avant le premier encodage réel pour cette firme — sans tarif actif, la valorisation financière de la mission échouera.",
        "Utilisez toujours « Remplacer à partir d'une date » pour un changement de prix, jamais une suppression suivie d'une recréation — vous perdriez la continuité de l'historique.",
        "Avant de créer une intervention dans le référentiel, vérifiez la suggestion de doublon proposée automatiquement — un référentiel propre profite à toutes les firmes.",
      ],
    },
  ],
};
