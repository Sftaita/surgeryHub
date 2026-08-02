import * as React from "react";
import { useQuery } from "@tanstack/react-query";
import { Box } from "@mui/material";
import { SheetModal } from "../../../ui/sheet/SheetModal";
import { SelectField } from "../../../ui/sheet/SelectField";
import { Field } from "../../../ui/sheet/Field";
import type { CatalogFirm, CatalogInterventionType, CreateInterventionBody } from "../api/encoding.types";
import { fetchInterventionTypeEncodingContext, fetchFirmServiceOfferings, type FirmServiceOfferingSummary } from "../api/encoding.api";

const GREEN_500 = "#42A882";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_100 = "#EFF2F5";
const GRAY_200 = "#DDE2E8";
const GRAY_300 = "#C2C9D1";
const GRAY_400 = "#98A2AE";
const GRAY_500 = "#727E8C";
const GRAY_800 = "#243240";
const RED_600 = "#E5484D";
const BORDER_DEFAULT = GRAY_200;
const FOCUS_RING = "0 0 0 3px rgba(66,168,130,.32)";

/** Sentinel jamais atteignable par un vrai id de firme/type (toujours positifs en base). */
const NO_FIRM = 0;
const NOT_IN_CATALOG = -1;

type Step = 1 | 2;

export type DraftSubmitValues = {
  label: string;
  suggestedCode?: string;
  comment?: string;
  requestedFirmId?: number;
};

type Props = {
  open: boolean;
  loadingIntervention: boolean;
  loadingDraft: boolean;
  interventionTypes: CatalogInterventionType[]; // catalogue actif complet — repli si la firme n'a aucune prestation
  firms: CatalogFirm[];
  existingCount: number; // used to auto-assign orderIndex
  onClose: () => void;
  onSubmit: (values: CreateInterventionBody) => void;
  /** Type absent du catalogue pour cette firme — crée une demande provisoire (draft). */
  onSubmitDraft: (values: DraftSubmitValues) => void;
};

function SearchIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke={GRAY_400} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
    </svg>
  );
}
function ChevronRightIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={GRAY_300} strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
      <path d="m9 18 6-6-6-6" />
    </svg>
  );
}

function searchInputSx() {
  return {
    height: 46, border: "1.5px solid", borderColor: BORDER_DEFAULT, borderRadius: "12px",
    padding: "0 14px 0 42px", fontSize: 15, color: "#16202B", background: "#fff", outline: "none", width: "100%",
    fontFamily: "inherit",
    "&:focus": { borderColor: GREEN_500, boxShadow: FOCUS_RING },
  };
}

/**
 * EPIC Revue instrumentiste, Lot 3, commit 8 — parcours "firme puis intervention" :
 * 1. choix explicite d'une firme (recherche locale sur le référentiel déjà chargé, ou
 *    "Aucune firme" — jamais de création automatique de firme) ;
 * 2. type d'intervention, toujours un `select` (SelectField), filtré aux prestations de
 *    la firme choisie quand elle en a, sinon replié sur le catalogue complet ; une option
 *    explicite "Intervention absente du catalogue" reste toujours disponible et déclenche
 *    une demande provisoire (draft) au lieu d'une intervention réelle — jamais un champ
 *    texte libre avec autocomplete.
 *
 * Remplace l'ancien flux "type d'abord" + le lien séparé vers InterventionTypeRequestDialog
 * (Lot 5/D-068) : les deux se rejoignent maintenant dans un seul parcours cohérent avec ce
 * que l'instrumentiste voit déjà en tête (la firme), plutôt que deux dialogues distincts
 * pour un seul geste métier ("je veux encoder cette intervention").
 */
