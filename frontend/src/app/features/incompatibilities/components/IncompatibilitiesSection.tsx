import * as React from "react";
import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Alert, Autocomplete, Box, Button, Chip, CircularProgress, Divider, Link, Stack, TextField, Typography } from "@mui/material";
import { Link as RouterLink } from "react-router-dom";
import dayjs from "dayjs";

import {
  apiErrorMessage,
  createIncompatibility,
  fetchIncompatibilitiesOf,
  liftIncompatibility,
  ORIGIN_LABELS,
  searchIncompatibilityCandidates,
  type CandidateOption,
  type Incompatibility,
} from "../api/incompatibilities.api";
import { useToast } from "../../../ui/toast/useToast";
import { useDebouncedValue } from "../../../ui/hooks/useDebouncedValue";

type Props = {
  /** Whose file this is: a surgeon (lists his incompatible instrumentists) or an instrumentist. */
  mode: "surgeon" | "instrumentist";
  personId: number;
};

/**
 * D-152 — manager view of the incompatibilities on a surgeon's or an instrumentist's file:
 * active ones with their origin, date and author, the future missions still affected to the
 * pair (conflicts to resolve — never modified silently), the history of lifted ones, and the
 * add / lift actions. All rights and conflicts come from the backend.
 */
export default function IncompatibilitiesSection({ mode, personId }: Props) {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [other, setOther] = React.useState<CandidateOption | null>(null);
  const [search, setSearch] = React.useState("");
  const debouncedSearch = useDebouncedValue(search.trim(), 300);
  const queryKey = ["incompatibilities", mode, personId];

  const list = useQuery({ queryKey, queryFn: () => fetchIncompatibilitiesOf(mode, personId) });
  // D-152 (réserve 1) — searched and paged by the backend (active accounts of the right role,
  // never one already incompatible): next pages load as the list scrolls.
  const candidates = useInfiniteQuery({
    queryKey: ["incompatibilities", "candidates", mode, personId, debouncedSearch],
    queryFn: ({ pageParam }) => searchIncompatibilityCandidates(mode, personId, debouncedSearch, pageParam),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.hasMore ? last.page + 1 : undefined),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  });
  const options = (candidates.data?.pages ?? []).flatMap((p) => p.items);
  const total = candidates.data?.pages[0]?.total ?? 0;
  const loadMoreOnScroll = (event: React.UIEvent<HTMLElement>) => {
    const box = event.currentTarget;
    if (box.scrollTop + box.clientHeight >= box.scrollHeight - 40 && candidates.hasNextPage && !candidates.isFetchingNextPage) {
      void candidates.fetchNextPage();
    }
  };

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ["incompatibilities"] });
    queryClient.invalidateQueries({ queryKey: ["planning-v2"] });
  };

  const create = useMutation({
    mutationFn: (otherId: number) =>
      mode === "surgeon" ? createIncompatibility(personId, otherId) : createIncompatibility(otherId, personId),
    onSuccess: (created) => {
      setOther(null);
      refresh();
      const conflicts = created.conflicts?.length ?? 0;
      toast.success(
        conflicts > 0
          ? `Incompatibilité créée — ${conflicts} mission(s) future(s) à traiter.`
          : "Incompatibilité créée.",
      );
    },
    onError: (err) => toast.error(apiErrorMessage(err)),
  });

  const lift = useMutation({
    mutationFn: (id: number) => liftIncompatibility(id),
    onSuccess: () => {
      refresh();
      toast.success("Incompatibilité levée.");
    },
    onError: (err) => toast.error(apiErrorMessage(err)),
  });

  const items = list.data ?? [];
  const active = items.filter((i) => i.active);
  const history = items.filter((i) => !i.active);
  const counterpart = (i: Incompatibility) => (mode === "surgeon" ? i.instrumentist.name : `Dr ${i.surgeon.name}`);
  const blocked = new Set(active.map((i) => (mode === "surgeon" ? i.instrumentist.id : i.surgeon.id)));

  if (list.isLoading) return <CircularProgress size={20} />;

  return (
    <Stack spacing={1.5} data-testid={`incompatibilities-${mode}`}>
      <Typography variant="body2" color="text.secondary">
        {mode === "surgeon"
          ? "Les instrumentistes incompatibles ne reçoivent ni les offres ni les notifications de ses missions et ne peuvent pas y être affectés."
          : "Cet instrumentiste ne reçoit ni les offres ni les notifications des chirurgiens listés et ne peut pas être affecté à leurs missions."}
      </Typography>

      {active.length === 0 ? (
        <Alert severity="info">Aucune incompatibilité active.</Alert>
      ) : (
        active.map((i) => (
          <Box key={i.id} sx={{ border: "1px solid", borderColor: "divider", borderRadius: 1, p: 1.25 }} data-testid={`incompatibility-${i.id}`}>
            <Stack direction="row" alignItems="center" spacing={1}>
              <Box sx={{ flex: 1, minWidth: 0 }}>
                <Typography variant="body2" fontWeight={600}>
                  {counterpart(i)}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {ORIGIN_LABELS[i.origin]} · le {dayjs(i.createdAt).format("DD/MM/YYYY")}
                  {i.createdByName ? ` par ${i.createdByName}` : ""}
                </Typography>
              </Box>
              {i.canLift && (
                <Button size="small" onClick={() => lift.mutate(i.id)} disabled={lift.isPending}>
                  Lever
                </Button>
              )}
            </Stack>
            {(i.conflicts ?? []).length > 0 && (
              <Box sx={{ mt: 1 }}>
                <Chip size="small" color="warning" label={`${i.conflicts!.length} mission(s) future(s) encore affectée(s) — à traiter`} />
                <Stack spacing={0.25} sx={{ mt: 0.75 }}>
                  {i.conflicts!.map((c) => (
                    <Link key={c.alertId} component={RouterLink} to={`/app/m/missions/${c.missionId}`} variant="caption">
                      Mission #{c.missionId}
                      {c.startAt ? ` du ${dayjs(c.startAt).format("DD/MM/YYYY HH:mm")}` : ""}
                      {c.siteName ? ` — ${c.siteName}` : ""}
                    </Link>
                  ))}
                </Stack>
              </Box>
            )}
          </Box>
        ))
      )}

      <Stack direction={{ xs: "column", sm: "row" }} spacing={1} alignItems={{ sm: "center" }}>
        <Autocomplete
          sx={{ flex: 1 }}
          size="small"
          options={options.filter((o) => !blocked.has(o.id))}
          value={other}
          onChange={(_e, value) => setOther(value)}
          inputValue={search}
          onInputChange={(_e, value, reason) => {
            if (reason !== "reset") setSearch(value);
          }}
          filterOptions={(x) => x}
          getOptionLabel={(o) => o.name}
          isOptionEqualToValue={(a, b) => a.id === b.id}
          renderOption={(props, o) => (
            <li {...props} key={o.id}>
              <Box>
                <Typography variant="body2">{o.name}</Typography>
                <Typography variant="caption" color="text.secondary">{o.email}</Typography>
              </Box>
            </li>
          )}
          loading={candidates.isFetching}
          loadingText="Recherche…"
          noOptionsText={debouncedSearch ? "Aucune personne trouvée" : "Aucune personne"}
          slotProps={{ listbox: { onScroll: loadMoreOnScroll } }}
          renderInput={(params) => (
            <TextField
              {...params}
              label={mode === "surgeon" ? "Instrumentiste incompatible" : "Chirurgien incompatible"}
              placeholder="Nom, prénom ou e-mail"
              helperText={total > options.length ? `${options.length} sur ${total} — affinez la recherche ou faites défiler la liste` : undefined}
            />
          )}
        />
        <Button variant="outlined" disabled={!other || create.isPending} onClick={() => other && create.mutate(other.id)}>
          Ajouter l'incompatibilité
        </Button>
      </Stack>

      {history.length > 0 && (
        <>
          <Divider />
          <Typography variant="caption" color="text.secondary">
            Historique
          </Typography>
          {history.map((i) => (
            <Typography key={i.id} variant="caption" color="text.secondary" data-testid={`incompatibility-history-${i.id}`}>
              {counterpart(i)} — {ORIGIN_LABELS[i.origin]}, du {dayjs(i.createdAt).format("DD/MM/YYYY")}
              {i.createdByName ? ` (${i.createdByName})` : ""} au {i.liftedAt ? dayjs(i.liftedAt).format("DD/MM/YYYY") : "—"}
              {i.liftedByName ? ` (levée par ${i.liftedByName})` : ""}
            </Typography>
          ))}
        </>
      )}
      {list.isError && <Alert severity="error">{apiErrorMessage(list.error)}</Alert>}
    </Stack>
  );
}
