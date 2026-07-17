import * as React from "react";
import { Box } from "@mui/material";
import { SheetModal } from "../../../ui/sheet/SheetModal";
import { StepperRow } from "../../../ui/sheet/StepperRow";
import type { CatalogFirm, CatalogItem, CreateMaterialLineBody } from "../api/encoding.types";

const GREEN_50 = "#EFFAF5";
const GREEN_500 = "#42A882";
const GREEN_600 = "#338F6E";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GREEN_100 = "#DDF4EA";
const GRAY_100 = "#EFF2F5";
const GRAY_150 = "#E7EBEF";
const GRAY_200 = "#DDE2E8";
const GRAY_300 = "#C2C9D1";
const GRAY_400 = "#98A2AE";
const GRAY_500 = "#727E8C";
const GRAY_700 = "#3A4754";
const GRAY_800 = "#243240";
const AMBER_50 = "#FEF6E7";
const AMBER_600 = "#D9920B";
const AMBER_700 = "#B7791F";
const BORDER_DEFAULT = GRAY_200;
const FOCUS_RING = "0 0 0 3px rgba(66,168,130,.32)";

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
function PlusRoundIcon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 5v14M5 12h14" />
    </svg>
  );
}
function PackageIcon() {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke={GREEN_800} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z" />
      <path d="m3.3 7 8.7 5 8.7-5M12 22V12" />
    </svg>
  );
}
function CheckIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
      <path d="M20 6 9 17l-5-5" />
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

type Step = 1 | 2 | 3;

type Props = {
  open: boolean;
  loading: boolean;
  interventionId: number | null;
  catalog?: { items: CatalogItem[]; firms: CatalogFirm[] };
  /** Marques déjà utilisées ailleurs dans cette mission — dérivé de données réelles, jamais inventé. */
  recentFirmIds?: number[];
  onClose: () => void;
  onSubmit: (values: CreateMaterialLineBody) => void;
  onNotFound?: (interventionId: number) => void;
};

/**
 * docs/design/screens/ajout-materiel/README.md — wizard 3 étapes exactement
 * (Marque → Matériel → Détails). Ne jamais fusionner en un seul formulaire.
 */
