import * as React from "react";
import { useQuery } from "@tanstack/react-query";
import { Box } from "@mui/material";
import { SheetModal } from "../../../ui/sheet/SheetModal";
import { Field } from "../../../ui/sheet/Field";
import type { CatalogFirm, CatalogInterventionType, CreateInterventionBody } from "../api/encoding.types";
import { fetchFirmServiceOfferings, type FirmServiceOfferingSummary } from "../api/encoding.api";
import { useRecentFirms, useRecentInterventionTypes } from "../api/useEncodingSuggestions";
import RepresentativePresenceQuestion, { representativeAnswerBody, type RepresentativeAnswer } from "./RepresentativePresenceQuestion";
import SearchablePicker, { type PickerItem } from "./SearchablePicker";

const GREEN_500 = "#42A882";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_100 = "#EFF2F5";
const GRAY_200 = "#DDE2E8";
const GRAY_300 = "#C2C9D1";
const GRAY_500 = "#727E8C";
const GRAY_800 = "#243240";
const RED_600 = "#E5484D";
const BORDER_DEFAULT = GRAY_200;

/** Sentinel jamais atteignable par un vrai id de type (toujours positifs en base). */
const NOT_IN_CATALOG = -1;

/** D-146 bis — question posée pour chaque intervention (jamais « Aucune firme »). */
export const FIRM_QUESTION = "Avec quelle firme cette intervention a-t-elle été réalisée ?";

type Step = 1 | 2;

export type DraftSubmitValues = {
  label: string;
  suggestedCode?: string;
  comment?: string;
  requestedFirmId?: number;
  /** D-146 bis — firme absente du référentiel, proposée au manager (jamais créée ici). */
  proposedFirmName?: string;
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
  /** D-172 — correction manager : la présence du délégué peut être « non renseignée ». */
  managerCorrection?: boolean;
  /** D-178 — raccourcis « récemment utilisés » (absents sans mission). */
  missionId?: number;
  /** D-178 — chirurgien réellement porté par la mission (clé des interventions récentes). */
  surgeonId?: number | null;
};

function ChevronRightIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={GRAY_300} strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
      <path d="m9 18 6-6-6-6" />
    </svg>
  );
}

export const RECENT_FIRMS_TITLE = "Firmes récemment utilisées";
export const RECENT_TYPES_TITLE = "Interventions récemment utilisées avec cette firme et ce chirurgien";

export function firmPickerItem(f: { id: number; name: string }): PickerItem {
  return { id: f.id, primary: f.name };
}

export function typePickerItem(t: CatalogInterventionType): PickerItem {
  return { id: t.id, primary: `${t.code} — ${t.label}` };
}

/**
 * EPIC Revue instrumentiste, Lot 3, commit 8 — parcours "firme puis intervention" :
 * 1. choix explicite d'une firme (recherche locale sur le référentiel déjà chargé, ou
 *    « Ma firme n'est pas dans la liste » + nom libre, qui force une demande au manager —
 *    D-146 bis : jamais « Aucune firme », jamais de création automatique de firme) ;
 * 2. type d'intervention, choisi dans une liste fermée filtrée aux prestations de la firme
 *    choisie quand elle en a, sinon repliée sur le catalogue complet ; une option explicite
 *    "Intervention absente du catalogue" reste toujours disponible et déclenche une demande
 *    provisoire (draft) au lieu d'une intervention réelle — jamais un texte libre.
 *
 * D-178 — chaque étape propose d'abord ses deux raccourcis « récemment utilisés » (firmes de
 * l'utilisateur ; interventions de la firme × chirurgien de la mission) et une recherche
 * instantanée (SearchablePicker). Un raccourci appelle exactement pickFirm / pickType, comme
 * la liste complète : choix obligatoire, délégué et matériel suggéré suivent le même flux.
 *
 * Remplace l'ancien flux "type d'abord" + le lien séparé vers InterventionTypeRequestDialog
 * (Lot 5/D-068) : les deux se rejoignent maintenant dans un seul parcours cohérent avec ce
 * que l'instrumentiste voit déjà en tête (la firme), plutôt que deux dialogues distincts
 * pour un seul geste métier ("je veux encoder cette intervention").
 */
