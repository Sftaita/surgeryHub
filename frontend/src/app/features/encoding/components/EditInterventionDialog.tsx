import * as React from "react";
import { Box } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { SheetModal } from "../../../ui/sheet/SheetModal";
import { SelectField } from "../../../ui/sheet/SelectField";
import type { CatalogFirm, CatalogInterventionType, MissionEncodingInterventionEntry, PatchInterventionBody } from "../api/encoding.types";
import { fetchFirmServiceOfferings } from "../api/encoding.api";

const GREEN_500 = "#42A882";
const GREEN_800 = "#1F6B4F";
const GRAY_500 = "#727E8C";
const GRAY_800 = "#243240";
const RED_600 = "#E5484D";
const BORDER_DEFAULT = "#DDE2E8";

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
  const [representativePresent, setRepresentativePresent] = React.useState<boolean | null>(null);

  React.useEffect(() => {
    if (!open || !intervention) return;
    setTypeId(intervention.interventionType?.id ?? null);
    setFirmId(intervention.firm?.id ?? NO_FIRM);
    setRepresentativePresent(intervention.representativePresent ?? null);
  }, [open, intervention]);

  const offeringsQuery = useQuery({
    queryKey: ["firmServiceOfferings", firmId],
    queryFn: () => fetchFirmServiceOfferings(firmId),
    enabled: open && firmId !== NO_FIRM,
  });

  // Refonte Catalogue/Prestations (D-092) — même règle qu'à la création : la question
  // n'est affichée que si pertinente pour la firme × type actuellement sélectionnés.
  const currentOffering = (offeringsQuery.data ?? []).find((o) => o.interventionType.id === typeId);
  const showRepresentativeQuestion = firmId !== NO_FIRM && !!currentOffering?.representativePresenceRelevant;
  const selectedFirmName = firms.find((f) => f.id === firmId)?.name ?? "";

  const canSubmit = typeId != null && (!showRepresentativeQuestion || representativePresent !== null);

  const submit = () => {
    if (!canSubmit || typeId == null) return;
    onSubmit({
      interventionTypeId: typeId,
      primaryFirmId: firmId === NO_FIRM ? null : firmId,
      // Prestation non pertinente désormais : on efface explicitement une éventuelle
      // ancienne réponse (jamais laissée orpheline, liée à une firme/type qui n'est plus
      // celui sélectionné — voir D-092 section 3 du lot instrumentiste).
      representativePresent: showRepresentativeQuestion ? representativePresent : null,
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
          onChange={(v) => { setTypeId(v); setRepresentativePresent(null); }}
          disabled={loading}
        />

        <SelectField
          id="edit-itv-firm"
          label="Firme principale (optionnel)"
          placeholder="Sélectionner une firme"
          value={firmId}
          options={firmOptions}
          onChange={(v) => { setFirmId(v); setRepresentativePresent(null); }}
          disabled={loading}
        />

        {showRepresentativeQuestion && (
          <Box sx={{ background: "#F5F7FA", borderRadius: "12px", padding: "14px" }}>
            <Box sx={{ fontSize: 14, fontWeight: 700, mb: "4px" }}>Un délégué de {selectedFirmName} était-il présent ?</Box>
            <Box sx={{ fontSize: 12.5, color: GRAY_500, mb: "10px" }}>
              Cette information est nécessaire pour cette intervention.
            </Box>
            <Box sx={{ display: "flex", gap: "10px" }}>
              {([true, false] as const).map((val) => (
                <Box
                  key={String(val)}
                  component="button"
                  type="button"
                  onClick={() => setRepresentativePresent(val)}
                  disabled={loading}
                  sx={{
                    flex: 1, height: 42, borderRadius: "10px", cursor: "pointer", fontFamily: "inherit",
                    fontSize: 14, fontWeight: 700,
                    border: "1.5px solid", borderColor: representativePresent === val ? GREEN_500 : BORDER_DEFAULT,
                    background: representativePresent === val ? "rgba(66,168,130,.12)" : "#fff",
                    color: representativePresent === val ? GREEN_800 : GRAY_800,
                  }}
                >
                  {val ? "Oui" : "Non"}
                </Box>
              ))}
            </Box>
            {representativePresent === null && (
              <Box sx={{ mt: "10px", fontSize: 12.5, color: RED_600, fontWeight: 600 }}>
                Indiquez si un délégué de {selectedFirmName} était présent.
              </Box>
            )}
          </Box>
        )}
      </Box>

      <Box
        component="button"
        type="button"
        onClick={submit}
        disabled={loading || !canSubmit}
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
