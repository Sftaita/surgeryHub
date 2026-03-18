import * as React from "react";
import {
  Box, Button, Checkbox, Chip, CircularProgress, FormControlLabel,
  Paper, Stack, Typography,
} from "@mui/material";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { SPECIALTIES, updateUserSpecialties } from "../../../features/planning-manager/api/planning.api";
import { useToast } from "../../../ui/toast/useToast";
import { apiClient } from "../../../api/apiClient";

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

function userName(u: any) {
  return `${u.firstname ?? ""} ${u.lastname ?? ""}`.trim() || u.email;
}

interface UserWithSpecialties {
  id: number; firstname: string; lastname: string; email: string;
  specialties: string[];
}

function SpecialtiesCard({ user, role }: { user: UserWithSpecialties; role: string }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [editing, setEditing] = React.useState(false);
  const [selected, setSelected] = React.useState<string[]>(user.specialties ?? []);

  React.useEffect(() => { setSelected(user.specialties ?? []); }, [user.specialties]);

  const saveMutation = useMutation({
    mutationFn: () => updateUserSpecialties(user.id, selected),
    onSuccess: () => {
      toast.success("Compétences mises à jour");
      qc.invalidateQueries({ queryKey: ["users-specialties"] });
      setEditing(false);
    },
    onError: (err) => toast.error(extractError(err)),
  });

  function toggle(v: string) {
    setSelected((prev) => prev.includes(v) ? prev.filter((x) => x !== v) : [...prev, v]);
  }

  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
      <Stack spacing={1.5}>
        <Stack direction="row" justifyContent="space-between" alignItems="flex-start">
          <Box>
            <Typography variant="subtitle2" fontWeight={700}>{userName(user)}</Typography>
            <Typography variant="caption" color="text.secondary">{role} · {user.email}</Typography>
          </Box>
          {!editing ? (
            <Button size="small" variant="outlined" onClick={() => setEditing(true)}>Modifier</Button>
          ) : (
            <Stack direction="row" spacing={1}>
              <Button size="small" color="inherit" onClick={() => { setEditing(false); setSelected(user.specialties ?? []); }}>Annuler</Button>
              <Button size="small" variant="contained" disableElevation onClick={() => saveMutation.mutate()} disabled={saveMutation.isPending}>
                {saveMutation.isPending ? <CircularProgress size={14} /> : "Sauvegarder"}
              </Button>
            </Stack>
          )}
        </Stack>

        {!editing ? (
          <Stack direction="row" spacing={0.75} flexWrap="wrap">
            {(user.specialties ?? []).length === 0 ? (
              <Typography variant="body2" color="text.secondary" fontStyle="italic">Aucune compétence définie</Typography>
            ) : (
              (user.specialties ?? []).map((s) => (
                <Chip key={s} label={SPECIALTIES.find((x) => x.value === s)?.label ?? s} size="small" color="primary" variant="outlined" />
              ))
            )}
          </Stack>
        ) : (
          <Box sx={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 0.5 }}>
            {SPECIALTIES.map((sp) => (
              <FormControlLabel
                key={sp.value}
                control={
                  <Checkbox
                    size="small"
                    checked={selected.includes(sp.value)}
                    onChange={() => toggle(sp.value)}
                  />
                }
                label={<Typography variant="body2">{sp.label}</Typography>}
                sx={{ m: 0 }}
              />
            ))}
          </Box>
        )}
      </Stack>
    </Paper>
  );
}

export default function SpecialtiesPage() {
  const [tab, setTab] = React.useState<"instrumentist" | "surgeon">("instrumentist");

  const instrumentistsQuery = useQuery({
    queryKey: ["users-specialties", "instrumentist"],
    queryFn: async () => {
      const r = await apiClient.get("/api/instrumentists");
      return r.data.items as UserWithSpecialties[];
    },
  });
  const surgeonsQuery = useQuery({
    queryKey: ["users-specialties", "surgeon"],
    queryFn: async () => {
      const r = await apiClient.get("/api/surgeons");
      return r.data.items as UserWithSpecialties[];
    },
  });

  const data = tab === "instrumentist" ? instrumentistsQuery : surgeonsQuery;
  const items = data.data ?? [];

  return (
    <Stack spacing={3}>
      <Typography variant="h6" fontWeight={700}>Compétences & Spécialités</Typography>
      <Typography variant="body2" color="text.secondary" sx={{ maxWidth: 560 }}>
        Définissez les domaines de compétence de chaque instrumentiste et chirurgien.
        Ces informations sont utilisées par l'algorithme de suggestion lors de la génération du planning.
      </Typography>

      {/* Tabs */}
      <Stack direction="row" spacing={1}>
        <Button
          variant={tab === "instrumentist" ? "contained" : "outlined"}
          disableElevation size="small"
          onClick={() => setTab("instrumentist")}
        >Instrumentistes</Button>
        <Button
          variant={tab === "surgeon" ? "contained" : "outlined"}
          disableElevation size="small"
          onClick={() => setTab("surgeon")}
        >Chirurgiens</Button>
      </Stack>

      {data.isLoading ? (
        <CircularProgress size={24} />
      ) : items.length === 0 ? (
        <Typography color="text.secondary">Aucun utilisateur trouvé.</Typography>
      ) : (
        <Box sx={{ display: "grid", gridTemplateColumns: { xs: "1fr", md: "1fr 1fr" }, gap: 2 }}>
          {items.map((u) => (
            <SpecialtiesCard
              key={u.id} user={u}
              role={tab === "instrumentist" ? "Instrumentiste" : "Chirurgien"}
            />
          ))}
        </Box>
      )}
    </Stack>
  );
}
