import * as React from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Alert, Autocomplete, Box, Button, CircularProgress, Paper, Stack, TextField, Typography } from "@mui/material";
import dayjs from "dayjs";

import {
  apiErrorMessage,
  blockInstrumentist,
  fetchBlockableInstrumentists,
  fetchMyBlockedInstrumentists,
  liftIncompatibility,
  ORIGIN_LABELS,
  type PersonOption,
} from "../api/incompatibilities.api";
import { useToast } from "../../../ui/toast/useToast";

const MY_BLOCKS_KEY = ["incompatibilities", "me"];

/**
 * D-152 — « Instrumentistes bloqués » on the surgeon's profile: who, since when, why the block
 * matters, add one (active instrumentists of his sites + freelancers, filtered by the backend)
 * and lift the ones he created. Everything decided server-side (canLift, blockable list).
 */
export default function BlockedInstrumentistsCard() {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [search, setSearch] = React.useState("");
  const [selected, setSelected] = React.useState<PersonOption | null>(null);

  const blocks = useQuery({ queryKey: MY_BLOCKS_KEY, queryFn: fetchMyBlockedInstrumentists });
  const blockable = useQuery({
    queryKey: ["incompatibilities", "blockable", search],
    queryFn: () => fetchBlockableInstrumentists(search),
    staleTime: 10_000,
  });

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ["incompatibilities"] });
  };

  const block = useMutation({
    mutationFn: (id: number) => blockInstrumentist(id),
    onSuccess: (created) => {
      setSelected(null);
      refresh();
      toast.success(`${created.instrumentist.name} est bloqué·e pour vos futures missions.`);
    },
    onError: (err) => toast.error(apiErrorMessage(err)),
  });

  const lift = useMutation({
    mutationFn: (id: number) => liftIncompatibility(id),
    onSuccess: (lifted) => {
      refresh();
      toast.success(`${lifted.instrumentist.name} peut de nouveau être affecté·e à vos missions.`);
    },
    onError: (err) => toast.error(apiErrorMessage(err)),
  });

  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 3 }} data-testid="blocked-instrumentists-card">
      <Typography variant="subtitle2" fontWeight={700}>
        Instrumentistes bloqués
      </Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
        Un instrumentiste bloqué ne voit plus vos nouvelles missions, n'en reçoit ni les offres ni les notifications et ne
        peut pas les accepter. Il n'est jamais informé de ce blocage. Les missions auxquelles il est déjà affecté ne sont
        pas modifiées : les managers sont alertés et les traitent.
      </Typography>

      <Box sx={{ mt: 1.5 }}>
        {blocks.isLoading ? (
          <CircularProgress size={20} />
        ) : (blocks.data ?? []).length === 0 ? (
          <Typography variant="body2" color="text.secondary">
            Aucun instrumentiste bloqué.
          </Typography>
        ) : (
          <Stack spacing={1}>
            {(blocks.data ?? []).map((b) => (
              <Stack key={b.id} direction="row" alignItems="center" spacing={1} data-testid={`block-${b.id}`}>
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Typography variant="body2" fontWeight={600}>
                    {b.instrumentist.name}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {ORIGIN_LABELS[b.origin]} · depuis le {dayjs(b.createdAt).format("DD/MM/YYYY")}
                  </Typography>
                </Box>
                {b.canLift ? (
                  <Button size="small" onClick={() => lift.mutate(b.id)} disabled={lift.isPending}>
                    Débloquer
                  </Button>
                ) : (
                  <Typography variant="caption" color="text.secondary">
                    Géré par la coordination
                  </Typography>
                )}
              </Stack>
            ))}
          </Stack>
        )}
      </Box>

      <Stack direction={{ xs: "column", sm: "row" }} spacing={1} sx={{ mt: 2 }} alignItems={{ sm: "center" }}>
        <Autocomplete
          sx={{ flex: 1 }}
          size="small"
          options={blockable.data ?? []}
          value={selected}
          onChange={(_e, value) => setSelected(value)}
          onInputChange={(_e, value) => setSearch(value)}
          getOptionLabel={(o) => o.name}
          isOptionEqualToValue={(a, b) => a.id === b.id}
          filterOptions={(x) => x}
          noOptionsText="Aucun instrumentiste"
          renderInput={(params) => <TextField {...params} label="Bloquer un instrumentiste" />}
        />
        <Button variant="outlined" color="error" disabled={!selected || block.isPending} onClick={() => selected && block.mutate(selected.id)}>
          Bloquer
        </Button>
      </Stack>
      {blocks.isError && (
        <Alert severity="error" sx={{ mt: 1 }}>
          {apiErrorMessage(blocks.error)}
        </Alert>
      )}
    </Paper>
  );
}
