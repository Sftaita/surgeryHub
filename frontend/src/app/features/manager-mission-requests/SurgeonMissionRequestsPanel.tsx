import * as React from "react";
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert, Box, Button, Chip, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogTitle, Link, Stack, Tab, TextField, Table, TableBody, TableCell, TableContainer,
  TableHead, TableRow, Tabs, Typography,
} from "@mui/material";
import HourglassEmptyOutlinedIcon from "@mui/icons-material/HourglassEmptyOutlined";
import CheckCircleOutlineIcon from "@mui/icons-material/CheckCircleOutline";
import HighlightOffIcon from "@mui/icons-material/HighlightOff";
import { Link as RouterLink } from "react-router-dom";

import {
  acceptSurgeonMissionRequest,
  getSurgeonMissionRequestCounts,
  getSurgeonMissionRequests,
  rejectSurgeonMissionRequest,
} from "./api/managerSurgeonMissionRequests.api";
import {
  REQUESTS_COUNTS_KEY,
  REQUESTS_HISTORY_KEY,
  REQUESTS_LIST_KEY,
  applyOptimisticReview,
  rollbackOptimisticReview,
  settleReview,
} from "./requestReviewOptimistic";
import { fetchSurgeonMissionRequestHistory, nextAuditPage } from "../audit-journal/api/auditJournal.api";
import AuditTimeline from "../audit-journal/components/AuditTimeline";
import StatCard from "../../ui/StatCard";
import type { ManagerSurgeonMissionRequest } from "./api/managerSurgeonMissionRequests.types";
import { formatBrusselsRange, formatMissionType } from "../missions/utils/missions.format";
import { useToast } from "../../ui/toast/useToast";
import { EmptyState } from "../../ui/EmptyState";
import MissionDispatchFields from "../missions/dispatch/MissionDispatchFields";
import {
  DEFAULT_DISPATCH_CHOICE,
  invalidateOperationalPlanning,
  isDispatchChoiceComplete,
  type MissionDispatchChoice,
} from "../missions/dispatch/missionDispatch.api";

type StatusValue = "PENDING" | "ACCEPTED" | "REJECTED";
type TabValue = StatusValue | "HISTORY";

/** D-147 — les demandes arrivent sans action du manager : rafraîchies en tâche de fond. */
const LIVE_REFRESH_MS = 30_000;

function statusLabel(status: StatusValue): string {
  switch (status) {
    case "PENDING": return "En attente";
    case "ACCEPTED": return "Acceptée";
    case "REJECTED": return "Refusée";
  }
}

