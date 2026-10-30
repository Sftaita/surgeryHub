import * as React from "react";
import { Box } from "@mui/material";
import { matchesSearch } from "../../planning-v2/components/SearchableSelect";

const GRAY_50 = "#F7F9FA";
const GRAY_100 = "#EFF2F5";
const GRAY_200 = "#DDE2E8";
const GRAY_400 = "#98A2AE";
const GRAY_500 = "#727E8C";
const GRAY_700 = "#3A4754";
const GRAY_800 = "#243240";
const GRAY_900 = "#16202B";
const GREEN_50 = "#EFFAF5";
const GREEN_500 = "#42A882";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const FOCUS_RING = "0 0 0 3px rgba(66,168,130,.32)";

export type PickerItem = { id: number; primary: string; secondary?: string };

type Props = {
  /** Préfixe des ids DOM (listbox, options) — unique dans la fenêtre. */
  id: string;
  /** Libellé du champ (au-dessus de la sélection ou de la recherche). Omis = pas d'en-tête. */
  label?: string;
  searchLabel: string;
  searchPlaceholder: string;
  /** Liste complète autorisée, dans l'ordre d'affichage. */
  items: PickerItem[];
  /** Raccourcis (au plus deux) — seuls ceux présents dans `items` sont affichés. */
  recent: PickerItem[];
  recentTitle: string;
  recentLoading?: boolean;
  allTitle: string;
  emptyText: string;
  /** Sélection existante : affichée telle quelle, jamais remplacée sans geste explicite. */
  value?: PickerItem | null;
  onSelect: (id: number) => void;
  /** Actions hors liste (ex. « Ma firme n'est pas dans la liste »). */
  footer?: React.ReactNode;
  /** Texte saisi, pour préremplir une action hors liste. */
  onQueryChange?: (query: string) => void;
  disabled?: boolean;
  loading?: boolean;
  loadingText?: string;
};

function SearchIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke={GRAY_400} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
    </svg>
  );
}

function ClockIcon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke={GREEN_700} strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
    </svg>
  );
}

/** Clavier physique (desktop) : focus direct sur la recherche ; sur mobile, pas de clavier virtuel imposé. */
function prefersAutoFocus(): boolean {
  return typeof window !== "undefined" && window.matchMedia?.("(pointer: fine)").matches === true;
}

/**
 * D-178 — sélection d'une firme ou d'un type d'intervention : raccourcis « récemment utilisés »
 * puis liste complète, recherche instantanée insensible à la casse et aux accents, navigation
 * clavier (↑ ↓ Entrée). Un raccourci n'est qu'une autre façon de désigner une entrée de `items` :
 * `onSelect` reçoit le même identifiant, l'appelant exécute le même flux.
 *
 * Liste dans le flux de la fenêtre (pas de zone défilante imbriquée).
 */
