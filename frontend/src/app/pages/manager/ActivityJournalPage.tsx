import { useMemo, useState } from "react";
import { keepPreviousData, useInfiniteQuery } from "@tanstack/react-query";
import { Alert, Box, CircularProgress, LinearProgress, Stack, ToggleButton, ToggleButtonGroup, Typography } from "@mui/material";

import { fetchAuditJournal, nextAuditPage, type AuditJournalFilters } from "../../features/audit-journal/api/auditJournal.api";
import { AUDIT_CATEGORIES } from "../../features/audit-journal/auditEventLabels";
import AuditTimeline from "../../features/audit-journal/components/AuditTimeline";
import { SearchBox } from "../../ui/SearchBox";
import { EmptyState } from "../../ui/EmptyState";
import { useDebouncedValue } from "../../ui/hooks/useDebouncedValue";

type JournalPeriod = "today" | "7d" | "30d" | "all";

const PERIODS: { value: JournalPeriod; label: string }[] = [
  { value: "today", label: "Aujourd'hui" },
  { value: "7d", label: "7 jours" },
  { value: "30d", label: "30 jours" },
  { value: "all", label: "Tout" },
];

function periodFrom(period: JournalPeriod, reference: Date = new Date()): string | undefined {
  const days = period === "today" ? 0 : period === "7d" ? 6 : period === "30d" ? 29 : null;
  if (days === null) return undefined;
  // Minuit LOCAL, sérialisé en ISO (instant exact) — voir missionsListPeriod.ts.
  return new Date(reference.getFullYear(), reference.getMonth(), reference.getDate() - days).toISOString();
}

/**
 * D-147 — journal d'activité manager : toutes les actions tracées (AuditEvent) —
 * missions, demandes chirurgien, planning, encodage, tarifs & facturation, catalogue —
 * plus récentes d'abord, groupées par jour. Lecture seule.
 */
export default function ActivityJournalPage() {
  const [category, setCategory] = useState<string>("ALL");
  const [period, setPeriod] = useState<JournalPeriod>("7d");
  const [searchInput, setSearchInput] = useState("");
  const search = useDebouncedValue(searchInput.trim(), 300);

  const filters = useMemo<AuditJournalFilters>(() => {
    const f: AuditJournalFilters = {};
    if (category !== "ALL") f.category = category;
    const from = periodFrom(period);
    if (from) f.from = from;
    if (search) f.q = search;
    return f;
  }, [category, period, search]);

  const query = useInfiniteQuery({
    queryKey: ["audit-journal", filters],
    queryFn: ({ pageParam }) => fetchAuditJournal(filters, pageParam),
    initialPageParam: 1,
    getNextPageParam: nextAuditPage,
    placeholderData: keepPreviousData,
    refetchInterval: 60_000,
  });

  const entries = query.data?.pages.flatMap((p) => p.items) ?? [];
  const total = query.data?.pages[0]?.total ?? 0;

  return (
    <Box>
      <Stack direction="row" alignItems="baseline" spacing={1.5} mb={0.5}>
        <Typography variant="h6" fontWeight={600}>Journal d'activité</Typography>
        {query.isFetching && !query.isLoading && <CircularProgress size={14} />}
      </Stack>
      <Typography variant="body2" color="text.secondary" mb={2.5}>
        Toutes les actions effectuées dans SurgicalHub, par qui et quand.
      </Typography>

      <Stack spacing={1.5} mb={2}>
        <Box sx={{ display: "flex", gap: 1.5, flexWrap: "wrap", alignItems: "center" }}>
          <SearchBox
            value={searchInput}
            onChange={setSearchInput}
            placeholder="Auteur, chirurgien, instrumentiste, site, #mission…"
            sx={{ flex: "1 1 280px", minWidth: 220 }}
          />
          <ToggleButtonGroup
            size="small"
            exclusive
            value={period}
            onChange={(_, v: JournalPeriod | null) => { if (v) setPeriod(v); }}
            aria-label="Période"
            sx={{ "& .MuiToggleButton-root": { textTransform: "none", px: 1.5 } }}
          >
            {PERIODS.map((p) => <ToggleButton key={p.value} value={p.value}>{p.label}</ToggleButton>)}
          </ToggleButtonGroup>
        </Box>
        <ToggleButtonGroup
          size="small"
          exclusive
          value={category}
          onChange={(_, v: string | null) => { if (v) setCategory(v); }}
          aria-label="Catégorie"
          sx={{ flexWrap: "wrap", "& .MuiToggleButton-root": { textTransform: "none", px: 1.5 } }}
        >
          <ToggleButton value="ALL">Tout</ToggleButton>
          {AUDIT_CATEGORIES.map((c) => <ToggleButton key={c.value} value={c.value}>{c.label}</ToggleButton>)}
        </ToggleButtonGroup>
      </Stack>

      {query.isLoading ? (
        <LinearProgress />
      ) : query.isError ? (
        <Alert severity="error">Impossible de charger le journal.</Alert>
      ) : entries.length === 0 ? (
        <EmptyState title="Aucune action sur cette période." />
      ) : (
        <Box sx={{ opacity: query.isPlaceholderData ? 0.6 : 1, transition: "opacity 150ms" }}>
          <AuditTimeline
            entries={entries}
            total={total}
            hasMore={query.hasNextPage}
            loadingMore={query.isFetchingNextPage}
            onLoadMore={() => query.fetchNextPage()}
          />
        </Box>
      )}
    </Box>
  );
}
