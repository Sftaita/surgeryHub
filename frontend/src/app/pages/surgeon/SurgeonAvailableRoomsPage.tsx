import * as React from "react";
import { useQuery } from "@tanstack/react-query";
import { useNavigate, useSearchParams } from "react-router-dom";
import {
  Alert,
  Autocomplete,
  Box,
  Chip,
  CircularProgress,
  IconButton,
  MenuItem,
  Select,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import ArrowBackIcon from "@mui/icons-material/ArrowBack";

import { getMyAvailableRooms } from "../../features/planning-v2/api/planningV2.api";
import type { ShiftPeriod } from "../../features/planning-v2/api/planningV2.types";
import {
  AvailableRoomRow,
  AVAILABLE_ROOM_PERIOD_LABELS,
  EmptyStateRow,
  formatDateToYmd,
  startOfWeek,
  addDays,
} from "../../features/mobile-planning/planningPrimitives";

type QuickRange = "today" | "week" | "30d" | "upcoming";

const QUICK_RANGES: Array<{ key: QuickRange; label: string }> = [
  { key: "today", label: "Aujourd'hui" },
  { key: "week", label: "Cette semaine" },
  { key: "30d", label: "30 prochains jours" },
  { key: "upcoming", label: "À venir" },
];

function isQuickRange(value: string | null): value is QuickRange {
  return value === "today" || value === "week" || value === "30d" || value === "upcoming";
}

function isPeriod(value: string | null): value is ShiftPeriod {
  return value === "MATIN" || value === "APRES_MIDI" || value === "JOURNEE";
}

/** Bornes dateFrom/dateTo dérivées du chip actif — "upcoming" (défaut) ne pose aucune borne
 *  haute, le backend applique déjà son propre plancher "jamais le passé" côté chirurgien. */
function rangeToDates(range: QuickRange, todayYmd: string): { dateFrom: string; dateTo?: string } {
  const today = new Date(`${todayYmd}T00:00:00`);
  if (range === "today") return { dateFrom: todayYmd, dateTo: todayYmd };
  if (range === "week") {
    const end = addDays(startOfWeek(today), 6);
    return { dateFrom: todayYmd, dateTo: formatDateToYmd(end) };
  }
  if (range === "30d") return { dateFrom: todayYmd, dateTo: formatDateToYmd(addDays(today, 30)) };
  return { dateFrom: todayYmd };
}

/**
 * « Salles libérées » (Lot D, post D-114) — vue chirurgien, scopée serveur à ses propres
 * affiliations (`GET /api/me/available-rooms`, jamais un `siteId` client de confiance au-delà
 * de cette intersection — le scoping backend reste seul décisif, §8 de la demande
 * d'intégration agenda). Indépendante des emails Room Release et du toggle
 * `notifyColleaguesEnabled` : un créneau BLOCK réellement libéré est visible ici même si les
 * emails collègues sont désactivés pour le site concerné.
 *
 * Filtres (revue 2026-09-07, §5/§6) : chips de plage rapide (Aujourd'hui/Cette semaine/30
 * prochains jours/À venir — défaut), établissement (Autocomplete, sert aussi de recherche —
 * §6 : "pas besoin d'un moteur complexe"), période. Synchronisés dans l'URL pour un deep-link
 * filtrable. Toujours aucune date passée (§9) : jamais exposé côté chirurgien, ni en filtre
 * ni en défaut.
 */
export default function SurgeonAvailableRoomsPage() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();

  const todayYmd = React.useMemo(() => formatDateToYmd(new Date()), []);
  const rangeParam = searchParams.get("range");
  const range: QuickRange = isQuickRange(rangeParam) ? rangeParam : "upcoming";
  const siteIdParam = searchParams.get("siteId");
  const siteId = siteIdParam ? Number(siteIdParam) : undefined;
  const periodParam = searchParams.get("period");
  const period = isPeriod(periodParam) ? periodParam : undefined;

  const updateParams = React.useCallback(
    (patch: Partial<{ range: QuickRange; siteId: number | undefined; period: ShiftPeriod | undefined }>) => {
      const next = new URLSearchParams(searchParams);
      const nextRange = "range" in patch ? patch.range : range;
      const nextSiteId = "siteId" in patch ? patch.siteId : siteId;
      const nextPeriod = "period" in patch ? patch.period : period;
      if (nextRange && nextRange !== "upcoming") next.set("range", nextRange); else next.delete("range");
      if (nextSiteId) next.set("siteId", String(nextSiteId)); else next.delete("siteId");
      if (nextPeriod) next.set("period", nextPeriod); else next.delete("period");
      setSearchParams(next, { replace: true });
    },
    [searchParams, range, siteId, period, setSearchParams],
  );

  const { dateFrom, dateTo } = React.useMemo(() => rangeToDates(range, todayYmd), [range, todayYmd]);

  const roomsQuery = useQuery({
    queryKey: ["available-rooms", "mine", "list", { dateFrom, dateTo, siteId, period }],
    queryFn: () => getMyAvailableRooms({ dateFrom, dateTo, siteId, period, limit: 100 }),
  });
  const items = roomsQuery.data?.items ?? [];

  // Options du filtre Établissement — dérivées d'une requête large indépendante des filtres
  // actifs (jamais bornée par la plage/période sélectionnée), pour que la liste ne se
  // rétrécisse jamais elle-même au fil des choix de l'utilisateur (§5/§6).
  const siteOptionsQuery = useQuery({
    queryKey: ["available-rooms", "mine", "site-options"],
    queryFn: () => getMyAvailableRooms({ limit: 200 }),
  });
  const siteOptions = React.useMemo(() => {
    const bySiteId = new Map<number, { id: number; name: string }>();
    for (const slot of siteOptionsQuery.data?.items ?? []) {
      if (slot.site) bySiteId.set(slot.site.id, slot.site);
    }
    return Array.from(bySiteId.values()).sort((a, b) => a.name.localeCompare(b.name));
  }, [siteOptionsQuery.data]);

  return (
    <Box sx={{ display: "flex", flexDirection: "column", gap: "20px" }}>
      <Stack direction="row" alignItems="center" spacing={1}>
        <IconButton size="small" onClick={() => navigate("/app/s/planning")} aria-label="Retour au planning">
          <ArrowBackIcon fontSize="small" />
        </IconButton>
        <Typography variant="h6" fontWeight={700}>Salles disponibles</Typography>
      </Stack>

      <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
        {QUICK_RANGES.map((r) => (
          <Chip
            key={r.key}
            label={r.label}
            clickable
            color={range === r.key ? "primary" : "default"}
            variant={range === r.key ? "filled" : "outlined"}
            onClick={() => updateParams({ range: r.key })}
          />
        ))}
      </Stack>

      <Stack direction="row" spacing={1.5} flexWrap="wrap" useFlexGap>
        {siteOptions.length > 1 && (
          <Autocomplete
            size="small"
            options={siteOptions}
            getOptionLabel={(o) => o.name}
            value={siteOptions.find((o) => o.id === siteId) ?? null}
            onChange={(_, value) => updateParams({ siteId: value?.id })}
            isOptionEqualToValue={(o, v) => o.id === v.id}
            sx={{ width: 240 }}
            renderInput={(params) => <TextField {...params} label="Établissement" placeholder="Rechercher…" />}
          />
        )}
        <Select
          size="small"
          displayEmpty
          value={period ?? ""}
          onChange={(e) => updateParams({ period: (e.target.value || undefined) as ShiftPeriod | undefined })}
          sx={{ minWidth: 160 }}
        >
          <MenuItem value="">Toutes les périodes</MenuItem>
          {Object.entries(AVAILABLE_ROOM_PERIOD_LABELS).map(([value, label]) => (
            <MenuItem key={value} value={value}>{label}</MenuItem>
          ))}
        </Select>
      </Stack>

      {roomsQuery.isError && <Alert severity="error">Impossible de charger les salles disponibles.</Alert>}

      {roomsQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 6 }}>
          <CircularProgress size={28} />
        </Box>
      ) : items.length === 0 ? (
        <EmptyStateRow text="Aucune salle disponible pour cette sélection." />
      ) : (
        <Stack spacing={1.375}>
          {items.map((slot) => (
            <AvailableRoomRow key={slot.id} slot={slot} />
          ))}
        </Stack>
      )}
    </Box>
  );
}