export default function MaterialWizard({
  open, loading, interventionId, catalog, recentFirmIds = [], onClose, onSubmit, onNotFound,
}: Props) {
  const [step, setStep] = React.useState<Step>(1);
  const [brandQuery, setBrandQuery] = React.useState("");
  const [selectedFirm, setSelectedFirm] = React.useState<CatalogFirm | null>(null);
  const [matQuery, setMatQuery] = React.useState("");
  const [selectedItem, setSelectedItem] = React.useState<CatalogItem | null>(null);
  const [qty, setQty] = React.useState(1);
  const [comment, setComment] = React.useState("");

  React.useEffect(() => {
    if (!open) return;
    setStep(1);
    setBrandQuery("");
    setSelectedFirm(null);
    setMatQuery("");
    setSelectedItem(null);
    setQty(1);
    setComment("");
  }, [open]);

  const firms = (catalog?.firms ?? []).filter((f) => f.active !== false);
  const items = (catalog?.items ?? []).filter((i) => i.active !== false);

  const recentFirms = firms.filter((f) => recentFirmIds.includes(f.id));
  const filteredFirms = firms.filter((f) => f.name.toLowerCase().includes(brandQuery.toLowerCase()));

  const materialsForFirm = selectedFirm ? items.filter((i) => i.firm.id === selectedFirm.id) : [];
  const filteredMaterials = materialsForFirm.filter((i) => i.label.toLowerCase().includes(matQuery.toLowerCase()));

  function pickFirm(firm: CatalogFirm) {
    setSelectedFirm(firm);
    setMatQuery("");
    setStep(2);
  }
  function pickMaterial(item: CatalogItem) {
    setSelectedItem(item);
    setQty(1);
    setStep(3);
  }
  function handleNotFound() {
    if (interventionId == null || !onNotFound) return;
    onClose();
    onNotFound(interventionId);
  }
  function handleAdd() {
    if (interventionId == null || !selectedItem) return;
    onSubmit({
      missionInterventionId: interventionId,
      itemId: selectedItem.id,
      quantity: String(qty),
      comment: comment.trim(),
    });
  }

  const stepDot = (n: Step, label: string) => {
    const done = step > n;
    const active = step === n;
    return (
      <Box sx={{ width: 76, flexShrink: 0, display: "flex", flexDirection: "column", alignItems: "center", gap: "6px" }}>
        <Box
          sx={{
            width: 30, height: 30, borderRadius: "999px", display: "flex", alignItems: "center", justifyContent: "center",
            fontSize: 13, fontWeight: 800,
            background: done || active ? GREEN_600 : "#fff",
            color: done || active ? "#fff" : GRAY_400,
            boxShadow: done || active ? "none" : `inset 0 0 0 1.5px ${GRAY_200}`,
          }}
        >
          {done ? <CheckIcon /> : n}
        </Box>
        <Box sx={{ fontSize: 12, fontWeight: 600, color: GRAY_500 }}>{label}</Box>
      </Box>
    );
  };
  const connector = (n: 1 | 2) => (
    <Box sx={{ flex: 1, height: "2px", mt: "14px", borderRadius: "2px", background: step > n ? GREEN_600 : GRAY_200 }} />
  );

  return (
    <SheetModal
      open={open}
      title="Ajouter du matériel"
      onClose={onClose}
      closeDisabled={loading}
      steps={
        <Box sx={{ display: "flex", alignItems: "flex-start", mt: "18px" }}>
          {stepDot(1, "Marque")}
          {connector(1)}
          {stepDot(2, "Matériel")}
          {connector(2)}
          {stepDot(3, "Détails")}
        </Box>
      }
    >
      {step === 1 && (
        <Box sx={{ mt: "20px" }}>
          <Box sx={{ fontSize: 14.5, fontWeight: 700 }}>Étape 1/3 – Choisir une marque</Box>
          <Box sx={{ position: "relative", mt: "12px" }}>
            <Box sx={{ position: "absolute", left: "14px", top: "14px", pointerEvents: "none", display: "flex" }}>
              <SearchIcon />
            </Box>
            <Box
              component="input"
              value={brandQuery}
              onChange={(e: React.ChangeEvent<HTMLInputElement>) => setBrandQuery(e.target.value)}
              placeholder="Rechercher une marque…"
              aria-label="Rechercher une marque"
              sx={searchInputSx()}
            />
          </Box>

          {recentFirms.length > 0 && (
            <>
              <Box sx={{ mt: "16px", fontSize: 12, fontWeight: 800, letterSpacing: "0.06em", color: GRAY_500 }}>
                MARQUES RÉCENTES
              </Box>
              <Box sx={{ display: "flex", gap: "8px", flexWrap: "wrap", mt: "9px" }}>
                {recentFirms.map((f) => (
                  <Box
                    key={f.id}
                    component="button"
                    type="button"
                    onClick={() => pickFirm(f)}
                    sx={{
                      height: 34, px: "13px", borderRadius: "999px", border: "1px solid", borderColor: BORDER_DEFAULT,
                      background: "#fff", color: GRAY_700, fontSize: 13, fontWeight: 600, cursor: "pointer", fontFamily: "inherit",
                      "&:hover": { borderColor: GREEN_500, background: GREEN_50 },
                      "&:active": { transform: "scale(.96)" },
                    }}
                  >
                    {f.name}
                  </Box>
                ))}
              </Box>
            </>
          )}

          <Box sx={{ mt: "18px", fontSize: 12, fontWeight: 800, letterSpacing: "0.06em", color: GRAY_500 }}>
            TOUTES LES MARQUES
          </Box>
          <Box sx={{ mt: "6px" }}>
            {filteredFirms.length === 0 && (
              <Box sx={{ py: "16px", px: "4px", fontSize: 13.5, color: GRAY_400 }}>Aucune marque ne correspond.</Box>
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
        <Box sx={{ mt: "20px" }}>
          <Box sx={{ fontSize: 14.5, fontWeight: 700 }}>Étape 2/3 – Rechercher un matériel</Box>
          <Box sx={{ display: "flex", alignItems: "center", gap: "10px", background: "#F5F7FA", borderRadius: "12px", padding: "12px 14px", mt: "12px" }}>
            <Box sx={{ flex: 1, minWidth: 0 }}>
              <Box sx={{ fontSize: 11.5, color: GRAY_500 }}>Marque sélectionnée</Box>
              <Box sx={{ mt: "1px", fontSize: 15, fontWeight: 700 }}>{selectedFirm?.name}</Box>
            </Box>
            <Box
              component="button"
              type="button"
              onClick={() => { setSelectedFirm(null); setStep(1); }}
              sx={{ border: "none", background: "none", p: 0, fontSize: 13.5, fontWeight: 700, color: GREEN_700, cursor: "pointer", fontFamily: "inherit" }}
            >
              Changer
            </Box>
          </Box>
          <Box sx={{ position: "relative", mt: "12px" }}>
            <Box sx={{ position: "absolute", left: "14px", top: "14px", pointerEvents: "none", display: "flex" }}>
              <SearchIcon />
            </Box>
            <Box
              component="input"
              value={matQuery}
              onChange={(e: React.ChangeEvent<HTMLInputElement>) => setMatQuery(e.target.value)}
              placeholder="Rechercher un matériel…"
              aria-label="Rechercher un matériel"
              sx={searchInputSx()}
            />
          </Box>
          <Box sx={{ mt: "14px", fontSize: 12, fontWeight: 800, letterSpacing: "0.06em", color: GRAY_500 }}>RÉSULTATS</Box>
          <Box>
            {filteredMaterials.length === 0 && (
              <Box sx={{ py: "16px", px: "4px", fontSize: 13.5, color: GRAY_400 }}>Aucun résultat pour cette recherche.</Box>
            )}
            {filteredMaterials.map((it) => (
              <Box key={it.id} sx={{ display: "flex", alignItems: "center", gap: "10px", padding: "11px 4px", borderBottom: "1px solid", borderColor: GRAY_100 }}>
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Box sx={{ fontSize: 14, fontWeight: 600, color: GRAY_800 }}>{it.label}</Box>
                  <Box sx={{ mt: "1px", fontSize: 12, color: GRAY_400 }}>{it.firm.name}</Box>
                </Box>
                <Box
                  component="button"
                  type="button"
                  onClick={() => pickMaterial(it)}
                  aria-label={`Ajouter ${it.label}`}
                  sx={{
                    width: 34, height: 34, border: "1.5px solid", borderColor: GREEN_500, borderRadius: "999px",
                    background: "#fff", color: GREEN_700, flexShrink: 0, display: "flex", alignItems: "center", justifyContent: "center",
                    cursor: "pointer", "&:hover": { background: GREEN_50 },
                    "&:active": { transform: "scale(.96)" },
                  }}
                >
                  <PlusRoundIcon />
                </Box>
              </Box>
            ))}
          </Box>
          <Box
            component="button"
            type="button"
            onClick={handleNotFound}
            disabled={loading}
            sx={{
              mt: "14px", height: 48, border: "1.5px solid", borderColor: BORDER_DEFAULT, borderRadius: "12px",
              background: "#fff", color: GRAY_700, fontSize: 14, fontWeight: 600, width: "100%", cursor: "pointer", fontFamily: "inherit",
              "&:hover": { borderColor: AMBER_600, color: AMBER_700, background: AMBER_50 },
              "&:active": { transform: "translateY(0.5px)" },
            }}
          >
            Je ne trouve pas le matériel
          </Box>
        </Box>
      )}

      {step === 3 && selectedItem && (
        <Box sx={{ mt: "20px" }}>
          <Box sx={{ fontSize: 14.5, fontWeight: 700 }}>Étape 3/3 – Détails du matériel</Box>
          <Box sx={{ display: "flex", alignItems: "center", gap: "12px", background: "#F5F7FA", borderRadius: "12px", padding: "13px 14px", mt: "12px" }}>
            <Box sx={{ width: 44, height: 44, borderRadius: "11px", background: GREEN_100, display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}>
              <PackageIcon />
            </Box>
            <Box sx={{ flex: 1, minWidth: 0 }}>
              <Box sx={{ fontSize: 11.5, color: GRAY_500 }}>Matériel sélectionné</Box>
              <Box sx={{ mt: "1px", fontSize: 15, fontWeight: 700 }}>{selectedItem.label}</Box>
              <Box sx={{ fontSize: 12.5, color: GRAY_500 }}>{selectedItem.firm.name}</Box>
            </Box>
          </Box>

          <Box sx={{ mt: "16px", fontSize: 13, fontWeight: 700, color: GRAY_700 }}>Quantité *</Box>
          <Box sx={{ mt: "8px" }}>
            <StepperRow
              value={String(qty)}
              onMinus={() => setQty((q) => Math.max(1, q - 1))}
              onPlus={() => setQty((q) => q + 1)}
              minusDisabled={qty <= 1}
              minusAriaLabel="Moins"
              plusAriaLabel="Plus"
            />
          </Box>

          <Box sx={{ mt: "16px", fontSize: 13, fontWeight: 700, color: GRAY_700 }}>
            Commentaire <Box component="span" sx={{ fontWeight: 400, color: GRAY_400 }}>(optionnel)</Box>
          </Box>
          <Box
            component="input"
            value={comment}
            onChange={(e: React.ChangeEvent<HTMLInputElement>) => setComment(e.target.value)}
            placeholder="Ex. utilisé pour fixation tibiale"
            sx={{
              mt: "8px", height: 50, border: "1.5px solid", borderColor: BORDER_DEFAULT, borderRadius: "12px",
              padding: "0 14px", fontSize: 15, color: "#16202B", background: "#fff", outline: "none", width: "100%", fontFamily: "inherit",
              "&:focus": { borderColor: GREEN_500, boxShadow: FOCUS_RING },
            }}
          />

          <Box
            component="button"
            type="button"
            onClick={handleAdd}
            disabled={loading}
            sx={{
              mt: "18px", height: 52, border: "none", borderRadius: "12px", background: GREEN_700, color: "#fff",
              fontSize: 15, fontWeight: 700, width: "100%", cursor: "pointer", fontFamily: "inherit",
              boxShadow: "0 5px 14px rgba(20,77,56,.3)", "&:hover": { background: GREEN_800 },
              "&:active": { transform: "translateY(0.5px)" },
              "&:disabled": { opacity: 0.7, cursor: "default" },
            }}
          >
            {loading ? "…" : "Ajouter à l'intervention"}
          </Box>
          <Box
            component="button"
            type="button"
            onClick={() => setStep(2)}
            disabled={loading}
            sx={{ mt: "8px", height: 44, border: "none", background: "transparent", color: GRAY_500, fontFamily: "inherit", fontSize: 14, fontWeight: 600, cursor: "pointer", width: "100%" }}
          >
            Retour
          </Box>
        </Box>
      )}
    </SheetModal>
  );
}
