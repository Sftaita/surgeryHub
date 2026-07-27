import * as React from "react";
import { Box } from "@mui/material";
import { useMutation, useQuery } from "@tanstack/react-query";

import type { Mission } from "../api/missions.types";
import { submitMission, getMissionExecution } from "../api/missions.api";
import { fetchMissionEncoding } from "../../encoding/api/encoding.api";
import { formatExecutionHours } from "../utils/missions.format";
import { useToast } from "../../../ui/toast/useToast";
import { requestMissionSync } from "../sync/missionSyncBus";
import { SheetModal } from "../../../ui/sheet/SheetModal";
import { Field } from "../../../ui/sheet/Field";

const GREEN_50 = "#EFFAF5";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_150 = "#E7EBEF";
const GRAY_500 = "#727E8C";
const GRAY_700 = "#3A4754";
const AMBER_50 = "#FEF6E7";
const AMBER_700 = "#B7791F";

type Props = {
  open: boolean;
  mission: Mission;
  onClose: () => void;
  onSubmitted?: () => void;
  /** Topic d'aide contextuel — omis par défaut, ce composant est réutilisé hors encodage. */
  helpTopicId?: string;
};

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
}

function OkIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M20 6 9 17l-5-5" />
    </svg>
  );
}
function WarnIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
    </svg>
  );
}

function RecapRow({ warn, label, value }: { warn: boolean; label: string; value: string }) {
  return (
    <Box sx={{ display: "flex", alignItems: "center", gap: "12px", padding: "13px 0", borderBottom: "1px dashed", borderColor: GRAY_150 }}>
      <Box sx={{
        width: 36, height: 36, borderRadius: "999px", display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0,
        background: warn ? AMBER_50 : GREEN_50, color: warn ? AMBER_700 : GREEN_700,
      }}>
        {warn ? <WarnIcon /> : <OkIcon />}
      </Box>
      <Box sx={{ flex: 1, fontSize: 14, fontWeight: 600, color: GRAY_700 }}>{label}</Box>
      <Box sx={{ fontSize: 14, fontWeight: 700, color: warn ? AMBER_700 : "inherit" }}>{value}</Box>
    </Box>
  );
}

/**
 * docs/design/screens/sheets-divers.md#Récapitulatif — passage obligé avant la
 * clôture, jamais un raccourci direct depuis "Terminer l'encodage". `noMaterial`/`comment`
 * (MissionSubmitRequest) sont désormais réellement branchés côté serveur
 * (MissionEncodingWorkflowService::complete()) : pour une mission BLOCK sans aucune ligne
 * de matériel encodée, un commentaire décrivant les interventions réalisées est requis pour
 * clôturer — une mission CONSULTATION n'attend jamais de matériel, jamais de commentaire
 * exigé pour ce type. Le serveur recalcule lui-même le compte de lignes et applique cette
 * même règle par type, jamais une confiance aveugle dans ce que ce composant calcule ici.
 */
export default function SubmitDialog({ open, mission, onClose, onSubmitted, helpTopicId }: Props) {
  const toast = useToast();
  const [comment, setComment] = React.useState("");

  const { data: encoding, isLoading } = useQuery({
    queryKey: ["missionEncoding", mission.id],
    queryFn: () => fetchMissionEncoding(mission.id),
    enabled: open,
  });

  // Anomalie écran d'encodage (commit dédié) — même query key que MissionEncodingPage.tsx
  // (lecture) et EditServiceHoursDialog.tsx (mutation) : le récapitulatif final doit
  // afficher exactement la même valeur que la carte "Heures prestées", jamais
  // mission.service?.hours (le backend n'expose plus ce champ depuis D-071).
  const { data: execution } = useQuery({
    queryKey: ["mission-execution", mission.id],
    queryFn: () => getMissionExecution(mission.id),
    enabled: open,
  });

  React.useEffect(() => {
    if (open) setComment("");
  }, [open]);

  const interventions = encoding?.interventions ?? [];
  const materialLineCount = interventions.reduce((sum, itv) => sum + (itv.materialLines?.length ?? 0), 0);
  const notFoundCount = interventions.reduce((sum, itv) => sum + (itv.materialItemRequests?.length ?? 0), 0);
  const hoursLabel = formatExecutionHours(execution);
  const hasHours = hoursLabel !== "Non renseigné";
  const requiresComment = !isLoading && mission.type === "BLOCK" && materialLineCount === 0;
  const canSubmit = !requiresComment || comment.trim() !== "";

  const mutation = useMutation({
    mutationFn: () => submitMission(mission.id, { noMaterial: materialLineCount === 0, comment: comment.trim() }),
    onSuccess: () => {
      toast.success("Mission clôturée. Encodage transmis à l'établissement.");
      requestMissionSync();
      onClose();
      onSubmitted?.();
    },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  return (
    <SheetModal open={open} title="Récapitulatif avant validation" onClose={onClose} closeDisabled={mutation.isPending} helpTopicId={helpTopicId}>
      {isLoading ? (
        <Box sx={{ mt: "14px", fontSize: 14, color: GRAY_500 }}>Chargement…</Box>
      ) : (
        <Box sx={{ mt: "14px", display: "flex", flexDirection: "column" }}>
          <RecapRow warn={!hasHours} label="Heures" value={hoursLabel} />
          <RecapRow warn={false} label="Interventions" value={String(interventions.length)} />
          <RecapRow warn={false} label="Matériel encodé" value={`${materialLineCount} ligne${materialLineCount > 1 ? "s" : ""}`} />
          {notFoundCount > 0 && (
            <RecapRow warn label="Matériel non trouvé" value={`${notFoundCount} ligne${notFoundCount > 1 ? "s" : ""}`} />
          )}
        </Box>
      )}

      {requiresComment && (
        <Box sx={{ mt: "14px" }}>
          <Field
            id="submit-no-material-comment"
            label="Décrivez les interventions réalisées *"
            value={comment}
            onChange={setComment}
            disabled={mutation.isPending}
            multiline
            minRows={3}
            placeholder="Ex. Consultation de suivi, aucun matériel utilisé."
            helperText="Aucun matériel n'a été encodé — précisez ce qui a été fait avant de clôturer."
          />
        </Box>
      )}

      <Box sx={{ mt: "14px", fontSize: 13, color: GRAY_500, lineHeight: 1.5 }}>
        Vérifiez les informations, encodez vos heures prestées et validez pour clôturer la mission.
      </Box>

      <Box
        component="button"
        type="button"
        onClick={() => mutation.mutate()}
        disabled={mutation.isPending || isLoading || !canSubmit}
        sx={{
          mt: "16px", width: "100%", height: 54, border: "none", borderRadius: "13px",
          background: GREEN_800, color: "#fff", fontFamily: "inherit", fontSize: 15.5, fontWeight: 700,
          cursor: "pointer", boxShadow: "0 5px 14px rgba(20,77,56,.3)",
          "&:hover": { background: "#144D38" }, "&:active": { transform: "translateY(0.5px)" },
          "&:disabled": { opacity: 0.6, cursor: "default", boxShadow: "none" },
        }}
      >
        {mutation.isPending ? "…" : "Valider et clôturer la mission"}
      </Box>
      <Box
        component="button"
        type="button"
        onClick={onClose}
        disabled={mutation.isPending}
        sx={{ mt: "8px", width: "100%", height: 44, border: "none", background: "transparent", color: GRAY_500, fontFamily: "inherit", fontSize: 14, fontWeight: 600, cursor: "pointer" }}
      >
        Continuer l'encodage
      </Box>
    </SheetModal>
  );
}
