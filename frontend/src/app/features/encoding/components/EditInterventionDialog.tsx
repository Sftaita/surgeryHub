import * as React from "react";
import { Box } from "@mui/material";
import { SheetModal } from "../../../ui/sheet/SheetModal";
import { SelectField } from "../../../ui/sheet/SelectField";
import type { CatalogFirm, CatalogInterventionType, MissionEncodingInterventionEntry, PatchInterventionBody } from "../api/encoding.types";

const GRAY_500 = "#727E8C";

const NO_FIRM = 0;

type Props = {
  open: boolean;
  loading: boolean;
  intervention: MissionEncodingInterventionEntry | null;
  interventionTypes: CatalogInterventionType[];
  firms: CatalogFirm[];
  onClose: () => void;
  onSubmit: (values: PatchInterventionBody) => void;
};

/**
 * Lot 5 (D-068) : le code/libellé ne sont plus édités directement (instantané dérivé du
 * référentiel) — seul le type et la firme principale sont modifiables ici.
 */
export default function EditInterventionDialog({
  open,
  loading,
  intervention,
  interventionTypes,
  firms,
  onClose,
  onSubmit,
}: Props) {
  const [typeId, setTypeId] = React.useState<number | null>(null);
  const [firmId, setFirmId] = React.useState<number>(NO_FIRM);

  React.useEffect(() => {
    if (!open || !intervention) return;
    setTypeId(intervention.interventionType?.id ?? null);
    setFirmId(intervention.firm?.id ?? NO_FIRM);
  }, [open, intervention]);

  const submit = () => {
    if (typeId == null) return;
    onSubmit({
      interventionTypeId: typeId,
      primaryFirmId: firmId === NO_FIRM ? null : firmId,
    });
  };

  const typeOptions = interventionTypes.map((t) => ({ value: t.id, label: `${t.code} — ${t.label}` }));
  const firmOptions = [
    { value: NO_FIRM, label: "— Aucune" },
    ...firms.map((f) => ({ value: f.id, label: f.name })),
  ];

  return (
    <SheetModal open={open} title="Modifier l'intervention" onClose={onClose} closeDisabled={loading} helpTopicId="mission-encoding">
      <Box sx={{ mt: "18px", display: "flex", flexDirection: "column", gap: "16px" }}>
        <SelectField
          id="edit-itv-type"
          label="Type d'intervention *"
          placeholder="Sélectionner un type"
          value={typeId}
          options={typeOptions}
          onChange={(v) => setTypeId(v)}
          disabled={loading}
        />

        <SelectField
          id="edit-itv-firm"
          label="Firme principale (optionnel)"
          placeholder="Sélectionner une firme"
          value={firmId}
          options={firmOptions}
          onChange={(v) => setFirmId(v)}
          disabled={loading}
        />
      </Box>

      <Box
        component="button"
        type="button"
        onClick={submit}
        disabled={loading || typeId == null}
        sx={{
          mt: "20px", width: "100%", height: 52, border: "none", borderRadius: "12px",
          background: "#1F6B4F", color: "#fff", fontFamily: "inherit", fontSize: 15, fontWeight: 700,
          cursor: "pointer", boxShadow: "0 5px 14px rgba(20,77,56,.3)",
          "&:hover": { background: "#144D38" }, "&:active": { transform: "translateY(0.5px)" },
          "&:disabled": { opacity: 0.6, cursor: "default", boxShadow: "none" },
        }}
      >
        {loading ? "…" : "Enregistrer"}
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
