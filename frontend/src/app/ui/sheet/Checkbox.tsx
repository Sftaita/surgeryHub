import { Box } from "@mui/material";

const GREEN_600 = "#338F6E";
const GRAY_300 = "#C2C9D1";
const GRAY_400 = "#98A2AE";
const GRAY_600 = "#566270";

function CheckIcon() {
  return (
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="3.4" strokeLinecap="round" strokeLinejoin="round">
      <path d="M20 6 9 17l-5-5" />
    </svg>
  );
}

type Props = {
  checked: boolean;
  onChange: () => void;
  label: React.ReactNode;
  ariaLabel: string;
  /** docs/design (heures-prestées, déclarer-mission) — la ligne "Se termine le lendemain" est indentée 86px pour s'aligner sous les StepperRow. */
  indent?: number;
};

/** docs/design — case à cocher 22×22, radius 6 ; cochée = fond green-600 + coche blanche. */
export function CheckboxRow({ checked, onChange, label, ariaLabel, indent = 0 }: Props) {
  return (
    <Box sx={{ display: "flex", alignItems: "center", gap: "10px", padding: `2px 0 2px ${indent}px` }}>
      <Box
        component="button"
        type="button"
        onClick={onChange}
        aria-label={ariaLabel}
        aria-pressed={checked}
        sx={{
          width: 22, height: 22, borderRadius: "6px", flexShrink: 0, display: "flex", alignItems: "center", justifyContent: "center",
          cursor: "pointer", border: "none", padding: 0, transition: "all 150ms",
          background: checked ? GREEN_600 : "#fff",
          boxShadow: checked ? "none" : `inset 0 0 0 1.5px ${GRAY_300}`,
        }}
      >
        {checked && <CheckIcon />}
      </Box>
      <Box
        component="span"
        onClick={onChange}
        sx={{ fontSize: 13.5, fontWeight: 600, color: GRAY_600, cursor: "pointer" }}
      >
        {label}
      </Box>
    </Box>
  );
}

export { GRAY_400 };
