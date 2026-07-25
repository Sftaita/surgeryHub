/**
 * Contenu d'aide contextuelle — un topic par écran. Volontairement structuré
 * (heading + paragraphes/puces) plutôt qu'un bloc de texte libre : ça force
 * chaque contenu à rester organisé par sous-thème, jamais un pavé générique.
 */
export interface HelpSection {
  heading: string;
  paragraphs?: string[];
  bullets?: string[];
}

export interface HelpTopic {
  title: string;
  /** Une phrase : à quoi sert cet écran. Jamais "Cette page permet de...". */
  intro: string;
  sections: HelpSection[];
}
