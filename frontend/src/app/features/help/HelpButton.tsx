import * as React from "react";
import { IconButton, Tooltip, type SxProps, type Theme } from "@mui/material";
import HelpOutlineIcon from "@mui/icons-material/HelpOutline";

import { HelpDrawer } from "./HelpDrawer";
import type { HelpTopicId } from "./content/registry";

type HelpButtonProps = {
  /** Clé du topic dans HELP_REGISTRY (content/registry.ts). */
  topicId: HelpTopicId | (string & {});
  size?: "small" | "medium";
  /** Pour s'intégrer à un header non-MUI (ex. fond coloré) sans dupliquer le composant. */
  sx?: SxProps<Theme>;
};

/**
 * Bouton d'aide contextuelle — le seul composant à poser dans le header d'un
 * écran : `<HelpButton topicId="firms" />`. Gère lui-même l'état d'ouverture
 * du panneau, aucun câblage supplémentaire nécessaire.
 */
export function HelpButton({ topicId, size = "small", sx }: HelpButtonProps) {
  const [open, setOpen] = React.useState(false);

  return (
    <>
      <Tooltip title="Aide">
        <IconButton
          size={size}
          onClick={() => setOpen(true)}
          aria-label="Aide sur cet écran"
          sx={{ color: "text.secondary", ...sx }}
        >
          <HelpOutlineIcon fontSize={size} />
        </IconButton>
      </Tooltip>
      <HelpDrawer topicId={topicId} open={open} onClose={() => setOpen(false)} />
    </>
  );
}

export default HelpButton;
