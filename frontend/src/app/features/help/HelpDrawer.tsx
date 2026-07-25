import {
  Box,
  Divider,
  Drawer,
  IconButton,
  List,
  ListItem,
  Stack,
  Typography,
} from "@mui/material";
import CloseIcon from "@mui/icons-material/Close";
import HelpOutlineIcon from "@mui/icons-material/HelpOutline";

import { HELP_REGISTRY } from "./content/registry";

type HelpDrawerProps = {
  topicId: string;
  open: boolean;
  onClose: () => void;
};

/**
 * Panneau de lecture pour un topic d'aide. Ne connaît rien du contenu métier —
 * toute la substance vient de `HELP_REGISTRY[topicId]` (voir registry.ts).
 * Largeur pleine sur mobile, panneau latéral fixe sur desktop — même composant.
 */
export function HelpDrawer({ topicId, open, onClose }: HelpDrawerProps) {
  const topic = HELP_REGISTRY[topicId];

  return (
    <Drawer
      anchor="right"
      open={open}
      onClose={onClose}
      PaperProps={{ sx: { width: { xs: "100%", sm: 420 }, maxWidth: "100vw" } }}
    >
      {!topic ? (
        <Box sx={{ p: 3 }}>
          <Typography color="text.secondary">Aide indisponible pour cet écran.</Typography>
        </Box>
      ) : (
        <Box sx={{ display: "flex", flexDirection: "column", height: "100%" }}>
          <Stack
            direction="row"
            alignItems="flex-start"
            justifyContent="space-between"
            sx={{ p: 2.5, pb: 1.5 }}
          >
            <Stack direction="row" spacing={1.25} alignItems="flex-start">
              <HelpOutlineIcon sx={{ color: "primary.main", mt: 0.25 }} />
              <Box>
                <Typography variant="h6" fontWeight={700} lineHeight={1.2}>
                  {topic.title}
                </Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
                  {topic.intro}
                </Typography>
              </Box>
            </Stack>
            <IconButton size="small" onClick={onClose} aria-label="Fermer l'aide" sx={{ mt: -0.5, mr: -0.5 }}>
              <CloseIcon fontSize="small" />
            </IconButton>
          </Stack>

          <Divider />

          <Box sx={{ overflowY: "auto", flex: 1, p: 2.5, pt: 2 }}>
            <Stack spacing={2.5}>
              {topic.sections.map((section, idx) => (
                <Box key={idx}>
                  <Typography variant="subtitle2" fontWeight={700} sx={{ mb: 0.75 }}>
                    {section.heading}
                  </Typography>
                  {section.paragraphs?.map((p, i) => (
                    <Typography key={i} variant="body2" color="text.secondary" sx={{ mb: i < (section.paragraphs?.length ?? 0) - 1 ? 1 : 0 }}>
                      {p}
                    </Typography>
                  ))}
                  {section.bullets && (
                    <List dense disablePadding sx={{ listStyleType: "disc", pl: 2.5 }}>
                      {section.bullets.map((b, i) => (
                        <ListItem
                          key={i}
                          disablePadding
                          sx={{ display: "list-item", py: 0.25 }}
                        >
                          <Typography variant="body2" color="text.secondary">
                            {b}
                          </Typography>
                        </ListItem>
                      ))}
                    </List>
                  )}
                </Box>
              ))}
            </Stack>
          </Box>
        </Box>
      )}
    </Drawer>
  );
}

export default HelpDrawer;