export default function SearchablePicker({
  id, label, searchLabel, searchPlaceholder, items, recent, recentTitle, recentLoading = false, allTitle,
  emptyText, value = null, onSelect, footer, onQueryChange, disabled = false, loading = false, loadingText = "Chargement…",
}: Props) {
  const [changing, setChanging] = React.useState(false);
  const [query, setQuery] = React.useState("");
  const [activeIndex, setActiveIndex] = React.useState(0);
  const optionRefs = React.useRef<Record<string, HTMLElement | null>>({});
  const autoFocus = React.useMemo(prefersAutoFocus, []);

  const showList = value == null || changing;

  const byId = React.useMemo(() => new Map(items.map((i) => [i.id, i])), [items]);
  // Un raccourci n'apparaît que s'il est sélectionnable dans la liste complète.
  const recentShown = recent.flatMap((r) => (byId.has(r.id) ? [byId.get(r.id)!] : []));
  const recentIds = new Set(recentShown.map((r) => r.id));
  const q = query.trim();
  const sections: Array<{ key: string; title: string; options: PickerItem[] }> = q === ""
    ? [
      ...(recentShown.length > 0 ? [{ key: "recent", title: recentTitle, options: recentShown }] : []),
      { key: "all", title: allTitle, options: items.filter((i) => !recentIds.has(i.id)) },
    ]
    : [{ key: "results", title: "Résultats", options: items.filter((i) => matchesSearch({ label: i.primary, sub: i.secondary }, q)) }];
  // Liste complète entièrement couverte par les raccourcis : pas d'en-tête vide.
  if (sections.length > 1 && sections[sections.length - 1].options.length === 0) sections.pop();
  const flat = sections.flatMap((s) => s.options.map((o) => ({ section: s.key, option: o })));
  const optionDomId = (section: string, optionId: number) => `${id}-opt-${section}-${optionId}`;

  React.useEffect(() => { setActiveIndex(0); }, [q]);
  React.useEffect(() => {
    if (!showList) { setQuery(""); }
  }, [showList]);

  const active = flat[Math.min(activeIndex, flat.length - 1)];
  React.useEffect(() => {
    if (!active) return;
    optionRefs.current[optionDomId(active.section, active.option.id)]?.scrollIntoView?.({ block: "nearest" });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active?.section, active?.option.id]);

  function select(optionId: number) {
    setChanging(false);
    onSelect(optionId);
  }

  function onKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (flat.length === 0) return;
    if (e.key === "ArrowDown") { e.preventDefault(); setActiveIndex((i) => Math.min(i + 1, flat.length - 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setActiveIndex((i) => Math.max(i - 1, 0)); }
    else if (e.key === "Enter" && active) { e.preventDefault(); select(active.option.id); }
  }

  if (!showList && value) {
    return (
      <Box sx={{ display: "flex", flexDirection: "column", gap: "7px" }}>
        {label && <Box component="span" sx={{ fontSize: 13, fontWeight: 700, color: GRAY_700 }}>{label}</Box>}
        <Box sx={{ display: "flex", alignItems: "center", gap: "10px", border: "1.5px solid", borderColor: GREEN_500, background: GREEN_50, borderRadius: "12px", padding: "8px 14px", minHeight: 50 }}>
          <Box data-testid={`${id}-selected`} sx={{ flex: 1, minWidth: 0, fontSize: 15, fontWeight: 700, color: GRAY_900 }}>
            {value.primary}
            {value.secondary && <Box component="span" sx={{ display: "block", fontSize: 12.5, fontWeight: 500, color: GRAY_500 }}>{value.secondary}</Box>}
          </Box>
          <Box component="button" type="button" disabled={disabled} onClick={() => setChanging(true)} aria-label={label ? `Changer — ${label.replace(/\s*\*$/, "")}` : undefined}
            sx={{ border: "none", background: "transparent", color: GREEN_800, fontFamily: "inherit", fontSize: 13.5, fontWeight: 700, cursor: "pointer", minHeight: 40, "&:disabled": { opacity: 0.6, cursor: "default" } }}>
            Changer
          </Box>
        </Box>
      </Box>
    );
  }

  const listboxId = `${id}-listbox`;
  return (
    <Box sx={{ display: "flex", flexDirection: "column", gap: "10px" }}>
      {label && <Box component="span" sx={{ fontSize: 13, fontWeight: 700, color: GRAY_700 }}>{label}</Box>}
      <Box sx={{ position: "relative" }}>
        <Box sx={{ position: "absolute", left: "14px", top: "14px", pointerEvents: "none", display: "flex" }}>
          <SearchIcon />
        </Box>
        <Box
          component="input"
          type="search"
          role="combobox"
          aria-label={searchLabel}
          aria-expanded
          aria-controls={listboxId}
          aria-autocomplete="list"
          aria-activedescendant={active ? optionDomId(active.section, active.option.id) : undefined}
          value={query}
          onChange={(e: React.ChangeEvent<HTMLInputElement>) => { setQuery(e.target.value); onQueryChange?.(e.target.value); }}
          onKeyDown={onKeyDown}
          placeholder={searchPlaceholder}
          disabled={disabled}
          autoFocus={autoFocus}
          autoComplete="off"
          sx={{
            height: 46, border: "1.5px solid", borderColor: GRAY_200, borderRadius: "12px",
            padding: "0 14px 0 42px", fontSize: 15, color: GRAY_900, background: "#fff", outline: "none", width: "100%",
            fontFamily: "inherit", "&:focus": { borderColor: GREEN_500, boxShadow: FOCUS_RING },
          }}
        />
      </Box>

      {loading ? (
        <Box role="status" sx={{ py: "14px", px: "4px", fontSize: 13.5, color: GRAY_500 }}>{loadingText}</Box>
      ) : (
        <Box id={listboxId} role="listbox" aria-label={allTitle}>
          {q === "" && recentLoading && recentShown.length === 0 && (
            <Box role="status" sx={{ py: "6px", px: "4px", fontSize: 12.5, color: GRAY_400 }}>Recherche des {recentTitle.toLowerCase()}…</Box>
          )}
          {sections.map((section) => (
            <Box key={section.key} role="group" aria-label={section.title} sx={{ mb: "8px" }}>
              {(sections.length > 1 || section.key === "recent") && (
                <Box sx={{ display: "flex", alignItems: "center", gap: "6px", px: "4px", py: "6px", fontSize: 12.5, fontWeight: 700, color: section.key === "recent" ? GREEN_700 : GRAY_500 }}>
                  {section.key === "recent" && <ClockIcon />}
                  {section.title}
                </Box>
              )}
              {section.options.map((option) => {
                const domId = optionDomId(section.key, option.id);
                const isActive = active?.section === section.key && active.option.id === option.id;
                const isRecent = section.key === "recent";
                return (
                  <Box
                    key={domId}
                    id={domId}
                    ref={(el: HTMLElement | null) => { optionRefs.current[domId] = el; }}
                    role="option"
                    aria-selected={isActive}
                    tabIndex={-1}
                    onClick={() => !disabled && select(option.id)}
                    onMouseDown={(e: React.MouseEvent) => e.preventDefault()}
                    sx={{
                      display: "flex", alignItems: "center", gap: "10px", minHeight: 50, px: "10px", py: "6px",
                      borderRadius: isRecent ? "10px" : 0, cursor: disabled ? "default" : "pointer",
                      border: isRecent ? "1.5px solid" : "none", borderColor: GREEN_500,
                      borderBottom: isRecent ? undefined : `1px solid ${GRAY_100}`,
                      mb: isRecent ? "6px" : 0,
                      background: isActive ? GREEN_50 : isRecent ? "#fff" : "transparent",
                      boxShadow: isActive ? FOCUS_RING : "none",
                      "&:hover": { background: isActive ? GREEN_50 : GRAY_50 },
                    }}
                  >
                    <Box sx={{ flex: 1, minWidth: 0 }}>
                      <Box sx={{ fontSize: 14.5, fontWeight: 600, color: GRAY_800 }}>{option.primary}</Box>
                      {option.secondary && <Box sx={{ fontSize: 12.5, color: GRAY_500 }}>{option.secondary}</Box>}
                    </Box>
                  </Box>
                );
              })}
            </Box>
          ))}
          {flat.length === 0 && (
            <Box sx={{ py: "14px", px: "4px", fontSize: 13.5, color: GRAY_400 }}>{emptyText}</Box>
          )}
        </Box>
      )}

      {footer}

      {value && changing && (
        <Box component="button" type="button" disabled={disabled} onClick={() => setChanging(false)}
          sx={{ alignSelf: "flex-start", border: "none", background: "transparent", color: GREEN_800, fontFamily: "inherit", fontSize: 13.5, fontWeight: 700, cursor: "pointer", p: 0, minHeight: 36 }}>
          Conserver « {value.primary} »
        </Box>
      )}
    </Box>
  );
}