function statusColor(status: StatusValue):"warning" | "success" | "default" {
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
  onAccept: (reviewComment: string | undefined, dispatch: MissionDispatchChoice) => void;
  accepting: boolean;
}) {
  const [comment, setComment] = React.useState("");
  const [dispatch, setDispatch] = React.useState<MissionDispatchChoice>(DEFAULT_DISPATCH_CHOICE);

  React.useEffect(() => {
    if (open) {
      setComment("");
      setDispatch(DEFAULT_DISPATCH_CHOICE);
    }
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
          {/* D-125 — même choix que pour une mission créée manuellement. */}
          <Typography variant="subtitle2">Mission créée</Typography>
          <MissionDispatchFields
            value={dispatch}
            onChange={setDispatch}
            slot={request.site ? { siteId: request.site.id, startAt: request.startAt, endAt: request.endAt } : null}
            disabled={accepting}
          />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={accepting}>Annuler</Button>
        <Button
          variant="contained"
          disabled={accepting || !isDispatchChoiceComplete(dispatch)}
          onClick={() => onAccept(comment.trim() || undefined, dispatch)}
        >
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

  // D-147 — même clé que le badge de l'onglet « Demandes chirurgien » (MissionsListPage) :
  // cartes, onglets et badge bougent ensemble, sans rechargement de page.
  const countsQuery = useQuery({
    queryKey: REQUESTS_COUNTS_KEY,
    queryFn: getSurgeonMissionRequestCounts,
    refetchInterval: LIVE_REFRESH_MS,
  });
  const counts = countsQuery.data;

  const requestsQuery = useQuery({
    queryKey: [...REQUESTS_LIST_KEY, tab],
    queryFn: () => getSurgeonMissionRequests(tab),
    enabled: tab !== "HISTORY",
    refetchInterval: LIVE_REFRESH_MS,
  });

  const historyQuery = useInfiniteQuery({
    queryKey: REQUESTS_HISTORY_KEY,
    queryFn: ({ pageParam }) => fetchSurgeonMissionRequestHistory(pageParam),
    initialPageParam: 1,
    getNextPageParam: nextAuditPage,
    enabled: tab === "HISTORY",
    refetchInterval: LIVE_REFRESH_MS,
  });

  // D-147 — optimiste : le dialogue se ferme et la demande quitte « En attente » dès le
  // clic ; le toast de succès n'arrive qu'avec la confirmation serveur, l'erreur remet
  // la demande à sa place (voir requestReviewOptimistic.ts).
  const acceptMutation = useMutation({
    mutationFn: ({ request, reviewComment, dispatch }: { request: ManagerSurgeonMissionRequest; reviewComment?: string; dispatch: MissionDispatchChoice }) =>
      acceptSurgeonMissionRequest(request.id, reviewComment, dispatch),
    onMutate: ({ request }) => {
      setAcceptTarget(null);
      return applyOptimisticReview(queryClient, request, "ACCEPTED");
    },
    onSuccess: (accepted, { dispatch }) => {
      // D-125 — the created mission is immediately part of the operational planning.
      invalidateOperationalPlanning(queryClient, accepted.createdMissionId);
      toast.success(
        dispatch.mode === "DIRECT"
          ? "Demande acceptée. Mission créée et attribuée."
          : dispatch.mode === "TARGETED"
            ? "Demande acceptée. Mission créée — demande envoyée à l'instrumentiste."
            : "Demande acceptée. Mission créée et proposée au pool.",
      );
    },
    onError: (err: any, { request }, context) => {
      rollbackOptimisticReview(queryClient, request, "ACCEPTED", context);
      const code = err?.response?.data?.error?.code;
      const msg = code === "SURGEON_MISSION_REQUEST_CONFLICT"
        ? "Le chirurgien a déjà une autre mission active sur cette période."
        : err?.response?.data?.error?.message ?? "Erreur lors de l'acceptation.";
      toast.error(msg);
      // An ineligibility refusal leaves the request PENDING — refresh candidates' state.
      if (code === "INSTRUMENTIST_INCOMPATIBLE") {
        queryClient.invalidateQueries({ queryKey: ["dispatch-candidates"] });
      }
    },
    onSettled: () => settleReview(queryClient),
  });

  const rejectMutation = useMutation({
    mutationFn: ({ request, reviewComment }: { request: ManagerSurgeonMissionRequest; reviewComment: string }) =>
      rejectSurgeonMissionRequest(request.id, reviewComment),
    onMutate: ({ request }) => {
      setRejectTarget(null);
      return applyOptimisticReview(queryClient, request, "REJECTED");
    },
    onSuccess: () => {
      toast.success("Demande refusée.");
    },
    onError: (err: any, { request }, context) => {
      rollbackOptimisticReview(queryClient, request, "REJECTED", context);
      toast.error(err?.response?.data?.error?.message ?? "Erreur lors du refus.");
    },
    onSettled: () => settleReview(queryClient),
  });

  const requests = requestsQuery.data?.items ?? [];
  const historyEntries = historyQuery.data?.pages.flatMap((p) => p.items) ?? [];
  const historyTotal = historyQuery.data?.pages[0]?.total ?? 0;
  const countLabel = (status: StatusValue) => (counts ? ` (${counts[status]})` : "");

  return (
    <Box>
      <Box sx={{ display: "grid", gridTemplateColumns: { xs: "1fr", sm: "repeat(3, 1fr)" }, gap: 1.5, mb: 2 }}>
        <StatCard
          label="En attente"
          value={counts?.PENDING ?? "—"}
          color={counts?.PENDING ? "warning.main" : undefined}
          hint={counts?.PENDING ? "À traiter" : undefined}
          icon={HourglassEmptyOutlinedIcon}
          onClick={() => setTab("PENDING")}
        />
        <StatCard label="Acceptées" value={counts?.ACCEPTED ?? "—"} color="success.main" icon={CheckCircleOutlineIcon} onClick={() => setTab("ACCEPTED")} />
        <StatCard label="Refusées" value={counts?.REJECTED ?? "—"} icon={HighlightOffIcon} onClick={() => setTab("REJECTED")} />
      </Box>

      <Tabs
        value={tab}
        onChange={(_, v: TabValue) => setTab(v)}
        sx={{ mb: 2, borderBottom: 1, borderColor: "divider", minHeight: 40 }}
      >
        <Tab label={`En attente${countLabel("PENDING")}`} value="PENDING" sx={{ minHeight: 40 }} />
        <Tab label={`Acceptées${countLabel("ACCEPTED")}`} value="ACCEPTED" sx={{ minHeight: 40 }} />
        <Tab label={`Refusées${countLabel("REJECTED")}`} value="REJECTED" sx={{ minHeight: 40 }} />
        <Tab label="Historique" value="HISTORY" sx={{ minHeight: 40 }} />
      </Tabs>

      {tab === "HISTORY" ? (
        historyQuery.isLoading ? (
          <Box sx={{ display: "flex", justifyContent: "center", py: 4 }}>
            <CircularProgress size={28} />
          </Box>
        ) : historyQuery.isError ? (
          <Alert severity="error">Impossible de charger l'historique.</Alert>
        ) : historyEntries.length === 0 ? (
          <EmptyState title="Aucune action pour l'instant." />
        ) : (
          <AuditTimeline
            entries={historyEntries}
            total={historyTotal}
            hasMore={historyQuery.hasNextPage}
            loadingMore={historyQuery.isFetchingNextPage}
            onLoadMore={() => historyQuery.fetchNextPage()}
          />
        )
      ) : requestsQuery.isLoading ? (
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
                {tab !== "PENDING" ? <TableCell>Traitée par</TableCell> : null}
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
                  {tab !== "PENDING" ? (
                    <TableCell>
                      <Typography variant="body2">{r.reviewedBy?.displayName ?? "—"}</Typography>
                      {r.reviewedAt && (
                        <Typography variant="caption" color="text.secondary">
                          {new Date(r.reviewedAt).toLocaleString("fr-BE", { timeZone: "Europe/Brussels", dateStyle: "short", timeStyle: "short" })}
                        </Typography>
                      )}
                    </TableCell>
                  ) : null}
                  <TableCell>
                    <Stack direction="row" spacing={0.75} alignItems="center">
                      <Chip label={statusLabel(r.status)} color={statusColor(r.status)} size="small" variant="outlined" />
                      {r.createdMissionId ? (
                        <Link component={RouterLink} to={`/app/m/missions/${r.createdMissionId}`} variant="body2" underline="hover">
                          Mission #{r.createdMissionId}
                        </Link>
                      ) : null}
                    </Stack>
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
        accepting={false}
        onAccept={(reviewComment, dispatch) => {
          if (acceptTarget) acceptMutation.mutate({ request: acceptTarget, reviewComment, dispatch });
        }}
      />
      <RejectDialog
        open={rejectTarget !== null}
        request={rejectTarget}
        onClose={() => setRejectTarget(null)}
        rejecting={false}
        onReject={(reviewComment) => {
          if (rejectTarget) rejectMutation.mutate({ request: rejectTarget, reviewComment });
        }}
      />
    </Box>
  );
}
