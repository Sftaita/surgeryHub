import { Autocomplete, Box, CircularProgress, TextField, Typography } from "@mui/material";
import SearchOutlinedIcon from "@mui/icons-material/SearchOutlined";
import { useQuery } from "@tanstack/react-query";

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

/**
 * Which population this instance searches over — generic, not absences-specific. `"all"`
 * (the default) covers every consumer that needs to pick any active person; `"instrumentists"`
 * / `"surgeons"` restrict both the displayed options AND the underlying API calls (the other
 * endpoint is never hit).
 */
export type PersonSearchScope = "all" | "instrumentists" | "surgeons";

interface Props {
  label: string;
  value: PersonOption | null;
  onChange: (person: PersonOption | null) => void;
  scope?: PersonSearchScope;
  disabled?: boolean;
}

const ROLE_LABELS: Record<PersonRole, string> = { INSTRUMENTIST: "Instrumentiste", SURGEON: "Chirurgien" };

/** Collapses any stray whitespace (e.g. a trailing space stored on `firstname` in the DB)
 *  so neither the display nor the search ever shows/compares a doubled space. */
function collapseSpaces(s: string): string {
  return s.trim().replace(/\s+/g, " ");
}

function displayName(p: { firstname: string | null; lastname: string | null; email: string }): string {
  return collapseSpaces(`${p.firstname ?? ""} ${p.lastname ?? ""}`) || p.email;
}

/** Strips accents so "deltour"/"Deltour"/"Démètre" etc. all compare equal regardless of diacritics. */
function foldAccents(s: string): string {
  return s.normalize("NFD").replace(/\p{Diacritic}/gu, "");
}

function normalizeForSearch(s: string): string {
  return foldAccents(collapseSpaces(s)).toLowerCase();
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
 * Substring match on prénom, nom, email, rôle, OR the full name in either word order
 * ("Arnaud Deltour" as well as "Deltour Arnaud") — all client-side, no debounce. Accent- and
 * whitespace-insensitive so a trailing space stuck on `firstname` in the DB (real prod data:
 * "Arnaud " for surgeon #8) never breaks a match.
 */
function matchesQuery(option: PersonOption, query: string): boolean {
  const q = normalizeForSearch(query);
  if (!q) return true;
  const firstname = normalizeForSearch(option.firstname ?? "");
  const lastname = normalizeForSearch(option.lastname ?? "");
  const roleLabel = normalizeForSearch(ROLE_LABELS[option.role]);
  const candidates = [
    firstname,
    lastname,
    normalizeForSearch(option.email),
    roleLabel,
    collapseSpaces(`${firstname} ${lastname}`),
    collapseSpaces(`${lastname} ${firstname}`),
  ];
  return candidates.some((field) => field.includes(q));
}

export function personOptionsQueryKey(scope: PersonSearchScope) {
  return ["personOptions", "active", scope] as const;
}

export async function fetchActivePersonOptions(scope: PersonSearchScope = "all"): Promise<PersonOption[]> {
  const [instRes, surgRes] = await Promise.all([
    scope === "all" || scope === "instrumentists" ? getInstrumentists({ active: true }) : null,
    scope === "all" || scope === "surgeons" ? getSurgeons({ active: true }) : null,
  ]);
  const insts: PersonOption[] = (instRes?.items ?? []).map((u) => ({ id: u.id, name: displayName(u), firstname: u.firstname?.trim() || null, lastname: u.lastname?.trim() || null, email: u.email, role: "INSTRUMENTIST" }));
  const surgs: PersonOption[] = (surgRes?.items ?? []).map((u) => ({ id: u.id, name: displayName(u), firstname: u.firstname?.trim() || null, lastname: u.lastname?.trim() || null, email: u.email, role: "SURGEON" }));
  return [...insts, ...surgs].sort(comparePersonOptions);
}

export function usePersonOptions(scope: PersonSearchScope = "all") {
  return useQuery({
    queryKey: personOptionsQueryKey(scope),
    queryFn: () => fetchActivePersonOptions(scope),
    staleTime: 5 * 60 * 1000,
  });
}

/**
 * Generic person picker — NOT specific to absences. Loads the active population for the given
 * `scope` ONCE (cached by React Query, keyed per scope — shared across every dialog open and
 * every other consumer using the same scope) and filters entirely client-side from then on. No
 * request is fired while typing — this is a deliberate UX choice over a server-side debounced
 * search, which felt sluggish in real manager usage.
 */
export function PersonSearchSelect({ label, value, onChange, scope = "all", disabled }: Props) {
  const { data, isLoading } = usePersonOptions(scope);
  const options = data ?? [];

  return (
    <Box>
      <Typography component="label" sx={{ display: "block", fontSize: 12, fontWeight: 700, color: "text.secondary", mb: 1 }}>
        {label}
      </Typography>
      <Autocomplete
        disabled={disabled}
        options={options}
        value={value}
        openOnFocus
        onChange={(_, v) => onChange(v)}
        getOptionLabel={(o) => o.name}
        isOptionEqualToValue={(o, v) => o.id === v.id && o.role === v.role}
        filterOptions={(opts, state) => opts.filter((o) => matchesQuery(o, state.inputValue))}
        loading={isLoading}
        loadingText="Chargement…"
        noOptionsText="Aucun résultat"
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
                endAdornment: <>{isLoading ? <CircularProgress size={16} /> : null}{params.InputProps.endAdornment}</>,
              },
            }}
          />
        )}
      />
    </Box>
  );
}
