import { Box } from "@mui/material";

const GREEN_500 = "#42A882";
const GREEN_800 = "#1F6B4F";
const GRAY_500 = "#727E8C";
const GRAY_800 = "#243240";
const RED_600 = "#E5484D";
const BORDER_DEFAULT = "#DDE2E8";

/**
 * Réponse à « un délégué de la firme était-il présent ? ». `"UNKNOWN"` (D-172) : correction
 * manager d'une donnée historique inconnue — enregistrée inconnue, jamais « non ».
 */
export type RepresentativeAnswer = boolean | "UNKNOWN" | null;

/** Corps d'écriture correspondant à une réponse (null = aucune réponse donnée). */
export function representativeAnswerBody(answer: RepresentativeAnswer): { representativePresent: boolean | null; representativePresenceUnknown?: true } {
  return answer === "UNKNOWN" ? { representativePresent: null, representativePresenceUnknown: true } : { representativePresent: answer };
}

type Props = {
  firmName: string;
  value: RepresentativeAnswer;
  onChange: (value: RepresentativeAnswer) => void;
  disabled?: boolean;
  /** D-172 — correction manager : « Délégué présent / Aucun délégué / Information non renseignée ». */
  managerCorrection?: boolean;
  mt?: string;
};

/**
 * Refonte Catalogue/Prestations (D-092) — la question n'est posée que si la prestation la rend
 * pertinente (décidé par l'appelant depuis le catalogue) ; ses conséquences tarifaires sont
 * décidées exclusivement par le backend.
 */
export default function RepresentativePresenceQuestion({ firmName, value, onChange, disabled, managerCorrection, mt }: Props) {
  const options: { value: Exclude<RepresentativeAnswer, null>; label: string }[] = managerCorrection
    ? [
      { value: true, label: "Délégué présent" },
      { value: false, label: "Aucun délégué" },
      { value: "UNKNOWN", label: "Information non renseignée" },
    ]
    : [{ value: true, label: "Oui" }, { value: false, label: "Non" }];

  return (
    <Box sx={{ mt, background: "#F5F7FA", borderRadius: "12px", padding: "14px" }} data-testid="representative-question">
      <Box sx={{ fontSize: 14, fontWeight: 700, mb: "4px" }}>Un délégué de {firmName} était-il présent ?</Box>
      <Box sx={{ fontSize: 12.5, color: GRAY_500, mb: "10px" }}>
        {managerCorrection
          ? `Délégué commercial ou technique de ${firmName} — pas l'instrumentiste SurgicalHub. « Information non renseignée » garde la donnée inconnue : l'encodage ne pourra pas être validé tant qu'elle est requise.`
          : "Cette information est nécessaire pour cette intervention."}
      </Box>
      <Box sx={{ display: "flex", gap: "10px", flexWrap: "wrap" }}>
        {options.map((option) => {
          const selected = value === option.value;
          return (
            <Box
              key={String(option.value)}
              component="button"
              type="button"
              aria-pressed={selected}
              onClick={() => onChange(option.value)}
              disabled={disabled}
              sx={{
                flex: "1 1 120px", minHeight: 42, borderRadius: "10px", cursor: "pointer", fontFamily: "inherit",
                fontSize: 14, fontWeight: 700, padding: "6px 10px",
                border: "1.5px solid", borderColor: selected ? GREEN_500 : BORDER_DEFAULT,
                background: selected ? "rgba(66,168,130,.12)" : "#fff",
                color: selected ? GREEN_800 : GRAY_800,
              }}
            >
              {option.label}
            </Box>
          );
        })}
      </Box>
      {value === null && (
        <Box sx={{ mt: "10px", fontSize: 12.5, color: RED_600, fontWeight: 600 }}>
          Indiquez si un délégué de {firmName} était présent.
        </Box>
      )}
    </Box>
  );
}
