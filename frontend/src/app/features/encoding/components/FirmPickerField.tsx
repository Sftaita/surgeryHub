import * as React from "react";
import { Box } from "@mui/material";
import { Field } from "../../../ui/sheet/Field";
import type { CatalogFirm } from "../api/encoding.types";

const GRAY_200 = "#DDE2E8";
const GRAY_500 = "#727E8C";
const GRAY_700 = "#3A4754";
const GRAY_900 = "#16202B";
const GREEN_50 = "#EFFAF5";
const GREEN_500 = "#42A882";
const GREEN_800 = "#1F6B4F";

/**
 * D-146 bis — firme obligatoire d'une demande (matériel ou intervention) : une firme
 * existante du référentiel OU un nom proposé librement (« Ma firme n'est pas dans la
 * liste »). Jamais « Aucune firme » ; la firme proposée n'est jamais créée côté
 * instrumentiste — c'est le manager qui la valide.
 */
export type FirmChoice =
  | { kind: "EXISTING"; firm: CatalogFirm }
  | { kind: "PROPOSED"; name: string };

export function firmChoiceIsComplete(choice: FirmChoice | null): boolean {
  if (choice == null) return false;
  return choice.kind === "EXISTING" || choice.name.trim() !== "";
}

/** Corps API commun : exactement l'un des deux champs. */
export function firmChoiceBody(choice: FirmChoice | null, idKey: "firmId" | "requestedFirmId"): Record<string, number | string> {
  if (choice == null) return {};
  return choice.kind === "EXISTING" ? { [idKey]: choice.firm.id } : { proposedFirmName: choice.name.trim() };
}

type Props = {
  id: string;
  label: string;
  firms: CatalogFirm[];
  value: FirmChoice | null;
  onChange: (value: FirmChoice | null) => void;
  disabled?: boolean;
};

export default function FirmPickerField({ id, label, firms, value, onChange, disabled }: Props) {
  const [query, setQuery] = React.useState("");
  const active = React.useMemo(
    () => firms.filter((f) => f.active !== false).slice().sort((a, b) => a.name.localeCompare(b.name, "fr")),
    [firms],
  );
  const q = query.trim().toLowerCase();
  const matches = (q === "" ? active : active.filter((f) => f.name.toLowerCase().includes(q))).slice(0, 8);

  if (value?.kind === "EXISTING") {
    return (
      <Box sx={{ display: "flex", flexDirection: "column", gap: "7px" }}>
        <Box component="span" sx={{ fontSize: 13, fontWeight: 700, color: GRAY_700 }}>{label}</Box>
        <Box sx={{ display: "flex", alignItems: "center", gap: "10px", border: "1.5px solid", borderColor: GREEN_500, background: GREEN_50, borderRadius: "12px", padding: "0 14px", minHeight: 50 }}>
          <Box data-testid={`${id}-selected`} sx={{ flex: 1, fontSize: 15, fontWeight: 700, color: GRAY_900 }}>{value.firm.name}</Box>
          <Box component="button" type="button" disabled={disabled} onClick={() => onChange(null)}
            sx={{ border: "none", background: "transparent", color: GREEN_800, fontFamily: "inherit", fontSize: 13.5, fontWeight: 700, cursor: "pointer" }}>
            Changer
          </Box>
        </Box>
      </Box>
    );
  }

  if (value?.kind === "PROPOSED") {
    return (
      <Box sx={{ display: "flex", flexDirection: "column", gap: "8px" }}>
        <Field
          id={`${id}-proposed`}
          label={`${label} — nom de la firme`}
          value={value.name}
          onChange={(name) => onChange({ kind: "PROPOSED", name })}
          disabled={disabled}
          placeholder="ex: Medacta"
          helperText="Le manager vérifiera cette firme avant de l'ajouter au catalogue."
          autoFocus
        />
        <Box component="button" type="button" disabled={disabled} onClick={() => onChange(null)}
          sx={{ alignSelf: "flex-start", border: "none", background: "transparent", color: GREEN_800, fontFamily: "inherit", fontSize: 13.5, fontWeight: 700, cursor: "pointer", p: 0 }}>
          Choisir dans la liste
        </Box>
      </Box>
    );
  }

  return (
    <Box sx={{ display: "flex", flexDirection: "column", gap: "8px" }}>
      <Field id={`${id}-search`} label={label} value={query} onChange={setQuery} disabled={disabled} placeholder="Rechercher une firme" />
      <Box role="listbox" aria-label={label} sx={{ display: "flex", flexDirection: "column", border: "1.5px solid", borderColor: GRAY_200, borderRadius: "12px", overflow: "hidden" }}>
        {matches.map((firm) => (
          <Box key={firm.id} role="option" aria-selected={false} component="button" type="button" disabled={disabled}
            onClick={() => onChange({ kind: "EXISTING", firm })}
            sx={{ textAlign: "left", border: "none", borderBottom: "1px solid", borderColor: GRAY_200, background: "#fff", padding: "11px 14px", fontFamily: "inherit", fontSize: 14.5, color: GRAY_900, cursor: "pointer", "&:hover": { background: GREEN_50 } }}>
            {firm.name}
          </Box>
        ))}
        {matches.length === 0 && (
          <Box sx={{ padding: "11px 14px", fontSize: 13.5, color: GRAY_500 }}>Aucune firme ne correspond.</Box>
        )}
        <Box role="option" aria-selected={false} component="button" type="button" disabled={disabled}
          onClick={() => onChange({ kind: "PROPOSED", name: query.trim() })}
          sx={{ textAlign: "left", border: "none", background: "#fff", padding: "11px 14px", fontFamily: "inherit", fontSize: 14.5, fontWeight: 700, color: GREEN_800, cursor: "pointer", "&:hover": { background: GREEN_50 } }}>
          Ma firme n'est pas dans la liste
        </Box>
      </Box>
    </Box>
  );
}