export default function AddInterventionDialog({
  open, loadingIntervention, loadingDraft, interventionTypes, firms, existingCount, onClose, onSubmit, onSubmitDraft, managerCorrection = false,
  missionId, surgeonId,
}: Props) {
  const [step, setStep] = React.useState<Step>(1);
  const [firmQuery, setFirmQuery] = React.useState("");
  const [selectedFirm, setSelectedFirm] = React.useState<CatalogFirm | null>(null);
  /** Non null = « Ma firme n'est pas dans la liste » : seule une demande est possible. */
  const [proposedFirmName, setProposedFirmName] = React.useState<string | null>(null);

  const [typeId, setTypeId] = React.useState<number | null>(null);
  const [representativePresent, setRepresentativePresent] = React.useState<RepresentativeAnswer>(null);
  const [selectedChoiceOptionId, setSelectedChoiceOptionId] = React.useState<number | null>(null);

  const [draftLabel, setDraftLabel] = React.useState("");
  const [draftSuggestedCode, setDraftSuggestedCode] = React.useState("");
  const [draftComment, setDraftComment] = React.useState("");

  const loading = loadingIntervention || loadingDraft;

  React.useEffect(() => {
    if (!open) return;
    setStep(1);
    setFirmQuery("");
    setSelectedFirm(null);
    setProposedFirmName(null);
    setTypeId(null);
    setRepresentativePresent(null);
    setSelectedChoiceOptionId(null);
    setDraftLabel("");
    setDraftSuggestedCode("");
    setDraftComment("");
  }, [open]);

  const activeFirms = firms.filter((f) => f.active !== false);

  const firmId = selectedFirm?.id ?? null;
  const firmName = selectedFirm?.name ?? proposedFirmName?.trim() ?? "";

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

  const recentFirmsQuery = useRecentFirms(missionId, open);
  const recentTypesQuery = useRecentInterventionTypes(missionId, firmId, surgeonId, open);

  function pickFirm(firm: CatalogFirm) {
    setSelectedFirm(firm);
    setProposedFirmName(null);
    setTypeId(null);
    setRepresentativePresent(null);
    setSelectedChoiceOptionId(null);
    setStep(2);
  }

  /** Firme hors référentiel : le type ne peut pas encore être catalogué pour elle — la
   *  saisie devient une demande au manager (firme + intervention à valider). */
  function proposeFirm() {
    const name = (proposedFirmName ?? "").trim();
    if (name === "") return;
    setSelectedFirm(null);
    setProposedFirmName(name);
    setTypeId(NOT_IN_CATALOG);
    setRepresentativePresent(null);
    setSelectedChoiceOptionId(null);
    setStep(2);
  }

  function pickType(v: number | null) {
    setTypeId(v);
    setRepresentativePresent(null);
    setSelectedChoiceOptionId(null);
  }

  // Refonte Catalogue/Prestations (D-092) — ne demander la présence du délégué que si
  // la prestation (firme × type) le rend pertinent. Jamais posée sans firme connue.
  const currentOffering: FirmServiceOfferingSummary | undefined = firmId != null
    ? (offeringsQuery.data ?? []).find((o) => o.interventionType.id === typeId)
    : undefined;
  const showRepresentativeQuestion = !!currentOffering?.representativePresenceRelevant;

  // Tarification firme conditionnée à un choix obligatoire — même règle : question posée
  // seulement si le groupe est opérationnel pour la firme × type effectivement choisis.
  const choiceGroup = currentOffering?.choiceGroup ?? null;
  const activeChoiceOptions = (choiceGroup?.options ?? []).filter((o) => o.active);
  const showChoiceQuestion = choiceGroup != null;

  const notInCatalog = typeId === NOT_IN_CATALOG;
  const canSubmitIntervention =
    !notInCatalog && typeId != null && firmId != null && !loading
    && (!showRepresentativeQuestion || representativePresent !== null)
    && (!showChoiceQuestion || selectedChoiceOptionId !== null);
  const canSubmitDraft = notInCatalog && draftLabel.trim() !== "" && !loading;

  function handleSubmit() {
    if (notInCatalog) {
      if (!canSubmitDraft) return;
      onSubmitDraft({
        label: draftLabel.trim(),
        suggestedCode: draftSuggestedCode.trim() || undefined,
        comment: draftComment.trim() || undefined,
        ...(firmId != null ? { requestedFirmId: firmId } : { proposedFirmName: (proposedFirmName ?? "").trim() }),
      });
      return;
    }
    if (typeId == null || firmId == null) return;
    onSubmit({
      interventionTypeId: typeId,
      primaryFirmId: firmId,
      orderIndex: existingCount,
      ...(showRepresentativeQuestion && representativePresent !== null ? representativeAnswerBody(representativePresent) : {}),
      ...(showChoiceQuestion && selectedChoiceOptionId !== null ? { selectedChoiceOptionId } : {}),
    });
  }

  const selectedType = typesToShow.find((t) => t.id === typeId);
  const selectedTypeItem: PickerItem | null = typeId === NOT_IN_CATALOG
    ? { id: NOT_IN_CATALOG, primary: "Intervention absente du catalogue" }
    : selectedType ? typePickerItem(selectedType) : null;

  return (
    <SheetModal open={open} title="Ajouter une intervention" onClose={onClose} closeDisabled={loading} helpTopicId="mission-encoding">
      {step === 1 && (
        <Box sx={{ mt: "18px" }}>
          <Box sx={{ fontSize: 14.5, fontWeight: 700 }}>Étape 1/2 – {FIRM_QUESTION}</Box>

          <Box sx={{ mt: "12px" }}>
            <SearchablePicker
              id="add-itv-firm"
              searchLabel="Rechercher une firme"
              searchPlaceholder="Rechercher une firme…"
              items={activeFirms.map(firmPickerItem)}
              recent={(recentFirmsQuery.data?.firms ?? []).map(firmPickerItem)}
              recentTitle={RECENT_FIRMS_TITLE}
              recentLoading={recentFirmsQuery.isLoading}
              allTitle="Toutes les firmes"
              emptyText="Aucune firme ne correspond."
              onSelect={(id) => { const firm = activeFirms.find((f) => f.id === id); if (firm) pickFirm(firm); }}
              onQueryChange={setFirmQuery}
              disabled={loading}
            />
            {proposedFirmName === null ? (
              <Box
                component="button"
                type="button"
                onClick={() => setProposedFirmName(firmQuery.trim())}
                sx={{
                  display: "flex", alignItems: "center", gap: "10px", height: 50, border: "none",
                  borderBottom: "1px solid", borderColor: GRAY_100, background: "transparent",
                  fontFamily: "inherit", fontSize: 14.5, fontWeight: 700, color: GREEN_700, cursor: "pointer",
                  textAlign: "left", width: "100%", padding: "0 4px",
                  "&:hover": { background: "#F5F7FA" },
                }}
              >
                <Box sx={{ flex: 1 }}>Ma firme n'est pas dans la liste</Box>
                <ChevronRightIcon />
              </Box>
            ) : (
              <Box sx={{ mt: "14px", display: "flex", flexDirection: "column", gap: "10px" }}>
                <Field
                  id="add-itv-proposed-firm"
                  label="Nom de la firme *"
                  value={proposedFirmName}
                  onChange={setProposedFirmName}
                  disabled={loading}
                  placeholder="ex: Medacta"
                  helperText="Le manager vérifiera cette firme avant de l'ajouter au catalogue."
                  autoFocus
                />
                <Box
                  component="button"
                  type="button"
                  onClick={proposeFirm}
                  disabled={proposedFirmName.trim() === ""}
                  sx={{
                    width: "100%", height: 46, border: "none", borderRadius: "12px", background: GREEN_800, color: "#fff",
                    fontFamily: "inherit", fontSize: 14.5, fontWeight: 700, cursor: "pointer",
                    "&:disabled": { opacity: 0.6, cursor: "default" },
                  }}
                >
                  Continuer avec cette firme
                </Box>
              </Box>
            )}
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
                {firmName}
                {proposedFirmName !== null && (
                  <Box component="span" sx={{ ml: "8px", fontSize: 12, fontWeight: 600, color: GRAY_500 }}>(à valider par le manager)</Box>
                )}
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

          {proposedFirmName === null && (
            <Box sx={{ mt: "16px" }}>
              <SearchablePicker
                id="add-itv-type"
                label="Type d'intervention *"
                searchLabel="Rechercher un type d'intervention"
                searchPlaceholder="Code ou libellé (ex. LCA, ligament)…"
                items={typesToShow.map(typePickerItem)}
                recent={(recentTypesQuery.data?.interventionTypes ?? []).map(typePickerItem)}
                recentTitle={RECENT_TYPES_TITLE}
                recentLoading={recentTypesQuery.isLoading}
                allTitle={firmTypes.length > 0 ? "Interventions de la firme" : "Toutes les interventions"}
                emptyText="Aucune intervention ne correspond."
                value={selectedTypeItem}
                onSelect={pickType}
                disabled={loading}
                loading={offeringsQuery.isLoading}
                loadingText="Chargement des interventions de la firme…"
                footer={(
                  <Box
                    component="button"
                    type="button"
                    onClick={() => pickType(NOT_IN_CATALOG)}
                    disabled={loading}
                    sx={{
                      display: "flex", alignItems: "center", gap: "10px", minHeight: 50, border: "none",
                      background: "transparent", fontFamily: "inherit", fontSize: 14.5, fontWeight: 700, color: GREEN_700,
                      cursor: "pointer", textAlign: "left", width: "100%", padding: "0 4px",
                      "&:hover": { background: "#F5F7FA" },
                    }}
                  >
                    <Box sx={{ flex: 1 }}>Intervention absente du catalogue</Box>
                    <ChevronRightIcon />
                  </Box>
                )}
              />
            </Box>
          )}

          {proposedFirmName === null && typesToShow.length === 0 && (
            <Box sx={{ mt: "8px", fontSize: 12.5, color: GRAY_500 }}>
              Le catalogue est vide pour le moment.
            </Box>
          )}

          {showRepresentativeQuestion && (
            <RepresentativePresenceQuestion
              mt="16px"
              firmName={firmName}
              value={representativePresent}
              onChange={setRepresentativePresent}
              disabled={loading}
              managerCorrection={managerCorrection}
            />
          )}

          {showChoiceQuestion && (
            <Box sx={{ mt: "16px", background: "#F5F7FA", borderRadius: "12px", padding: "14px" }}>
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
