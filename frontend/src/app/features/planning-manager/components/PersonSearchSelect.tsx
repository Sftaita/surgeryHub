import * as React from "react";
import { Autocomplete, Box, CircularProgress, TextField, Typography } from "@mui/material";
import SearchOutlinedIcon from "@mui/icons-material/SearchOutlined";

import { getInstrumentists } from "../../manager-instrumentists/api/instrumentists.api";
import { getSurgeons } from "../../manager-surgeons/api/surgeons.api";
import { Avatar } from "../../../ui/avatar/Avatar";
import type { PersonRole } from "../api/planning.api";

export interface PersonOption {
  id: number;
  name: string;
  firstname: string | null;
  lastname: string | null;
  email: string;
  role: PersonRole;
}

interface Props {
  label: string;
  value: PersonOption | null;
  onChange: (person: PersonOption | null) => void;
  disabled?: boolean;
}

const ROLE_LABELS: Record<PersonRole, string> = { INSTRUMENTIST: "Instrumentiste", SURGEON: "Chirurgien" };

function displayName(p: { firstname: string | null; lastname: string | null; email: string }): string {
  return `${p.firstname ?? ""} ${p.lastname ?? ""}`.trim() || p.email;
}

/** Instrumentistes → Chirurgiens → nom de famille → prénom → email en dernier repli. */
function sortKey(p: PersonOption): [number, string, string, string] {
  return [
    p.role === "INSTRUMENTIST" ? 0 : 1,
    (p.lastname ?? "").toLowerCase(),
    (p.firstname ?? "").toLowerCase(),
    p.email.toLowerCase(),
  ];
}

function comparePersonOptions(a: PersonOption, b: PersonOption): number {
  const [ra, lastA, firstA, emailA] = sortKey(a);
  const [rb, lastB, firstB, emailB] = sortKey(b);
  return (ra - rb)
    || lastA.localeCompare(lastB)
    || firstA.localeCompare(firstB)
    || emailA.localeCompare(emailB);
}

/**
 * Server-side debounced person search — never lists everyone upfront. Below ~100+ users,
 * an eager full-list autocomplete becomes both slow to load and unpleasant to scroll; this
 * fetches only matches for whatever was typed, from /api/instrumentists + /api/surgeons
 * (both already support search server-side — search=, q= respectively).
 */
export function PersonSearchSelect({ label, value, onChange, disabled }: Props) {
  const [inputValue, setInputValue] = React.useState("");
  const [options, setOptions] = React.useState<PersonOption[]>([]);
  const [loading, setLoading] = React.useState(false);

  React.useEffect(() => {
    const query = inputValue.trim();
    if (query.length === 0) {
      setOptions([]);
      return;
    }
    let cancelled = false;
    setLoading(true);
    const timer = setTimeout(async () => {
      try {
        const [instRes, surgRes] = await Promise.all([
          getInstrumentists({ search: query }),
          getSurgeons({ q: query }),
        ]);
        if (cancelled) return;
        const insts: PersonOption[] = instRes.items.map((u) => ({ id: u.id, name: displayName(u), firstname: u.firstname, lastname: u.lastname, email: u.email, role: "INSTRUMENTIST" }));
        const surgs: PersonOption[] = surgRes.items.map((u) => ({ id: u.id, name: displayName(u), firstname: u.firstname, lastname: u.lastname, email: u.email, role: "SURGEON" }));
        setOptions([...insts, ...surgs].sort(comparePersonOptions));
      } finally {
        if (!cancelled) setLoading(false);
      }
    }, 300);
    return () => { cancelled = true; clearTimeout(timer); };
  }, [inputValue]);

  return (
    <Box>
      <Typography component="label" sx={{ display: "block", fontSize: 12, fontWeight: 700, color: "text.secondary", mb: 1 }}>
        {label}
      </Typography>
      <Autocomplete
        disabled={disabled}
        options={options}
        value={value}
        inputValue={inputValue}
        onInputChange={(_, v) => setInputValue(v)}
        onChange={(_, v) => onChange(v)}
        getOptionLabel={(o) => o.name}
        isOptionEqualToValue={(o, v) => o.id === v.id && o.role === v.role}
        filterOptions={(opts) => opts}
        loading={loading}
        noOptionsText={inputValue.trim() ? "Aucun résultat" : "Tapez pour rechercher…"}
        renderOption={(props, option) => (
          <Box component="li" {...props} key={`${option.role}-${option.id}`} sx={{ display: "flex", alignItems: "center !important", gap: 1 }}>
            <Avatar name={option.name} size={26} />
            <Box sx={{ minWidth: 0 }}>
              <Typography sx={{ fontSize: 13.5 }} noWrap>{option.name}</Typography>
              <Typography sx={{ fontSize: 11, color: "text.secondary" }} noWrap>{ROLE_LABELS[option.role]} · {option.email}</Typography>
            </Box>
          </Box>
        )}
        renderInput={(params) => (
          <TextField
            {...params}
            placeholder="Rechercher une personne…"
            slotProps={{
              input: {
                ...params.InputProps,
                startAdornment: <SearchOutlinedIcon sx={{ fontSize: 16, color: "text.secondary", mr: 0.5 }} />,
                endAdornment: <>{loading ? <CircularProgress size={16} /> : null}{params.InputProps.endAdornment}</>,
              },
            }}
          />
        )}
      />
    </Box>
  );
}
