import * as React from "react";
import { Box } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { SheetModal } from "../../../ui/sheet/SheetModal";
import type { CatalogFirm, CatalogInterventionType, MissionEncodingInterventionEntry, PatchInterventionBody } from "../api/encoding.types";
import { fetchFirmServiceOfferings } from "../api/encoding.api";
import { useRecentFirms, useRecentInterventionTypes } from "../api/useEncodingSuggestions";
import RepresentativePresenceQuestion, { representativeAnswerBody, type RepresentativeAnswer } from "./RepresentativePresenceQuestion";
import SearchablePicker from "./SearchablePicker";
import { RECENT_FIRMS_TITLE, RECENT_TYPES_TITLE, firmPickerItem, typePickerItem } from "./AddInterventionDialog";

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
  /** D-172 — correction manager : la présence du délégué peut être « non renseignée ». */
  managerCorrection?: boolean;
  /** D-178 — raccourcis « récemment utilisés » (absents sans mission). */
  missionId?: number;
  surgeonId?: number | null;
};

/**
 * Lot 5 (D-068) : le code/libellé ne sont plus édités directement (instantané dérivé du
 * référentiel) — seul le type et la firme principale sont modifiables ici.
 *
 * D-178 — même sélection que l'ajout (SearchablePicker : raccourcis récents + recherche) ; la
 * firme et le type déjà enregistrés restent affichés et ne changent que sur un choix explicite.
 * Le PATCH envoyé est inchangé (règles de choix obligatoire / matériel incompatible côté serveur).
 */
