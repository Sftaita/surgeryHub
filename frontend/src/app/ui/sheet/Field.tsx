import { Box } from "@mui/material";

const GRAY_200 = "#DDE2E8";
const GRAY_400 = "#98A2AE";
const GRAY_700 = "#3A4754";
const GRAY_900 = "#16202B";
const GREEN_500 = "#42A882";
const FOCUS_RING = "0 0 0 3px rgba(66,168,130,.32)";

type Props = {
  id?: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  disabled?: boolean;
  multiline?: boolean;
  minRows?: number;
  type?: string;
  inputMode?: "text" | "decimal" | "numeric";
  helperText?: string;
  autoFocus?: boolean;
};

/**
 * docs/design/components/field.md — champ de saisie du sheet (variante dense 50px/12px
 * déjà utilisée par MaterialWizard/EditServiceHoursDialog), jamais un `TextField` MUI nu
 * dans un `SheetModal`.
 */
export function Field({
  id, label, value, onChange, placeholder, disabled, multiline, minRows = 3, type = "text",
  inputMode, helperText, autoFocus,
}: Props) {
  return (
    <Box sx={{ display: "flex", flexDirection: "column", gap: "7px", width: "100%" }}>
      <Box component="label" htmlFor={id} sx={{ fontSize: 13, fontWeight: 700, color: GRAY_700 }}>
        {label}
      </Box>
      <Box
        component={multiline ? "textarea" : "input"}
        id={id}
        type={multiline ? undefined : type}
        inputMode={inputMode}
        value={value}
        onChange={(e: React.ChangeEvent<HTMLInputElement & HTMLTextAreaElement>) => onChange(e.target.value)}
        placeholder={placeholder}
        disabled={disabled}
        autoFocus={autoFocus}
        rows={multiline ? minRows : undefined}
        sx={{
          minHeight: multiline ? undefined : 50,
          height: multiline ? undefined : 50,
          border: "1.5px solid", borderColor: GRAY_200, borderRadius: "12px",
          padding: multiline ? "12px 14px" : "0 14px", fontSize: 15, color: GRAY_900,
          background: "#fff", outline: "none", width: "100%", fontFamily: "inherit",
          resize: multiline ? "vertical" : undefined,
          "&::placeholder": { color: GRAY_400 },
          "&:focus": { borderColor: GREEN_500, boxShadow: FOCUS_RING },
          "&:disabled": { opacity: 0.6, cursor: "default" },
        }}
      />
      {helperText && (
        <Box sx={{ fontSize: 12, color: GRAY_400 }}>{helperText}</Box>
      )}
    </Box>
  );
}
