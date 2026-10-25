import * as React from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  TextField,
  Typography,
} from "@mui/material";

import {
  approveDeclaredMission,
  cancelMission,
  cancelExecutedWithoutInstrumentist,
  declareExecutedWithoutInstrumentist,
  rejectDeclaredMission,
} from "../api/missions.api";
import type { Mission } from "../api/missions.types";
import {
  fetchMissionEncoding,
  rejectMissionEncoding,
  reopenMissionEncoding,
  validateMissionEncoding,
} from "../../encoding/api/encoding.api";
import InterventionsSection from "../../encoding/components/InterventionsSection";
import { reassignMission } from "../../planning-v2/api/planningV2.api";
import { ReassignMissionDialog } from "../../planning-v2/components/ReassignMissionDialog";
import EditServiceHoursDialog from "../components/EditServiceHoursDialog";
import { encodingIncompleteText } from "../../encoding/api/encodingIncomplete";
import EditMissionDialog from "../components/EditMissionDialog";
import PublishMissionDialog from "../components/PublishMissionDialog";
import { useToast } from "../../../ui/toast/useToast";

/**
 * Actions manager d'une mission — tonalité visuelle seulement : la page détail la traduit
 * en bouton MUI, le tiroir de la liste Missions en bouton de pied (`.mdw-btn`).
 */
export type MissionActionTone = "neutral" | "warning" | "danger" | "success" | "primary";

export interface MissionActionDescriptor {
  key:
    | "declareWithoutInstrumentist"
    | "cancelDeclaration"
    | "cancel"
    | "reject"
    | "approve"
    | "rejectEncoding"
    | "validateEncoding"
    | "reopenEncoding"
    | "edit"
    | "publish"
    | "reassign"
    | "editHours"
    | "editEncoding";
  label: string;
  tone: MissionActionTone;
  contained: boolean;
  disabled: boolean;
  testId?: string;
  onClick: () => void;
}

/**
 * Mutations + fenêtres de confirmation des actions manager d'une mission, pour le tiroir
 * ManagerMissionDrawer. `onLeave` : l'action fait sortir la mission de la vue
 * courante (approuvée, rejetée, annulée) — le tiroir se ferme. `corrections` : changer
 * l'instrumentiste (reassign), corriger les heures et l'encodage (D-153 edit_hours /
 * edit_encoding), mêmes fenêtres que le planning vivant et le Suivi des encodages. Chaque action n'est
 * proposée que si `allowedActions` la contient — le backend seul décide (MissionActionsService).
 */
