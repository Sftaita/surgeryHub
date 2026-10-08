import * as React from "react";
import { Link as RouterLink } from "react-router-dom";
import {
  Box,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  Link,
  Stack,
  Typography,
} from "@mui/material";

import {
  IGNORE_REASON_LABELS,
  formatRequestDateTime,
  statusColor,
  statusLabel,
  type CatalogueRequestRow,
} from "../catalogueRequestDisplay";

type Props = {
  row: CatalogueRequestRow | null;
  onClose: () => void;
  /** PENDING uniquement : ferme le détail et ouvre la modal de traitement existante. */
  onResolve: (row: CatalogueRequestRow) => void;
  onIgnore: (row: CatalogueRequestRow) => void;
};

const preWrapSx = { whiteSpace: "pre-wrap", overflowWrap: "anywhere" } as const;

function DetailRow({ term, children }: { term: string; children: React.ReactNode }) {
  return (
    <Box sx={{ display: "grid", gridTemplateColumns: { xs: "1fr", sm: "180px 1fr" }, columnGap: 2, rowGap: 0.25, py: 0.75 }}>
      <Typography component="dt" variant="body2" color="text.secondary" sx={{ fontWeight: 600 }}>
        {term}
      </Typography>
      <Typography component="dd" variant="body2" sx={{ m: 0, minWidth: 0 }}>
        {children}
      </Typography>
    </Box>
  );
}

function Missing({ children = "—" }: { children?: React.ReactNode }) {
  return <Box component="span" sx={{ color: "text.disabled", fontStyle: "italic" }}>{children}</Box>;
}

/** Ce que les données disent du traitement — jamais déduit ni complété côté client. */
function TreatmentSection({ row }: { row: CatalogueRequestRow }) {
  const { request } = row;
  const decidedAt = formatRequestDateTime(request.decidedAt);
  const decider = request.decidedBy ? (
    <>par {request.decidedBy.displayName}{decidedAt ? ` le ${decidedAt}` : ""}</>
  ) : decidedAt ? <>le {decidedAt}</> : null;

  if (request.status === "PENDING") {
    return <DetailRow term="Traitement">En attente de traitement par un manager.</DetailRow>;
  }

  if (request.status === "RESOLVED") {
    const resolvedTo = row.kind === "material"
      ? row.request.materialItem
        ? `${row.request.materialItem.label}${row.request.materialItem.referenceCode ? ` · ${row.request.materialItem.referenceCode}` : ""}`
        : null
      : row.request.resolvedInterventionType
        ? `${row.request.resolvedInterventionType.label} (${row.request.resolvedInterventionType.code})`
        : null;
    return (
      <>
        <DetailRow term="Traitement">Résolue{decider ? <> {decider}</> : null}</DetailRow>
        <DetailRow term={row.kind === "material" ? "Produit associé" : "Type d'intervention associé"}>
          {resolvedTo ?? <Missing />}
        </DetailRow>
      </>
    );
  }

  return (
    <>
      <DetailRow term="Traitement">Ignorée{decider ? <> {decider}</> : null}</DetailRow>
      <DetailRow term="Motif">
        {request.ignoreReason ? IGNORE_REASON_LABELS[request.ignoreReason] : <Missing />}
      </DetailRow>
      <DetailRow term="Explication du manager">
        {request.ignoreComment
          ? <Box component="span" sx={{ display: "block", ...preWrapSx }}>{request.ignoreComment}</Box>
          : <Missing />}
      </DetailRow>
    </>
  );
}

/**
 * Consultation d'une demande catalogue (matériel ou type d'intervention) depuis
 * Manager > Demandes, quel que soit son statut. Lecture seule : l'ouverture et la
 * fermeture n'appellent aucun endpoint — toutes les données viennent de la liste déjà
 * chargée. Les actions de traitement (PENDING) ne font qu'ouvrir les modals existantes.
 */
export function CatalogueRequestDetailDialog({ row, onClose, onResolve, onIgnore }: Props) {
  if (!row) return null;
  const { request } = row;
  const isMaterial = row.kind === "material";
  const reference = isMaterial ? row.request.referenceCode : row.request.suggestedCode;

  return (
    <Dialog open onClose={onClose} fullWidth maxWidth="sm" aria-labelledby="catalogue-request-detail-title">
      <DialogTitle id="catalogue-request-detail-title" sx={{ pb: 1 }}>
        <Typography variant="overline" color="text.secondary" component="div" sx={{ lineHeight: 1.6 }}>
          Détail de la demande
        </Typography>
        <Box component="span" sx={{ display: "block", ...preWrapSx }}>{request.label}</Box>
        <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
          <Chip size="small" variant="outlined" label={isMaterial ? "Matériel" : "Intervention"} color={isMaterial ? "default" : "primary"} />
          <Chip size="small" variant="outlined" label={statusLabel(request.status)} color={statusColor(request.status)} />
        </Stack>
      </DialogTitle>

      <DialogContent dividers>
        <Box component="dl" sx={{ m: 0 }}>
          <DetailRow term="Type de demande">
            {isMaterial ? "Matériel" : "Nouveau type d'intervention"}
          </DetailRow>
          <DetailRow term="Nom demandé">
            <Box component="span" sx={preWrapSx}>{request.label}</Box>
          </DetailRow>
          <DetailRow term={isMaterial ? "Référence" : "Code suggéré"}>
            {reference || <Missing />}
          </DetailRow>
          <DetailRow term="Instrumentiste">
            {request.requestedBy?.displayName || <Missing />}
          </DetailRow>
          <DetailRow term="Mission">
            {request.mission ? (
              <Link component={RouterLink} to={`/app/m/missions/${request.mission.id}`}>
                Mission #{request.mission.id}
              </Link>
            ) : <Missing />}
          </DetailRow>
          <DetailRow term="Site">{request.mission?.site || <Missing />}</DetailRow>
          <DetailRow term="Date de la demande">
            {formatRequestDateTime(request.createdAt) ?? <Missing />}
          </DetailRow>
        </Box>

        <Box
          sx={{ mt: 1.5, p: 1.5, borderRadius: 1, bgcolor: "action.hover" }}
          role="region"
          aria-label="Commentaire de l'instrumentiste"
        >
          <Typography variant="body2" sx={{ fontWeight: 600, mb: 0.5 }}>
            Commentaire de l'instrumentiste
          </Typography>
          {request.comment ? (
            <Typography variant="body2" component="div" sx={preWrapSx} data-testid="catalogue-request-detail-comment">
              {request.comment}
            </Typography>
          ) : (
            <Typography variant="body2" sx={{ color: "text.disabled", fontStyle: "italic" }}>
              Aucun commentaire
            </Typography>
          )}
        </Box>

        <Divider sx={{ my: 1.5 }} />

        <Box component="dl" sx={{ m: 0 }}>
          <DetailRow term="Statut actuel">{statusLabel(request.status)}</DetailRow>
          <TreatmentSection row={row} />
        </Box>
      </DialogContent>

      <DialogActions>
        <Button onClick={onClose}>Fermer</Button>
        {request.status === "PENDING" ? (
          <>
            <Box sx={{ flex: 1 }} />
            <Button color="inherit" variant="outlined" onClick={() => onIgnore(row)}>Ignorer</Button>
            <Button variant="contained" onClick={() => onResolve(row)}>
              {isMaterial ? "Créer produit" : "Résoudre"}
            </Button>
          </>
        ) : null}
      </DialogActions>
    </Dialog>
  );
}