export default function EditInterventionDialog({
  open,
  loading,
  intervention,
  interventionTypes,
  firms,
  onClose,
  onSubmit,
  managerCorrection = false,
  missionId,
  surgeonId,
}: Props) {
  const [typeId, setTypeId] = React.useState<number | null>(null);
  const [firmId, setFirmId] = React.useState<number>(NO_FIRM);
  const [representativePresent, setRepresentativePresent] = React.useState<RepresentativeAnswer>(null);
  const [selectedChoiceOptionId, setSelectedChoiceOptionId] = React.useState<number | null>(null);

  React.useEffect(() => {
    if (!open || !intervention) return;
    setTypeId(intervention.interventionType?.id ?? null);
    setFirmId(intervention.firm?.id ?? NO_FIRM);
    setRepresentativePresent(intervention.representativePresent ?? null);
    setSelectedChoiceOptionId(intervention.selectedChoiceOptionId ?? null);
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

  // Tarification firme conditionnée à un choix obligatoire — même règle.
  const choiceGroup = currentOffering?.choiceGroup ?? null;
  const activeChoiceOptions = (choiceGroup?.options ?? []).filter((o) => o.active);
  const showChoiceQuestion = firmId !== NO_FIRM && choiceGroup != null;

  const canSubmit = typeId != null
    && (!showRepresentativeQuestion || representativePresent !== null)
    && (!showChoiceQuestion || selectedChoiceOptionId !== null);

  const submit = () => {
    if (!canSubmit || typeId == null) return;
    onSubmit({
      interventionTypeId: typeId,
      primaryFirmId: firmId === NO_FIRM ? null : firmId,
      // Prestation non pertinente désormais : on efface explicitement une éventuelle
      // ancienne réponse (jamais laissée orpheline, liée à une firme/type qui n'est plus
      // celui sélectionné — voir D-092 section 3 du lot instrumentiste).
      ...(showRepresentativeQuestion ? representativeAnswerBody(representativePresent) : { representativePresent: null }),
      selectedChoiceOptionId: showChoiceQuestion ? selectedChoiceOptionId : null,
    });
  };

  const recentFirmsQuery = useRecentFirms(missionId, open);
  const recentTypesQuery = useRecentInterventionTypes(missionId, firmId === NO_FIRM ? null : firmId, surgeonId, open);

  // Sélection existante toujours affichée, même si la firme / le type a été désactivé depuis.
  const currentFirm = intervention?.firm ?? null;
  const firmItems = firms.filter((f) => f.active !== false).map(firmPickerItem);
  const firmValue = firmId === NO_FIRM ? null
    : firmItems.find((f) => f.id === firmId) ?? (currentFirm?.id === firmId ? firmPickerItem(currentFirm) : null);
  const currentType = intervention?.interventionType ?? null;
  const typeItems = interventionTypes.map(typePickerItem);
  const typeValue = typeId == null ? null
    : typeItems.find((t) => t.id === typeId) ?? (currentType?.id === typeId ? typePickerItem(currentType) : null);

  return (
    <SheetModal open={open} title="Modifier l'intervention" onClose={onClose} closeDisabled={loading} helpTopicId="mission-encoding">
      <Box sx={{ mt: "18px", display: "flex", flexDirection: "column", gap: "16px" }}>
        <SearchablePicker
          id="edit-itv-firm"
          label="Firme principale"
          searchLabel="Rechercher une firme"
          searchPlaceholder="Rechercher une firme…"
          items={firmItems}
          recent={(recentFirmsQuery.data?.firms ?? []).map(firmPickerItem)}
          recentTitle={RECENT_FIRMS_TITLE}
          recentLoading={recentFirmsQuery.isLoading}
          allTitle="Toutes les firmes"
          emptyText="Aucune firme ne correspond."
          value={firmValue}
          onSelect={(v) => { if (v === firmId) return; setFirmId(v); setRepresentativePresent(null); setSelectedChoiceOptionId(null); }}
          disabled={loading}
        />

        <SearchablePicker
          id="edit-itv-type"
          label="Type d'intervention *"
          searchLabel="Rechercher un type d'intervention"
          searchPlaceholder="Code ou libellé (ex. LCA, ligament)…"
          items={typeItems}
          recent={(recentTypesQuery.data?.interventionTypes ?? []).map(typePickerItem)}
          recentTitle={RECENT_TYPES_TITLE}
          recentLoading={recentTypesQuery.isLoading}
          allTitle="Toutes les interventions"
          emptyText="Aucune intervention ne correspond."
          value={typeValue}
          onSelect={(v) => { if (v === typeId) return; setTypeId(v); setRepresentativePresent(null); setSelectedChoiceOptionId(null); }}
          disabled={loading}
        />

        {showRepresentativeQuestion && (
          <RepresentativePresenceQuestion
            firmName={selectedFirmName}
            value={representativePresent}
            onChange={setRepresentativePresent}
            disabled={loading}
            managerCorrection={managerCorrection}
          />
        )}

        {showChoiceQuestion && (
          <Box sx={{ background: "#F5F7FA", borderRadius: "12px", padding: "14px" }}>
            <Box sx={{ fontSize: 14, fontWeight: 700, mb: "10px" }}>{choiceGroup!.question} *</Box>
            <Box sx={{ display: "flex", flexDirection: "column", gap: "8px" }}>
              {activeChoiceOptions.map((option) => (
                <Box
                  key={option.id}
                  component="button"
                  type="button"
                  onClick={() => setSelectedChoiceOptionId(option.id)}
                  disabled={loading}
                  sx={{
                    display: "flex", alignItems: "center", gap: "10px", height: 44, borderRadius: "10px",
                    cursor: "pointer", fontFamily: "inherit", fontSize: 14, fontWeight: 700, textAlign: "left",
                    padding: "0 14px",
                    border: "1.5px solid", borderColor: selectedChoiceOptionId === option.id ? GREEN_500 : BORDER_DEFAULT,
                    background: selectedChoiceOptionId === option.id ? "rgba(66,168,130,.12)" : "#fff",
                    color: selectedChoiceOptionId === option.id ? GREEN_800 : GRAY_800,
                  }}
                >
                  {option.label}
                </Box>
              ))}
            </Box>
            {selectedChoiceOptionId === null && (
              <Box sx={{ mt: "10px", fontSize: 12.5, color: RED_600, fontWeight: 600 }}>
                Une réponse est requise pour cette prestation.
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