export function useMissionManagerActions(
  missionId: number,
  data: Mission | undefined,
  { onLeave }: { onLeave: () => void },
) {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [openEdit, setOpenEdit] = React.useState(false);
  const [openPublish, setOpenPublish] = React.useState(false);
  const [rejectConfirmOpen, setRejectConfirmOpen] = React.useState(false);
  const [approveConfirmOpen, setApproveConfirmOpen] = React.useState(false);
  const [validateConfirmOpen, setValidateConfirmOpen] = React.useState(false);
  const [rejectEncodingOpen, setRejectEncodingOpen] = React.useState(false);
  const [rejectEncodingComment, setRejectEncodingComment] = React.useState("");
  const [reopenOpen, setReopenOpen] = React.useState(false);
  const [reopenComment, setReopenComment] = React.useState("");
  const [cancelConfirmOpen, setCancelConfirmOpen] = React.useState(false);
  const [cancelReason, setCancelReason] = React.useState("");
  // D-155 — activité exécutée sans instrumentiste : déclarer / annuler une déclaration erronée.
  const [declareOpen, setDeclareOpen] = React.useState(false);
  const [cancelDeclarationOpen, setCancelDeclarationOpen] = React.useState(false);
  const [cancelDeclarationReason, setCancelDeclarationReason] = React.useState("");
  // Corrections ponctuelles servies par le backend (reassign, D-153 edit_hours / edit_encoding).
  const [reassignOpen, setReassignOpen] = React.useState(false);
  const [hoursOpen, setHoursOpen] = React.useState(false);
  const [encodingEditOpen, setEncodingEditOpen] = React.useState(false);

  async function refreshAfterAction() {
    await queryClient.invalidateQueries({ queryKey: ["mission", missionId] });
    await queryClient.invalidateQueries({ queryKey: ["missionEncoding", missionId] });
    await queryClient.invalidateQueries({ queryKey: ["mission-audit", missionId] });
    await queryClient.invalidateQueries({ queryKey: ["mission-execution", missionId] });
    await queryClient.invalidateQueries({ queryKey: ["mission-financial-calculations", missionId] });
    await queryClient.invalidateQueries({ queryKey: ["missions"], exact: false });
    await queryClient.refetchQueries({ queryKey: ["missions"], exact: false });
    // D-121 — validate/reject/reopen changent tous le nombre de missions SUBMITTED :
    // le badge de nav doit refléter l'action immédiatement, pas seulement au prochain
    // polling (60s, voir useNavBadgeCount).
    await queryClient.invalidateQueries({ queryKey: ["encoding-tracking", "pending-validation-count"] });
  }

  const approveMutation = useMutation({
    mutationFn: async () => approveDeclaredMission(missionId),
    onSuccess: async () => {
      await refreshAfterAction();
      toast.success("Mission approuvée.");
      onLeave();
    },
    onError: (err: any) => {
      toast.error(err?.response?.status === 403 ? "Accès interdit." : "Erreur lors de l'approbation.");
    },
  });

  const rejectMutation = useMutation({
    mutationFn: async () => rejectDeclaredMission(missionId),
    onSuccess: async () => {
      await refreshAfterAction();
      toast.success("Mission rejetée.");
      onLeave();
    },
    onError: (err: any) => {
      toast.error(err?.response?.status === 403 ? "Accès interdit." : "Erreur lors du rejet.");
    },
  });

  /** Lot 7 (D-070) — validate/reject/reopen de l'encodage, distincts d'approve/reject
   *  ci-dessus qui ne concernent que le statut DECLARED. */
  const validateEncodingMutation = useMutation({
    mutationFn: async () => validateMissionEncoding(missionId),
    onSuccess: async () => {
      await refreshAfterAction();
      toast.success("Encodage validé.");
    },
    onError: (err: any) => {
      toast.error(err?.response?.status === 403 ? "Accès interdit." : encodingIncompleteText(err) ?? "Erreur lors de la validation.");
    },
  });

  const rejectEncodingMutation = useMutation({
    mutationFn: async (comment: string) => rejectMissionEncoding(missionId, comment),
    onSuccess: async () => {
      await refreshAfterAction();
      toast.success("Encodage rejeté, renvoyé à l'instrumentiste.");
      setRejectEncodingComment("");
    },
    onError: (err: any) => {
      toast.error(err?.response?.status === 403 ? "Accès interdit." : "Erreur lors du rejet de l'encodage.");
    },
  });

  const reopenEncodingMutation = useMutation({
    mutationFn: async (comment: string) => reopenMissionEncoding(missionId, comment),
    onSuccess: async () => {
      await refreshAfterAction();
      toast.success("Encodage rouvert.");
      setReopenComment("");
    },
    onError: (err: any) => {
      toast.error(err?.response?.status === 403 ? "Accès interdit." : "Erreur lors de la réouverture.");
    },
  });

  /**
   * Point 5 (audit UX manager) — annulation, jamais une suppression physique (D-055/
   * D-056 : historique auditable). Couvre DRAFT/OPEN/ASSIGNED, toujours conditionné à
   * allowedActions.includes("cancel") — voir MissionActionsService côté backend.
   */
  const cancelMutation = useMutation({
    mutationFn: async (reason: string) => cancelMission(missionId, reason),
    onSuccess: async () => {
      await refreshAfterAction();
      toast.success("Mission annulée.");
      setCancelReason("");
      onLeave();
    },
    onError: (err: any) => {
      toast.error(err?.response?.status === 403 ? "Accès interdit." : "Erreur lors de l'annulation.");
    },
  });

  const declareMutation = useMutation({
    mutationFn: async () => declareExecutedWithoutInstrumentist(missionId),
    onSuccess: async () => {
      await refreshAfterAction();
      setDeclareOpen(false);
      toast.success("Activité déclarée réalisée sans instrumentiste.");
    },
    onError: (err: any) => toast.error(err?.response?.data?.error?.message ?? (err?.response?.status === 403 ? "Accès interdit." : "Déclaration impossible.")),
  });

  const cancelDeclarationMutation = useMutation({
    mutationFn: async (reason: string) => cancelExecutedWithoutInstrumentist(missionId, reason),
    onSuccess: async () => {
      await refreshAfterAction();
      setCancelDeclarationOpen(false);
      setCancelDeclarationReason("");
      toast.success("Déclaration annulée : la mission est de nouveau ouverte.");
    },
    onError: (err: any) => toast.error(err?.response?.data?.error?.message ?? (err?.response?.status === 403 ? "Accès interdit." : "Annulation impossible.")),
  });

  const reassignMutation = useMutation({
    mutationFn: async (instrumentistId: number) => reassignMission(missionId, instrumentistId),
    onSuccess: async () => {
      setReassignOpen(false);
      await refreshAfterAction();
      toast.success("Instrumentiste changé.");
    },
    onError: (err: any) => toast.error(err?.response?.data?.error?.message ?? (err?.response?.status === 403 ? "Accès interdit." : "Changement d'instrumentiste impossible.")),
  });

  // Même clé que la lecture du tiroir : la fenêtre de correction réutilise l'encodage en cache.
  const encodingQuery = useQuery({
    queryKey: ["missionEncoding", missionId],
    queryFn: () => fetchMissionEncoding(missionId),
    enabled: encodingEditOpen,
  });

  const allowed = data?.allowedActions ?? [];
  const canEdit = allowed.includes("edit");
  // D-125 — 'dispatch' = remettre en jeu une mission OPEN (ex. demande refusée).
  const canPublish = allowed.includes("publish") || allowed.includes("dispatch");
  const canApprove = allowed.includes("approve");
  /** `reject` est un libellé d'action partagé entre DECLARED (rejectDeclaredMission)
   *  et SUBMITTED (rejectMissionEncoding, Lot 7) — un statut à la fois, jamais ambigu. */
  const canReject = allowed.includes("reject") && data?.status === "DECLARED";
  const canRejectEncoding = allowed.includes("reject") && data?.status === "SUBMITTED";
  const canValidateEncoding = allowed.includes("validate");
  const canReopenEncoding = allowed.includes("reopen");
  const canCancel = allowed.includes("cancel");
  const canDeclareWithoutInstrumentist = allowed.includes("declare_executed_without_instrumentist");
  const canCancelDeclaration = allowed.includes("cancel_executed_without_instrumentist");
  const canReassign = allowed.includes("reassign");
  const canEditHours = allowed.includes("edit_hours");
  const canEditEncoding = allowed.includes("edit_encoding");

  const anyLoading =
    approveMutation.isPending ||
    rejectMutation.isPending ||
    validateEncodingMutation.isPending ||
    rejectEncodingMutation.isPending ||
    reopenEncodingMutation.isPending ||
    cancelMutation.isPending ||
    declareMutation.isPending ||
    cancelDeclarationMutation.isPending ||
    reassignMutation.isPending;

  const dialogOpen =
    openEdit || openPublish || rejectConfirmOpen || approveConfirmOpen || validateConfirmOpen ||
    rejectEncodingOpen || reopenOpen || cancelConfirmOpen || declareOpen || cancelDeclarationOpen ||
    reassignOpen || hoursOpen || encodingEditOpen;

  const actions: MissionActionDescriptor[] = [];
  if (canDeclareWithoutInstrumentist) actions.push({ key: "declareWithoutInstrumentist", label: "Déclarer l'activité réalisée sans instrumentiste", tone: "neutral", contained: false, disabled: anyLoading, testId: "manager-declare-without-instrumentist", onClick: () => setDeclareOpen(true) });
  if (canCancelDeclaration) actions.push({ key: "cancelDeclaration", label: "Annuler la déclaration", tone: "warning", contained: false, disabled: anyLoading, testId: "manager-cancel-declaration", onClick: () => setCancelDeclarationOpen(true) });
  if (canCancel) actions.push({ key: "cancel", label: "Annuler la mission", tone: "danger", contained: false, disabled: anyLoading, onClick: () => setCancelConfirmOpen(true) });
  if (canReject) actions.push({ key: "reject", label: "Rejeter", tone: "danger", contained: false, disabled: anyLoading, onClick: () => setRejectConfirmOpen(true) });
  if (canApprove) actions.push({ key: "approve", label: "Approuver", tone: "success", contained: true, disabled: anyLoading, onClick: () => setApproveConfirmOpen(true) });
  if (canRejectEncoding) actions.push({ key: "rejectEncoding", label: "Rejeter l'encodage", tone: "danger", contained: false, disabled: anyLoading, onClick: () => setRejectEncodingOpen(true) });
  if (canValidateEncoding) actions.push({ key: "validateEncoding", label: "Valider l'encodage", tone: "success", contained: true, disabled: anyLoading, onClick: () => setValidateConfirmOpen(true) });
  if (canReopenEncoding) actions.push({ key: "reopenEncoding", label: "Rouvrir l'encodage", tone: "warning", contained: false, disabled: anyLoading, onClick: () => setReopenOpen(true) });
  if (canEdit) actions.push({ key: "edit", label: "Modifier", tone: "neutral", contained: false, disabled: false, onClick: () => setOpenEdit(true) });
  if (canPublish) actions.push({ key: "publish", label: "Diffuser / attribuer", tone: "primary", contained: true, disabled: false, onClick: () => setOpenPublish(true) });

  /** Corrections posées à côté de ce qu'elles modifient (personne, heures, encodage), pas dans le pied. */
  const corrections: Partial<Record<"reassign" | "editHours" | "editEncoding", MissionActionDescriptor>> = {};
  if (canReassign) corrections.reassign = { key: "reassign", label: "Changer", tone: "neutral", contained: false, disabled: anyLoading, testId: "manager-reassign", onClick: () => setReassignOpen(true) };
  if (canEditHours) corrections.editHours = { key: "editHours", label: "Corriger les heures", tone: "neutral", contained: false, disabled: anyLoading, testId: "manager-edit-hours", onClick: () => setHoursOpen(true) };
  if (canEditEncoding) corrections.editEncoding = { key: "editEncoding", label: "Corriger l'encodage", tone: "neutral", contained: false, disabled: anyLoading, testId: "manager-edit-encoding", onClick: () => setEncodingEditOpen(true) };

  const cancelEffectMessage =
    data?.status === "DRAFT"
      ? "Ce brouillon n'a jamais été publié — l'annuler ne notifie personne."
      : data?.status === "ASSIGNED"
      ? "Un instrumentiste est assigné à cette mission. L'annuler le retire de la mission et l'en informe."
      : "Cette mission est proposée au pool d'instrumentistes. L'annuler retire l'offre — plus personne ne pourra la revendiquer.";

  const dialogs = data ? (
    <>
      {canEdit && openEdit && (
        <EditMissionDialog open={openEdit} onClose={() => setOpenEdit(false)} mission={data} />
      )}
      {canPublish && openPublish && (
        <PublishMissionDialog open={openPublish} onClose={() => setOpenPublish(false)} mission={data} />
      )}

      <Dialog open={rejectConfirmOpen} onClose={() => setRejectConfirmOpen(false)}>
        <DialogTitle>Rejeter la mission</DialogTitle>
        <DialogContent dividers>
          <Typography>Voulez-vous rejeter cette mission déclarée ?</Typography>
          <Typography variant="body2" color="text.secondary" mt={0.5}>
            Le rejet supprime l'encodage côté serveur.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRejectConfirmOpen(false)} disabled={anyLoading}>Annuler</Button>
          <Button color="error" variant="contained" disabled={anyLoading}
            onClick={() => { setRejectConfirmOpen(false); rejectMutation.mutate(); }}>
            Rejeter
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={approveConfirmOpen} onClose={() => setApproveConfirmOpen(false)}>
        <DialogTitle>Approuver la mission</DialogTitle>
        <DialogContent dividers>
          <Typography>Voulez-vous approuver cette mission déclarée ?</Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setApproveConfirmOpen(false)} disabled={anyLoading}>Annuler</Button>
          <Button color="success" variant="contained" disabled={anyLoading}
            onClick={() => { setApproveConfirmOpen(false); approveMutation.mutate(); }}>
            Approuver
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={validateConfirmOpen} onClose={() => setValidateConfirmOpen(false)}>
        <DialogTitle>Valider l'encodage</DialogTitle>
        <DialogContent dividers>
          <Typography>
            Voulez-vous valider cet encodage ? Il sera définitivement verrouillé — plus aucune
            modification possible, sauf réouverture explicite ensuite.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setValidateConfirmOpen(false)} disabled={anyLoading}>Annuler</Button>
          <Button color="success" variant="contained" disabled={anyLoading}
            onClick={() => { setValidateConfirmOpen(false); validateEncodingMutation.mutate(); }}>
            Valider
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={rejectEncodingOpen} onClose={() => setRejectEncodingOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Rejeter l'encodage</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" color="text.secondary" mb={1.5} data-testid="reject-encoding-intro">
            {data.executedWithoutInstrumentist
              ? "Activité réalisée sans instrumentiste : l'encodage est renvoyé au chirurgien pour correction. Le commentaire est obligatoire (ex : intervention manquante, matériel incorrect, quantité à vérifier, mauvaise firme…)."
              : "L'encodage repart chez l'instrumentiste pour correction. Le commentaire est obligatoire (ex : matériel manquant, quantité incorrecte, mauvaise firme, intervention incomplète...)."}
          </Typography>
          <TextField
            autoFocus
            fullWidth
            multiline
            minRows={3}
            label="Motif du rejet"
            value={rejectEncodingComment}
            onChange={(e) => setRejectEncodingComment(e.target.value)}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRejectEncodingOpen(false)} disabled={anyLoading}>Annuler</Button>
          <Button color="error" variant="contained"
            disabled={anyLoading || rejectEncodingComment.trim() === ""}
            onClick={() => { setRejectEncodingOpen(false); rejectEncodingMutation.mutate(rejectEncodingComment.trim()); }}>
            Rejeter
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={declareOpen} onClose={() => setDeclareOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Déclarer l'activité réalisée sans instrumentiste</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" color="text.secondary">
            La mission a commencé sans être couverte. Si l'intervention a bien eu lieu, elle quitte le pool et
            devient encodable (interventions, matériel) par le chirurgien ou un manager, sans aucune heure ni
            prestation d'instrumentiste. Le calcul ne portera que sur la firme.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeclareOpen(false)} disabled={anyLoading}>Annuler</Button>
          <Button variant="contained" disableElevation disabled={anyLoading} onClick={() => declareMutation.mutate()}>
            Confirmer la déclaration
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={cancelDeclarationOpen} onClose={() => setCancelDeclarationOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Annuler la déclaration « sans instrumentiste »</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" color="text.secondary" mb={1.5}>
            La mission redevient ouverte. Les interventions et le matériel déjà encodés sont conservés et tracés.
            Motif obligatoire.
          </Typography>
          <TextField
            autoFocus
            fullWidth
            multiline
            minRows={3}
            required
            label="Motif de l'annulation"
            value={cancelDeclarationReason}
            onChange={(e) => setCancelDeclarationReason(e.target.value)}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setCancelDeclarationOpen(false)} disabled={anyLoading}>Fermer</Button>
          <Button color="warning" variant="contained" disabled={anyLoading || cancelDeclarationReason.trim() === ""}
            onClick={() => cancelDeclarationMutation.mutate(cancelDeclarationReason.trim())}>
            Annuler la déclaration
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={reopenOpen} onClose={() => setReopenOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Rouvrir l'encodage</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" color="text.secondary" mb={1.5} data-testid="reopen-encoding-intro">
            {data.executedWithoutInstrumentist
              ? "Activité réalisée sans instrumentiste : la mission repasse en encodage modifiable par le chirurgien. Motif obligatoire (D-148)."
              : "La mission repasse en encodage modifiable côté instrumentiste. Motif obligatoire (D-148)."}
          </Typography>
          <TextField
            autoFocus
            fullWidth
            multiline
            minRows={3}
            required
            label="Motif de la correction"
            value={reopenComment}
            onChange={(e) => setReopenComment(e.target.value)}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setReopenOpen(false)} disabled={anyLoading}>Annuler</Button>
          <Button color="warning" variant="contained" disabled={anyLoading || reopenComment.trim() === ""}
            onClick={() => { setReopenOpen(false); reopenEncodingMutation.mutate(reopenComment.trim()); }}>
            Rouvrir
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={cancelConfirmOpen} onClose={() => setCancelConfirmOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Annuler la mission</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" color="text.secondary" mb={1.5}>
            {cancelEffectMessage}
          </Typography>
          <Typography variant="body2" color="text.secondary" mb={1.5}>
            La mission reste consultable dans l'historique — elle n'est jamais supprimée.
          </Typography>
          <TextField
            autoFocus
            fullWidth
            multiline
            minRows={2}
            label="Motif (optionnel)"
            value={cancelReason}
            onChange={(e) => setCancelReason(e.target.value)}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setCancelConfirmOpen(false)} disabled={anyLoading}>Retour</Button>
          <Button color="error" variant="contained" disabled={anyLoading}
            onClick={() => { setCancelConfirmOpen(false); cancelMutation.mutate(cancelReason); }}>
            Annuler la mission
          </Button>
        </DialogActions>
      </Dialog>

      {canReassign && (
        <ReassignMissionDialog
          open={reassignOpen}
          loading={reassignMutation.isPending}
          missionId={missionId}
          onClose={() => setReassignOpen(false)}
          onConfirm={(instrumentistId) => reassignMutation.mutate(instrumentistId)}
        />
      )}

      {canEditHours && hoursOpen && (
        <EditServiceHoursDialog
          open={hoursOpen}
          onClose={() => setHoursOpen(false)}
          mission={data}
          hoursSource="MANAGER"
          title="Corriger les heures"
          onSaved={() => void refreshAfterAction()}
        />
      )}

      {canEditEncoding && encodingEditOpen && (
        <Dialog open onClose={() => { setEncodingEditOpen(false); void refreshAfterAction(); }} maxWidth="md" fullWidth>
          <DialogTitle>Corriger l'encodage — mission #{missionId}</DialogTitle>
          <DialogContent dividers>
            {data.encodingCorrectionFinancialNotice && (
              <Typography variant="body2" color="warning.dark" sx={{ mb: 1.5 }}>{data.encodingCorrectionFinancialNotice}</Typography>
            )}
            {encodingQuery.data ? (
              <InterventionsSection
                missionId={missionId}
                canEdit={canEditEncoding}
                entries={encodingQuery.data.entries ?? []}
                legacyInterventions={encodingQuery.data.interventions ?? []}
                catalog={encodingQuery.data.catalog}
                onSaved={() => void refreshAfterAction()}
                canSubmit={false}
                onValidate={() => undefined}
                progress={encodingQuery.data.progress}
              />
            ) : (
              <Typography variant="body2" color="text.secondary">
                {encodingQuery.isError ? "Impossible de charger l'encodage." : "Chargement…"}
              </Typography>
            )}
          </DialogContent>
          <DialogActions>
            <Button onClick={() => { setEncodingEditOpen(false); void refreshAfterAction(); }}>Fermer</Button>
          </DialogActions>
        </Dialog>
      )}
    </>
  ) : null;

  return { actions, corrections, dialogs, anyLoading, dialogOpen };
}
