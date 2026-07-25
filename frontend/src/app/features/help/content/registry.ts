import type { HelpTopic } from "../types";
import { planningV2 } from "./topics/planningV2";
import { firms } from "./topics/firms";
import { materialCatalogue } from "./topics/materialCatalogue";
import { billingConfig } from "./topics/billingConfig";
import { missionEncoding } from "./topics/missionEncoding";

/**
 * Un topic = un écran. Pour ajouter l'aide d'un nouvel écran :
 * 1. Créer `content/topics/monEcran.ts` exportant un `HelpTopic` (voir ../types.ts).
 * 2. L'importer et l'ajouter ci-dessous avec une clé stable.
 * 3. Poser `<HelpButton topicId="mon-ecran" />` dans le header de la page.
 * Aucun composant à modifier — voir docs/architecture.md « Aide contextuelle ».
 */
export const HELP_REGISTRY: Record<string, HelpTopic> = {
  "planning-v2": planningV2,
  firms: firms,
  "material-catalogue": materialCatalogue,
  "billing-config": billingConfig,
  "mission-encoding": missionEncoding,
};

export type HelpTopicId = keyof typeof HELP_REGISTRY;
