import * as React from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert, Box, Button, Chip, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogTitle, Stack, Tab, TextField, Table, TableBody, TableCell, TableContainer,
  TableHead, TableRow, Tabs, Typography,
} from "@mui/material";

import {
  acceptSurgeonMissionRequest,
  getSurgeonMissionRequests,
  rejectSurgeonMissionRequest,
} from "./api/managerSurgeonMissionRequests.api";
import type { ManagerSurgeonMissionRequest } from "./api/managerSurgeonMissionRequests.types";
import { formatBrusselsRange, formatMissionType } from "../missions/utils/missions.format";
import { useToast } from "../../ui/toast/useToast";
import { EmptyState } from "../../ui/EmptyState";

type TabValue = "PENDING" | "ACCEPTED" | "REJECTED";

function statusLabel(status: TabValue): string {
  switch (status) {
    case "PENDING": return "En attente";
    case "ACCEPTED": return "Acceptée";
    case "REJECTED": return "Refusée";
  }
}

function statusColor(status: TabValue): "warning" | "success" | "default" {
  switch (status) {
    case "PENDING": return "warning";
    case "ACCEPTED": return "success";
    case "REJECTED": return "default";
  }
}

// §9 — résumé en lecture seule + revue légère (accepter/refuser + commentaire), pas un
// second formulaire complet de création de mission.
function AcceptDialog({
  open, request, onClose, onAccept, accepting,
}: {
  open: boolean;
  request: ManagerSurgeonMissionRequest | null;
  onClose: () => void;
  onAccept: (reviewComment?: string) => void;
  accepting: boolean;
}) {
  const [comment, setComment] = React.useState("");

  React.useEffect(() => {
    if (open) setComment("");
  }, [open]);

  if (!request) return null;

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle>Demande du Dr {request.surgeon?.displayName ?? "—"}</DialogTitle>
      <DialogContent>
        <Stack spacing={1.5} sx={{ pt: 0.5 }}>
          <Typography variant="body2">
            {request.site?.name ?? "—"} · {formatBrusselsRange(request.startAt, request.endAt)} · {formatMissionType(request.type)}
          </Typography>
          {request.comment && (
            <Typography variant="body2" color="text.secondary">« {request.comment} »</Typography>
          )}
          <TextField
            label="Commentaire (optionnel)"
            size="small"
            multiline
            minRows={2}
            value={comment}
            onChange={(e) => setComment(e.target.value)}
          />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={accepting}>Annuler</Button>
        <Button variant="contained" disabled={accepting} onClick={() => onAccept(comment.trim() || undefined)}>
          {accepting ? <CircularProgress size={18} /> : "Accepter et créer"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

function RejectDialog({
  open, request, onClose, onReject, rejecting,
}: {
  open: boolean;
  request: ManagerSurgeonMissionRequest | null;
  onClose: () => void;
  onReject: (reviewComment: string) => void;
  rejecting: boolean;
}) {
  const [comment, setComment] = React.useState("");

  React.useEffect(() => {
    if (open) setComment("");
  }, [open]);

  if (!request) return null;
  const canSubmit = comment.trim().length > 0;

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle>Refuser la demande</DialogTitle>
      <DialogContent>
        <Stack spacing={1.5} sx={{ pt: 0.5 }}>
          <Typography variant="body2" color="text.secondary">
            Dr {request.surgeon?.displayName ?? "—"} — {request.site?.name ?? "—"} · {formatBrusselsRange(request.startAt, request.endAt)}
          </Typography>
          <TextField
            label="Motif du refus *"
            size="small"
            multiline
            minRows={2}
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            placeholder="Ex. bloc déjà complet à cette date"
            required
          />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={rejecting}>Annuler</Button>
        <Button variant="contained" color="error" disabled={rejecting || !canSubmit} onClick={() => onReject(comment.trim())}>
          {rejecting ? <CircularProgress size={18} /> : "Refuser"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

/**
 * Revue manager des demandes de mission chirurgien (Lot 5, D-099, §8/§20) — onglet
 * contextuel de MissionsListPage plutôt qu'une nouvelle section top-level, même
 * principe de placement que Catalogue > Demandes mais domaine distinct (planning, pas
 * catalogue) : jamais mélangé avec CatalogueRequestsPage.
 */
export default function SurgeonMissionRequestsPanel() {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [tab, setTab] = React.useState<TabValue>("PENDING");
  const [acceptTarget, setAcceptTarget] = React.useState<ManagerSurgeonMissionRequest | null>(null);
  const [rejectTarget, setRejectTarget] = React.useState<ManagerSurgeonMissionRequest | null>(null);

  const requestsQuery = useQuery({
    queryKey: ["surgeon-mission-requests-manager", tab],
    queryFn: () => getSurgeonMissionRequests(tab),
  });

  const acceptMutation = useMutation({
    mutationFn: ({ id, reviewComment }: { id: number; reviewComment?: string }) => acceptSurgeonMissionRequest(id, reviewComment),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["surgeon-mission-requests-manager"] });
      queryClient.invalidateQueries({ queryKey: ["missions"] });
      setAcceptTarget(null);
      toast.success("Demande acceptée. Mission créée.");
    },
    onError: (err: any) => {
      const code = err?.response?.data?.error?.code;
      const msg = code === "SURGEON_MISSION_REQUEST_CONFLICT"
        ? "Le chirurgien a déjà une autre mission active sur cette période."
        : err?.response?.data?.error?.message ?? "Erreur lors de l'acceptation.";
      toast.error(msg);
    },
  });

  const rejectMutation = useMutation({
    mutationFn: ({ id, reviewComment }: { id: number; reviewComment: string }) => rejectSurgeonMissionRequest(id, reviewComment),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["surgeon-mission-requests-manager"] });
      setRejectTarget(null);
      toast.success("Demande refusée.");
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.error?.message ?? "Erreur lors du refus.");
    },
  });

  const requests = requestsQuery.data?.items ?? [];

  return (
    <Box>
      <Tabs
        value={tab}
        onChange={(_, v: TabValue) => setTab(v)}
        sx={{ mb: 2, borderBottom: 1, borderColor: "divider", minHeight: 40 }}
      >
        <Tab label="En attente" value="PENDING" sx={{ minHeight: 40 }} />
        <Tab label="Acceptées" value="ACCEPTED" sx={{ minHeight: 40 }} />
        <Tab label="Refusées" value="REJECTED" sx={{ minHeight: 40 }} />
      </Tabs>

      {requestsQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 4 }}>
          <CircularProgress size={28} />
        </Box>
      ) : requestsQuery.isError ? (
        <Alert severity="error">Impossible de charger les demandes.</Alert>
      ) : requests.length === 0 ? (
        <EmptyState title="Aucune demande." />
      ) : (
        <TableContainer>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Chirurgien</TableCell>
                <TableCell>Site</TableCell>
                <TableCell>Date / heure</TableCell>
                <TableCell>Type</TableCell>
                <TableCell>Commentaire</TableCell>
                <TableCell>Statut</TableCell>
                {tab === "PENDING" ? <TableCell align="right">Actions</TableCell> : null}
              </TableRow>
            </TableHead>
            <TableBody>
              {requests.map((r) => (
                <TableRow key={r.id} hover>
                  <TableCell>Dr {r.surgeon?.displayName ?? "—"}</TableCell>
                  <TableCell>{r.site?.name ?? "—"}</TableCell>
                  <TableCell>{formatBrusselsRange(r.startAt, r.endAt)}</TableCell>
                  <TableCell>{formatMissionType(r.type)}</TableCell>
                  <TableCell>
                    <Typography variant="body2" color="text.secondary" noWrap sx={{ maxWidth: 220 }}>
                      {tab === "REJECTED" ? (r.reviewComment ?? "—") : (r.comment ?? "—")}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <Chip label={statusLabel(r.status)} color={statusColor(r.status)} size="small" variant="outlined" />
                  </TableCell>
                  {tab === "PENDING" ? (
                    <TableCell align="right">
                      <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                        <Button size="small" variant="contained" onClick={() => setAcceptTarget(r)}>
                          Accepter
                        </Button>
                        <Button size="small" color="inherit" variant="outlined" onClick={() => setRejectTarget(r)}>
                          Refuser
                        </Button>
                      </Stack>
                    </TableCell>
                  ) : null}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      <AcceptDialog
        open={acceptTarget !== null}
        request={acceptTarget}
        onClose={() => setAcceptTarget(null)}
        accepting={acceptMutation.isPending}
        onAccept={(reviewComment) => {
          if (acceptTarget) acceptMutation.mutate({ id: acceptTarget.id, reviewComment });
        }}
      />
      <RejectDialog
        open={rejectTarget !== null}
        request={rejectTarget}
        onClose={() => setRejectTarget(null)}
        rejecting={rejectMutation.isPending}
        onReject={(reviewComment) => {
          if (rejectTarget) rejectMutation.mutate({ id: rejectTarget.id, reviewComment });
        }}
      />
    </Box>
  );
}