export default function AddInterventionDialog({
  open, loadingIntervention, loadingDraft, interventionTypes, firms, existingCount, onClose, onSubmit, onSubmitDraft,
}: Props) {
  const [step, setStep] = React.useState<Step>(1);
  const [firmQuery, setFirmQuery] = React.useState("");
  const [selectedFirm, setSelectedFirm] = React.useState<CatalogFirm | typeof NO_FIRM | null>(null);

  const [typeId, setTypeId] = React.useState<number | null>(null);
  const [firmAutoSuggested, setFirmAutoSuggested] = React.useState(false);
  const [representativePresent, setRepresentativePresent] = React.useState<boolean | null>(null);

  const [draftLabel, setDraftLabel] = React.useState("");
  const [draftSuggestedCode, setDraftSuggestedCode] = React.useState("");
  const [draftComment, setDraftComment] = React.useState("");

  const loading = loadingIntervention || loadingDraft;

  React.useEffect(() => {
    if (!open) return;
    setStep(1);
    setFirmQuery("");
    setSelectedFirm(null);
    setTypeId(null);
    setFirmAutoSuggested(false);
    setRepresentativePresent(null);
    setDraftLabel("");
    setDraftSuggestedCode("");
    setDraftComment("");
  }, [open]);

  const activeFirms = firms.filter((f) => f.active !== false);
  const filteredFirms = activeFirms.filter((f) => f.name.toLowerCase().includes(firmQuery.toLowerCase()));

  const firmId = selectedFirm === NO_FIRM ? null : selectedFirm?.id ?? null;

  // Prestations de la firme choisie — accélérateur de saisie, jamais une restriction dure
  // (voir FirmServiceOfferingController) : si la firme n'en a aucune, on retombe sur le
  // catalogue complet plutôt que de bloquer l'instrumentiste sur une liste vide.
  const offeringsQuery = useQuery({
    queryKey: ["firmServiceOfferings", firmId],
    queryFn: () => fetchFirmServiceOfferings(firmId as number),
    enabled: firmId != null && open,
  });

  const firmTypes = (offeringsQuery.data ?? [])
    .filter((o) => o.active !== false)
    .map((o) => o.interventionType);
  const typesToShow = firmId != null && firmTypes.length > 0 ? firmTypes : interventionTypes;

  // Lot 6 : dès qu'un type réel est choisi, si une seule prestation active existe pour ce
  // type, sa firme est pré-sélectionnée (jamais devinée côté frontend) — ne s'applique
  // qu'en repli catalogue complet (firme pas encore choisie), sinon la firme est déjà fixée.
  React.useEffect(() => {
    if (typeId == null || typeId === NOT_IN_CATALOG || firmId != null) return;
    let cancelled = false;
    fetchInterventionTypeEncodingContext(typeId)
      .then((ctx) => {
        if (cancelled || !ctx.suggestedPrimaryFirm) return;
        const match = activeFirms.find((f) => f.id === ctx.suggestedPrimaryFirm!.id);
        if (match) {
          setSelectedFirm(match);
          setFirmAutoSuggested(true);
        }
      })
      .catch(() => {});
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [typeId]);

  function pickFirm(firm: CatalogFirm | typeof NO_FIRM) {
    setSelectedFirm(firm);
    setTypeId(null);
    setFirmAutoSuggested(false);
    setRepresentativePresent(null);
    setStep(2);
  }

  function pickType(v: number | null) {
    setTypeId(v);
    setRepresentativePresent(null);
  }

  // Refonte Catalogue/Prestations (D-092) — ne demander la présence du délégué que si
  // la prestation (firme × type) le rend pertinent. Jamais posée sans firme connue.
  const currentOffering: FirmServiceOfferingSummary | undefined = firmId != null
    ? (offeringsQuery.data ?? []).find((o) => o.interventionType.id === typeId)
    : undefined;
  const showRepresentativeQuestion = !!currentOffering?.representativePresenceRelevant;

  const notInCatalog = typeId === NOT_IN_CATALOG;
  const canSubmitIntervention =
    !notInCatalog && typeId != null && !loading && (!showRepresentativeQuestion || representativePresent !== null);
  const canSubmitDraft = notInCatalog && draftLabel.trim() !== "" && !loading;

  function handleSubmit() {
    if (notInCatalog) {
      if (!canSubmitDraft) return;
      onSubmitDraft({
        label: draftLabel.trim(),
        suggestedCode: draftSuggestedCode.trim() || undefined,
        comment: draftComment.trim() || undefined,
        requestedFirmId: firmId ?? undefined,
      });
      return;
    }
    if (typeId == null) return;
    onSubmit({
      interventionTypeId: typeId,
      primaryFirmId: firmId ?? undefined,
      orderIndex: existingCount,
      ...(showRepresentativeQuestion && representativePresent !== null ? { representativePresent } : {}),
    });
  }

  const typeOptions = [
    ...typesToShow.map((t) => ({ value: t.id, label: `${t.code} — ${t.label}` })),
    { value: NOT_IN_CATALOG, label: "Intervention absente du catalogue" },
  ];

  return (
    <SheetModal open={open} title="Ajouter une intervention" onClose={onClose} closeDisabled={loading} helpTopicId="mission-encoding">
      {step === 1 && (
        <Box sx={{ mt: "18px" }}>
          <Box sx={{ fontSize: 14.5, fontWeight: 700 }}>Étape 1/2 – Choisir une firme</Box>

          <Box sx={{ position: "relative", mt: "12px" }}>
            <Box sx={{ position: "absolute", left: "14px", top: "14px", pointerEvents: "none", display: "flex" }}>
              <SearchIcon />
            </Box>
            <Box
              component="input"
              value={firmQuery}
              onChange={(e: React.ChangeEvent<HTMLInputElement>) => setFirmQuery(e.target.value)}
              placeholder="Rechercher une firme…"
              aria-label="Rechercher une firme"
              sx={searchInputSx()}
            />
          </Box>

          <Box sx={{ mt: "14px" }}>
            <Box
              component="button"
              type="button"
              onClick={() => pickFirm(NO_FIRM)}
              sx={{
                display: "flex", alignItems: "center", gap: "10px", height: 50, border: "none",
                borderBottom: "1px solid", borderColor: GRAY_100, background: "transparent",
                fontFamily: "inherit", fontSize: 14.5, fontWeight: 600, color: GRAY_500, cursor: "pointer",
                textAlign: "left", width: "100%", padding: "0 4px", fontStyle: "italic",
                "&:hover": { background: "#F5F7FA" },
              }}
            >
              <Box sx={{ flex: 1 }}>— Aucune firme (voir tout le catalogue)</Box>
              <ChevronRightIcon />
            </Box>

            {filteredFirms.length === 0 && (
              <Box sx={{ py: "16px", px: "4px", fontSize: 13.5, color: GRAY_400 }}>Aucune firme ne correspond.</Box>
            )}
            {filteredFirms.map((f) => (
              <Box
                key={f.id}
                component="button"
                type="button"
                onClick={() => pickFirm(f)}
                sx={{
                  display: "flex", alignItems: "center", gap: "10px", height: 50, border: "none",
                  borderBottom: "1px solid", borderColor: GRAY_100, background: "transparent",
                  fontFamily: "inherit", fontSize: 14.5, fontWeight: 600, color: GRAY_800, cursor: "pointer",
                  textAlign: "left", width: "100%", padding: "0 4px",
                  "&:hover": { background: "#F5F7FA" },
                  "&:active": { transform: "translateY(0.5px)" },
                }}
              >
                <Box sx={{ flex: 1 }}>{f.name}</Box>
                <ChevronRightIcon />
              </Box>
            ))}
          </Box>

          <Box
            component="button"
            type="button"
            onClick={onClose}
            disabled={loading}
            sx={{ mt: "14px", width: "100%", height: 44, border: "none", background: "transparent", color: GRAY_500, fontFamily: "inherit", fontSize: 14, fontWeight: 600, cursor: "pointer" }}
          >
            Annuler
          </Box>
        </Box>
      )}

      {step === 2 && (
        <Box sx={{ mt: "18px" }}>
          <Box sx={{ fontSize: 14.5, fontWeight: 700 }}>Étape 2/2 – Choisir le type d'intervention</Box>

          <Box sx={{ display: "flex", alignItems: "center", gap: "10px", background: "#F5F7FA", borderRadius: "12px", padding: "12px 14px", mt: "12px" }}>
            <Box sx={{ flex: 1, minWidth: 0 }}>
              <Box sx={{ fontSize: 11.5, color: GRAY_500 }}>Firme</Box>
              <Box sx={{ mt: "1px", fontSize: 15, fontWeight: 700 }}>
                {selectedFirm === NO_FIRM ? "Aucune" : selectedFirm?.name}
              </Box>
            </Box>
            <Box
              component="button"
              type="button"
              onClick={() => setStep(1)}
              disabled={loading}
              sx={{ border: "none", background: "none", p: 0, fontSize: 13.5, fontWeight: 700, color: GREEN_700, cursor: "pointer", fontFamily: "inherit" }}
            >
              Changer
            </Box>
          </Box>

          <Box sx={{ mt: "16px" }}>
            <SelectField
              id="add-itv-type"
              label="Type d'intervention *"
              placeholder="Sélectionner un type"
              value={typeId}
              options={typeOptions}
              onChange={pickType}
              disabled={loading}
            />
          </Box>

          {typesToShow.length === 0 && (
            <Box sx={{ mt: "8px", fontSize: 12.5, color: GRAY_500 }}>
              Le catalogue est vide pour le moment.
            </Box>
          )}

          {firmAutoSuggested && (
            <Box sx={{ mt: "8px", fontSize: 12.5, color: GRAY_500 }}>
              Firme suggérée automatiquement (seule prestation active pour ce type).
            </Box>
          )}

          {showRepresentativeQuestion && (
            <Box sx={{ mt: "16px", background: "#F5F7FA", borderRadius: "12px", padding: "14px" }}>
              <Box sx={{ fontSize: 14, fontWeight: 700, mb: "4px" }}>
                Un délégué de {selectedFirm !== NO_FIRM ? selectedFirm?.name : ""} était-il présent ?
              </Box>
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
                  Indiquez si un délégué de {selectedFirm !== NO_FIRM ? selectedFirm?.name : ""} était présent.
                </Box>
              )}
            </Box>
          )}

          {notInCatalog && (
            <Box sx={{ mt: "16px", display: "flex", flexDirection: "column", gap: "16px" }}>
              <Box sx={{ fontSize: 13, color: GRAY_500, lineHeight: 1.5 }}>
                La demande sera transmise au manager. En attendant sa validation, vous
                pouvez déjà y rattacher du matériel.
              </Box>

              <Field
                id="add-itv-draft-label"
                label="Type d'intervention souhaité *"
                value={draftLabel}
                onChange={setDraftLabel}
                disabled={loading}
                placeholder="ex: Prothèse épaule inversée"
                autoFocus
              />

              <Field
                id="add-itv-draft-code"
                label="Code suggéré"
                value={draftSuggestedCode}
                onChange={(v) => setDraftSuggestedCode(v.toUpperCase())}
                disabled={loading}
                placeholder="ex: PTE-INV"
              />

              <Field
                id="add-itv-draft-comment"
                label="Commentaire"
                value={draftComment}
                onChange={setDraftComment}
                disabled={loading}
                multiline
                minRows={2}
                placeholder="Informations complémentaires pour le manager"
              />
            </Box>
          )}

          <Box
            component="button"
            type="button"
            onClick={handleSubmit}
            disabled={notInCatalog ? !canSubmitDraft : !canSubmitIntervention}
            sx={{
              mt: "20px", width: "100%", height: 52, border: "none", borderRadius: "12px",
              background: GREEN_800, color: "#fff", fontFamily: "inherit", fontSize: 15, fontWeight: 700,
              cursor: "pointer", boxShadow: "0 5px 14px rgba(20,77,56,.3)",
              "&:hover": { background: "#144D38" }, "&:active": { transform: "translateY(0.5px)" },
              "&:disabled": { opacity: 0.6, cursor: "default", boxShadow: "none" },
            }}
          >
            {loading ? "…" : notInCatalog ? "Envoyer la demande" : "Ajouter"}
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
        </Box>
      )}
    </SheetModal>
  );
}
