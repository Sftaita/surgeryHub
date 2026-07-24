import * as React from "react";
import { Box } from "@mui/material";
import { SheetModal } from "../../../ui/sheet/SheetModal";
import { SelectField } from "../../../ui/sheet/SelectField";
import { Field } from "../../../ui/sheet/Field";

import type { MissionEncodingEntry } from "../api/encoding.types";
import type { MaterialTarget } from "./MaterialWizard";

const GREEN_800 = "#1F6B4F";
const GRAY_500 = "#727E8C";

/** Encodée comme "kind:id" — un select natif à cette page ne peut porter qu'une seule
 *  valeur scalaire, jamais une paire (kind, id) séparée. */
function encodeTargetValue(entry: MissionEncodingEntry): string {
  return `${entry.kind}:${entry.id}`;
}
function decodeTargetValue(value: string): MaterialTarget {
  const [kind, id] = value.split(":");
  return { kind: kind as MaterialTarget["kind"], id: Number(id) };
}

type Props = {
  open: boolean;
  loading: boolean;
  /** Interventions et drafts modifiables (readOnly=false) — un draft KEPT_AS_HISTORY
   *  n'apparaît jamais dans cette liste, aucune mutation ne doit jamais lui être envoyée. */
  entries: MissionEncodingEntry[];
  preferredTarget: MaterialTarget | null;
  onClose: () => void;
  onSubmit: (values: {
    missionInterventionId?: number;
    interventionDraftId?: number;
    label: string;
    referenceCode?: string;
    comment?: string;
  }) => void;
};

export default function MaterialItemRequestDialog({
  open,
  loading,
  entries,
  preferredTarget,
  onClose,
  onSubmit,
}: Props) {
  const [targetValue, setTargetValue] = React.useState<string | null>(null);
  const [label, setLabel] = React.useState("");
  const [referenceCode, setReferenceCode] = React.useState("");
  const [comment, setComment] = React.useState("");

  const sorted = React.useMemo(
    () => entries.filter((e) => !e.readOnly).slice().sort((a, b) => (a.orderIndex ?? 0) - (b.orderIndex ?? 0)),
    [entries],
  );

  React.useEffect(() => {
    if (!open) return;
    const preferred = preferredTarget ? `${preferredTarget.kind}:${preferredTarget.id}` : null;
    const fallback = sorted[0] ? encodeTargetValue(sorted[0]) : null;
    setTargetValue(preferred ?? fallback);
    setLabel("");
    setReferenceCode("");
    setComment("");
  }, [open, preferredTarget, sorted]);

  const canSubmit = !loading && targetValue != null && label.trim() !== "";

  function handleSubmit() {
    if (!canSubmit || targetValue == null) return;
    const target = decodeTargetValue(targetValue);
    onSubmit({
      missionInterventionId: target.kind === "INTERVENTION" ? target.id : undefined,
      interventionDraftId: target.kind === "DRAFT" ? target.id : undefined,
      label: label.trim(),
      referenceCode: referenceCode.trim() || undefined,
      comment: comment.trim() || undefined,
    });
  }

  const interventionOptions = sorted.map((entry) => ({
    value: encodeTargetValue(entry),
    label: entry.kind === "INTERVENTION" ? entry.label : `${entry.label} (demande en cours)`,
  }));

  return (
    <SheetModal open={open} title="Déclarer un matériel manquant" onClose={onClose} closeDisabled={loading} helpTopicId="mission-encoding">
      <Box sx={{ mt: "14px", fontSize: 13.5, color: GRAY_500, lineHeight: 1.5 }}>
        Le matériel sera signalé au manager. Il sera ajouté au catalogue et associé à votre mission.
      </Box>

      <Box sx={{ mt: "16px", display: "flex", flexDirection: "column", gap: "16px" }}>
        <SelectField
          id="mat-req-itv"
          label="Intervention"
          placeholder="Sélectionner une intervention"
          value={targetValue}
          options={interventionOptions}
          onChange={(v) => setTargetValue(v)}
          disabled={loading}
        />

        <Field
          id="mat-req-label"
          label="Nom du matériel *"
          value={label}
          onChange={setLabel}
          disabled={loading}
          placeholder="ex: Anchor suture 5.5mm Smith & Nephew"
          autoFocus
        />

        <Field
          id="mat-req-reference"
          label="Référence"
          value={referenceCode}
          onChange={setReferenceCode}
          disabled={loading}
          placeholder="ex: REF-12345"
        />

        <Field
          id="mat-req-comment"
          label="Commentaire"
          value={comment}
          onChange={setComment}
          disabled={loading}
          multiline
          minRows={2}
          placeholder="Informations complémentaires pour le manager"
        />
      </Box>

      <Box
        component="button"
        type="button"
        onClick={handleSubmit}
        disabled={!canSubmit}
        sx={{
          mt: "20px", width: "100%", height: 52, border: "none", borderRadius: "12px",
          background: GREEN_800, color: "#fff", fontFamily: "inherit", fontSize: 15, fontWeight: 700,
          cursor: "pointer", boxShadow: "0 5px 14px rgba(20,77,56,.3)",
          "&:hover": { background: "#144D38" }, "&:active": { transform: "translateY(0.5px)" },
          "&:disabled": { opacity: 0.6, cursor: "default", boxShadow: "none" },
        }}
      >
        {loading ? "…" : "Envoyer la demande"}
      </Box>
      <Box
        component="button"
        type="button"
        onClick={onClose}
        disabled={loading}
        sx={{ mt: "8px", width: "100%", height: 44, border: "none", background: "transparent", color: GRAY_500, fontFamily: "inherit", fontSize: 14, fontWeight: 600, cursor: "pointer" }}
      >
        Annuler
      </Box>
    </SheetModal>
  );
}
