import * as React from "react";
import { Box, Button, Typography } from "@mui/material";

/**
 * Commentaire libre saisi par l'instrumentiste à la création d'une demande catalogue
 * (`MaterialItemRequest.comment` / `InterventionTypeRequest.comment`) — affiché tel que
 * renvoyé par le backend, jamais réécrit ni complété côté client.
 */

/** Au-delà, la ligne du tableau n'affiche qu'un aperçu (2 lignes) + « Voir tout ». */
const PREVIEW_MAX_CHARS = 140;
const PREVIEW_MAX_LINES = 2;

export function isLongComment(comment: string): boolean {
  return comment.length > PREVIEW_MAX_CHARS || comment.split("\n").length > PREVIEW_MAX_LINES;
}

const commentTextSx = { whiteSpace: "pre-wrap", overflowWrap: "anywhere" } as const;

/**
 * Commentaire dans une ligne du tableau Demandes. Court : affiché en entier. Long :
 * aperçu replié sur 2 lignes, dépliable sur place — le texte complet reste toujours dans
 * le DOM (le repli est purement visuel), jamais tronqué définitivement.
 */
export function RequesterCommentPreview({ comment }: { comment: string }) {
  const [expanded, setExpanded] = React.useState(false);
  const long = isLongComment(comment);
  const collapsed = long && !expanded;

  return (
    <Box sx={{ mt: 0.5, pl: 1, borderLeft: 2, borderColor: "divider" }}>
      <Typography
        variant="caption"
        component="div"
        color="text.secondary"
        sx={{
          ...commentTextSx,
          ...(collapsed
            ? { display: "-webkit-box", WebkitLineClamp: PREVIEW_MAX_LINES, WebkitBoxOrient: "vertical", overflow: "hidden" }
            : null),
        }}
      >
        <Box component="span" sx={{ fontWeight: 600, color: "text.primary" }}>Commentaire : </Box>
        <span>{comment}</span>
      </Typography>
      {long ? (
        <Button
          size="small"
          variant="text"
          aria-expanded={expanded}
          onClick={() => setExpanded((v) => !v)}
          sx={{ p: 0, minWidth: 0, fontSize: 12, textTransform: "none" }}
        >
          {expanded ? "Réduire" : "Voir tout le commentaire"}
        </Button>
      ) : null}
    </Box>
  );
}

type RequestContextProps = {
  kind: "material" | "intervention";
  label: string;
  reference: string | null;
  requestedBy: { displayName: string } | null;
  mission: { id: number; site: string | null } | null;
  comment: string | null;
};

function ContextRow({ term, children }: { term: string; children: React.ReactNode }) {
  return (
    <>
      <Typography component="dt" variant="caption" color="text.secondary" sx={{ fontWeight: 600 }}>
        {term}
      </Typography>
      <Typography component="dd" variant="body2" sx={{ m: 0, mb: 1, "&:last-of-type": { mb: 0 } }}>
        {children}
      </Typography>
    </>
  );
}

/**
 * Récapitulatif de la demande en tête des modals de traitement (résolution / ignore) :
 * le manager décide avec le contexte complet sous les yeux, commentaire intégral inclus.
 */
export function CatalogueRequestContext({ kind, label, reference, requestedBy, mission, comment }: RequestContextProps) {
  return (
    <Box
      component="dl"
      aria-label="Détail de la demande"
      sx={{ m: 0, p: 1.5, borderRadius: 1, bgcolor: "action.hover" }}
    >
      <ContextRow term={kind === "material" ? "Matériel demandé" : "Intervention demandée"}>
        {label}
        {reference ? (
          <Box component="span" sx={{ color: "text.secondary" }}> · {reference}</Box>
        ) : null}
      </ContextRow>
      <ContextRow term="Demandé par">{requestedBy?.displayName ?? "—"}</ContextRow>
      <ContextRow term="Mission">
        {mission ? `#${mission.id}${mission.site ? ` · ${mission.site}` : ""}` : "—"}
      </ContextRow>
      <ContextRow term="Commentaire de l'instrumentiste">
        {comment ? (
          <Box component="span" sx={{ display: "block", ...commentTextSx }}>{comment}</Box>
        ) : (
          <Box component="span" sx={{ color: "text.disabled", fontStyle: "italic" }}>Aucun commentaire</Box>
        )}
      </ContextRow>
    </Box>
  );
}
