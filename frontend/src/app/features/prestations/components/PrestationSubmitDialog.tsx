import * as React from "react";
import { Box } from "@mui/material";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import { SheetModal } from "../../../ui/sheet/SheetModal";
import { Field } from "../../../ui/sheet/Field";
import { useToast } from "../../../ui/toast/useToast";
import { requestMissionSync } from "../../missions/sync/missionSyncBus";
import { invalidateOperationalPlanning } from "../../missions/dispatch/missionDispatch.api";
import { formatImputation, formatMinutes, prestationError, submitPrestation, type PrestationView } from "../api/prestations.api";

const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_500 = "#727E8C";
const GRAY_600 = "#566270";
const GRAY_800 = "#243240";
const RED_50 = "#FDECEC";
const RED_800 = "#9B1C1C";

type Props = {
  open: boolean;
  onClose: () => void;
  anchorMissionId: number;
  prestation: PrestationView;
  onSubmitted: () => void;
  helpTopicId?: string;
};

/**
 * D-156 — « Terminer la prestation » : une seule soumission pour toutes les missions
 * simultanées, tout ou rien (contrôlée et appliquée par le serveur). Une seule justification
 * « sans matériel », posée par le serveur sur les seules missions sans matériel (D-080).
 */
export default function PrestationSubmitDialog({ open, onClose, anchorMissionId, prestation, onSubmitted, helpTopicId }: Props) {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [comment, setComment] = React.useState("");
  const [serverError, setServerError] = React.useState<{ message: string; missing: { label: string }[] } | null>(null);

  React.useEffect(() => {
    if (open) {
      setComment("");
      setServerError(null);
    }
  }, [open]);

  const pending = prestation.missions.filter((m) => m.status !== "SUBMITTED");
  const withoutMaterial = pending.filter((m) => m.activeMaterialLineCount === 0);

  const mutation = useMutation({
    mutationFn: () => submitPrestation(anchorMissionId, {
      fingerprint: prestation.fingerprint,
      ...(comment.trim() !== "" ? { noMaterialComment: comment.trim() } : {}),
    }),
    onSuccess: (view) => {
      queryClient.setQueryData(["prestation", anchorMissionId], view);
      view.missionIds.forEach((id) => {
        void queryClient.invalidateQueries({ queryKey: ["mission", id] });
        void queryClient.invalidateQueries({ queryKey: ["missionEncoding", id] });
      });
      void invalidateOperationalPlanning(queryClient);
      requestMissionSync();
      toast.success(`Prestation soumise (${view.size} missions).`);
      onSubmitted();
    },
    onError: (err) => {
      const e = prestationError(err);
      if (e.code === "PRESTATION_CHANGED") {
        void queryClient.invalidateQueries({ queryKey: ["prestation"] });
      }
      const missing = Array.isArray(e.extra.missing) ? (e.extra.missing as { label: string }[]) : [];
      setServerError({ message: e.message, missing });
    },
  });

  return (
    <SheetModal open={open} title="Terminer la prestation" onClose={onClose} closeDisabled={mutation.isPending} helpTopicId={helpTopicId}>
      <Box sx={{ mt: "14px", fontSize: 13.5, color: GRAY_600 }}>
        Les {prestation.size} missions de cette prestation sont soumises ensemble.
      </Box>

      <Box component="ul" sx={{ listStyle: "none", p: 0, m: "12px 0 0", display: "flex", flexDirection: "column", gap: "8px" }}>
        {prestation.missions.map((m) => (
          <Box component="li" key={m.id} data-testid={`prestation-submit-mission-${m.id}`} sx={{ background: "#F5F7FA", borderRadius: "10px", padding: "10px 12px" }}>
            <Box sx={{ fontSize: 13.5, fontWeight: 700, color: GRAY_800 }}>{m.label}</Box>
            <Box sx={{ fontSize: 12.5, color: GRAY_500, mt: "2px" }}>
              {m.interventionCount} intervention(s) · {m.activeMaterialLineCount} ligne(s) de matériel · {formatImputation(m.imputedMinutes)}
              {m.status === "SUBMITTED" ? " · déjà soumise" : ""}
            </Box>
          </Box>
        ))}
      </Box>

      <Box sx={{ mt: "12px", fontSize: 13.5, color: GRAY_600 }}>
        Présence réelle :{" "}
        <Box component="span" sx={{ fontWeight: 700, color: GRAY_800 }}>{formatMinutes(prestation.presence?.totalMinutes)}</Box>
      </Box>

      {withoutMaterial.length > 0 && (
        <Box sx={{ mt: "14px" }}>
          <Field
            id="prestation-no-material-comment"
            label={withoutMaterial.length === pending.length
              ? "Aucun matériel encodé : décrivez les interventions réalisées"
              : `Aucun matériel encodé pour ${withoutMaterial.map((m) => m.label).join(", ")} : décrivez les interventions réalisées`}
            value={comment}
            onChange={setComment}
            multiline
            helperText="Une seule justification, appliquée aux missions sans matériel."
          />
        </Box>
      )}

      {serverError && (
        <Box data-testid="prestation-submit-error" sx={{ mt: "12px", background: RED_50, borderRadius: "12px", padding: "12px 14px", fontSize: 13, color: RED_800 }}>
          {serverError.message}
          {serverError.missing.length > 0 && (
            <Box component="ul" sx={{ m: "6px 0 0", pl: "18px" }}>
              {serverError.missing.map((item, i) => <li key={i}>{item.label}</li>)}
            </Box>
          )}
        </Box>
      )}

      <Box
        component="button"
        type="button"
        onClick={() => mutation.mutate()}
        disabled={mutation.isPending || (withoutMaterial.length > 0 && comment.trim() === "")}
        sx={{
          mt: "16px", width: "100%", height: 52, border: "none", borderRadius: "12px",
          background: GREEN_700, color: "#fff", fontFamily: "inherit", fontSize: 15, fontWeight: 700, cursor: "pointer",
          "&:hover": { background: GREEN_800 },
          "&:disabled": { opacity: 0.6, cursor: "default" },
        }}
      >
        {mutation.isPending ? "…" : "Soumettre la prestation"}
      </Box>
      <Box
        component="button"
        type="button"
        onClick={onClose}
        disabled={mutation.isPending}
        sx={{ mt: "8px", width: "100%", height: 44, border: "none", background: "transparent", color: GRAY_500, fontFamily: "inherit", fontSize: 14, fontWeight: 600, cursor: "pointer" }}
      >
        Annuler
      </Box>
    </SheetModal>
  );
}
