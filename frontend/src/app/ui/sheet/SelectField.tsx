import * as React from "react";
import { Box } from "@mui/material";

const GRAY_50 = "#F7F9FA";
const GRAY_100 = "#EFF2F5";
const GRAY_200 = "#DDE2E8";
const GRAY_400 = "#98A2AE";
const GRAY_700 = "#3A4754";
const GRAY_900 = "#16202B";
const GREEN_50 = "#EFFAF5";
const GREEN_300 = "#8FDABF";
const GREEN_500 = "#42A882";
const GREEN_600 = "#338F6E";
const GREEN_800 = "#1F6B4F";
const SHADOW_MD = "0 2px 6px rgba(22,32,43,.06), 0 8px 20px rgba(22,32,43,.08)";

function ChevronIcon({ open }: { open: boolean }) {
  return (
    <Box
      component="svg"
      width={16}
      height={16}
      viewBox="0 0 24 24"
      fill="none"
      stroke={GRAY_400}
      strokeWidth={2.2}
      strokeLinecap="round"
      strokeLinejoin="round"
      sx={{ flexShrink: 0, transition: "transform 200ms", transform: open ? "rotate(180deg)" : "rotate(0deg)" }}
    >
      <path d="m6 9 6 6 6-6" />
    </Box>
  );
}

function CheckIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={GREEN_600} strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round">
      <path d="M20 6 9 17l-5-5" />
    </svg>
  );
}

export type SelectFieldOption<T extends string | number> = {
  value: T;
  label: string;
};

type Props<T extends string | number> = {
  id?: string;
  label: string;
  placeholder: string;
  value: T | null;
  options: SelectFieldOption<T>[];
  onChange: (value: T) => void;
  disabled?: boolean;
};

/**
 * docs/design/components/select-field.md + prototypes/SelectField.dc.html — liste
 * déroulante inline (pousse le contenu, pas d'overlay/portal), jamais un <select>
 * natif. Adapté au réel avec des options {value, label} (le prototype ne prend que
 * des strings) pour pouvoir porter des ids numériques (site, chirurgien) ou un enum.
 */
export function SelectField<T extends string | number>({
  id,
  label,
  placeholder,
  value,
  options,
  onChange,
  disabled,
}: Props<T>) {
  const [open, setOpen] = React.useState(false);
  const rootRef = React.useRef<HTMLDivElement>(null);
  const selected = options.find((o) => o.value === value) ?? null;

  React.useEffect(() => {
    if (!open) return;

    function handlePointerDown(e: PointerEvent) {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape") setOpen(false);
    }

    document.addEventListener("pointerdown", handlePointerDown);
    document.addEventListener("keydown", handleKeyDown);
    return () => {
      document.removeEventListener("pointerdown", handlePointerDown);
      document.removeEventListener("keydown", handleKeyDown);
    };
  }, [open]);

  return (
    <Box ref={rootRef} sx={{ display: "flex", flexDirection: "column", gap: "7px", width: "100%" }}>
      <Box component="label" htmlFor={id} sx={{ fontSize: 13, fontWeight: 700, color: GRAY_700 }}>
        {label}
      </Box>

      <Box
        component="button"
        id={id}
        type="button"
        role="combobox"
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => !disabled && setOpen((v) => !v)}
        disabled={disabled}
        sx={{
          display: "flex", alignItems: "center", gap: "10px", height: 50, padding: "0 14px",
          borderRadius: "12px", background: "#fff", cursor: disabled ? "default" : "pointer",
          fontFamily: "inherit", width: "100%", transition: "border-color 150ms",
          border: "1.5px solid", borderColor: open ? GREEN_500 : GRAY_200,
          opacity: disabled ? 0.6 : 1,
        }}
      >
        <Box
          component="span"
          sx={{
            flex: 1, minWidth: 0, textAlign: "left", fontSize: 15, whiteSpace: "nowrap",
            overflow: "hidden", textOverflow: "ellipsis",
            color: selected ? GRAY_900 : GRAY_400, fontWeight: selected ? 600 : 400,
          }}
        >
          {selected ? selected.label : placeholder}
        </Box>
        <ChevronIcon open={open} />
      </Box>

      {open && (
        <Box
          role="listbox"
          sx={{
            background: "#fff", border: "1.5px solid", borderColor: GREEN_300, borderRadius: "14px",
            overflow: "hidden", boxShadow: SHADOW_MD,
            animation: "sfPop 180ms cubic-bezier(0.22,1,0.36,1)",
            "@keyframes sfPop": { from: { opacity: 0, transform: "translateY(-5px)" }, to: { opacity: 1, transform: "none" } },
          }}
        >
          {options.map((o, i) => {
            const isSelected = o.value === value;
            return (
              <Box
                key={String(o.value)}
                component="button"
                type="button"
                role="option"
                aria-selected={isSelected}
                onClick={() => {
                  onChange(o.value);
                  setOpen(false);
                }}
                sx={{
                  display: "flex", alignItems: "center", gap: "10px", width: "100%", minHeight: 48,
                  padding: "10px 14px", border: "none",
                  borderBottom: i < options.length - 1 ? "1px solid" : "none", borderBottomColor: GRAY_100,
                  cursor: "pointer", textAlign: "left", fontFamily: "inherit", fontSize: 14.5,
                  fontWeight: isSelected ? 700 : 600,
                  background: isSelected ? GREEN_50 : "transparent",
                  color: isSelected ? GREEN_800 : GRAY_700,
                  "&:hover": { background: isSelected ? GREEN_50 : GRAY_50 },
                }}
              >
                <Box component="span" sx={{ flex: 1, minWidth: 0, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                  {o.label}
                </Box>
                {isSelected && <CheckIcon />}
              </Box>
            );
          })}
        </Box>
      )}
    </Box>
  );
}
