import * as React from "react";
import {
  Box, Button, Chip, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogTitle, IconButton, MenuItem, Paper, Select, Stack,
  Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import DeleteIcon from "@mui/icons-material/Delete";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  getAbsences, createAbsence, deleteAbsence, type Absence,
} from "../../../features/planning-manager/api/planning.api";
import { useToast } from "../../../ui/toast/useToast";
import { apiClient } from "../../../api/apiClient";

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

function userName(u: Absence["user"]) {
  return `${u.firstname ?? ""} ${u.lastname ?? ""}`.trim() || u.email;
}

export default function AbsencesPage() {
  const toast = useToast();
  const qc = useQueryClient();

  const [createOpen, setCreateOpen] = React.useState(false);
  const [userId, setUserId] = React.useState<number | "">("");
  const [dateStart, setDateStart] = React.useState(new Date().toISOString().slice(0, 10));
  const [dateEnd, setDateEnd] = React.useState(new Date().toISOString().slice(0, 10));
  const [reason, setReason] = React.useState("");
  const [filterUserId, setFilterUserId] = React.useState<number | "">("");

  const absencesQuery = useQuery({
    queryKey: ["absences", filterUserId],
    queryFn: () => getAbsences(filterUserId ? { userId: filterUserId } : undefined),
  });

  const usersQuery = useQuery({
    queryKey: ["users-for-absences"],
    queryFn: async () => {
      const [instRes, surgRes] = await Promise.all([
        apiClient.get("/api/instrumentists"),
        apiClient.get("/api/surgeons"),
      ]);
      const insts = (instRes.data.items ?? []).map((u: any) => ({ ...u, _role: "Instrumentiste" }));
      const surgs = (surgRes.data.items ?? []).map((u: any) => ({ ...u, _role: "Chirurgien" }));
      return [...insts, ...surgs] as (any & { _role: string })[];
    },
  });

  const createMutation = useMutation({
    mutationFn: () => createAbsence({
      userId: userId as number,
      dateStart,
      dateEnd,
      reason: reason.trim() || undefined,
    }),
    onSuccess: () => {
      toast.success("Absence enregistrée");
      qc.invalidateQueries({ queryKey: ["absences"] });
      setCreateOpen(false);
      setUserId(""); setReason("");
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: deleteAbsence,
    onSuccess: () => { toast.success("Absence supprimée"); qc.invalidateQueries({ queryKey: ["absences"] }); },
    onError: (err) => toast.error(extractError(err)),
  });

  const absences = absencesQuery.data ?? [];

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Typography variant="h6" fontWeight={700}>Gestion des absences</Typography>
        <Button variant="contained" disableElevation startIcon={<AddIcon />} onClick={() => setCreateOpen(true)}>
          Nouvelle absence
        </Button>
      </Stack>

      {/* Filter */}
      <Stack direction="row" spacing={2}>
        <Select
          value={filterUserId}
          onChange={(e) => setFilterUserId(e.target.value as number | "")}
          displayEmpty size="small" sx={{ minWidth: 220 }}
        >
          <MenuItem value="">Toutes les personnes</MenuItem>
          {(usersQuery.data ?? []).map((u) => (
            <MenuItem key={u.id} value={u.id}>
              {`${u.firstname ?? ""} ${u.lastname ?? ""}`.trim() || u.email} ({u._role})
            </MenuItem>
          ))}
        </Select>
      </Stack>

      {/* List */}
      {absencesQuery.isLoading ? (
        <CircularProgress size={24} />
      ) : absences.length === 0 ? (
        <Box sx={{ display: "flex", flexDirection: "column", alignItems: "center", py: 8, gap: 2 }}>
          <img src="https://cdn.undraw.co/illustration/time-management_30iu.svg" alt="" style={{ width: 220, opacity: 0.85 }} />
          <Typography variant="h6" fontWeight={600} color="text.secondary">Aucune absence enregistrée</Typography>
          <Typography variant="body2" color="text.secondary" textAlign="center" sx={{ maxWidth: 360 }}>
            Les absences permettent à SurgicalHub de les exclure automatiquement lors de la génération du planning.
          </Typography>
        </Box>
      ) : (
        <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
          <Table size="small">
            <TableHead>
              <TableRow sx={{ bgcolor: "grey.50" }}>
                <TableCell>Personne</TableCell>
                <TableCell>Du</TableCell>
                <TableCell>Au</TableCell>
                <TableCell>Durée</TableCell>
                <TableCell>Motif</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {absences.map((abs: Absence) => {
                const days = Math.round(
                  (new Date(abs.dateEnd).getTime() - new Date(abs.dateStart).getTime()) / 86400000 + 1
                );
                return (
                  <TableRow key={abs.id} hover>
                    <TableCell sx={{ fontWeight: 600 }}>{userName(abs.user)}</TableCell>
                    <TableCell>{new Date(abs.dateStart + "T00:00:00").toLocaleDateString("fr-BE")}</TableCell>
                    <TableCell>{new Date(abs.dateEnd + "T00:00:00").toLocaleDateString("fr-BE")}</TableCell>
                    <TableCell>
                      <Chip label={`${days} jour${days > 1 ? "s" : ""}`} size="small" variant="outlined" />
                    </TableCell>
                    <TableCell>{abs.reason ?? <Typography component="span" color="text.disabled">—</Typography>}</TableCell>
                    <TableCell align="right">
                      <IconButton
                        size="small" color="error"
                        onClick={() => deleteMutation.mutate(abs.id)}
                        disabled={deleteMutation.isPending}
                      >
                        <DeleteIcon fontSize="small" />
                      </IconButton>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </Paper>
      )}

      {/* Create dialog */}
      <Dialog open={createOpen} onClose={() => setCreateOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle fontWeight={700}>Nouvelle absence</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <Select
              value={userId}
              onChange={(e) => setUserId(e.target.value as number | "")}
              displayEmpty size="small" fullWidth
            >
              <MenuItem value="" disabled>Sélectionner une personne</MenuItem>
              {(usersQuery.data ?? []).map((u) => (
                <MenuItem key={u.id} value={u.id}>
                  {`${u.firstname ?? ""} ${u.lastname ?? ""}`.trim() || u.email} ({u._role})
                </MenuItem>
              ))}
            </Select>
            <Stack direction="row" spacing={1}>
              <TextField
                label="Du" type="date" value={dateStart}
                onChange={(e) => setDateStart(e.target.value)}
                size="small" InputLabelProps={{ shrink: true }} fullWidth
              />
              <TextField
                label="Au" type="date" value={dateEnd}
                onChange={(e) => setDateEnd(e.target.value)}
                size="small" InputLabelProps={{ shrink: true }} fullWidth
              />
            </Stack>
            <TextField
              label="Motif (optionnel)" value={reason}
              onChange={(e) => setReason(e.target.value)}
              size="small" fullWidth
              placeholder="Congés, formation..."
            />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setCreateOpen(false)} color="inherit">Annuler</Button>
          <Button
            variant="contained" disableElevation
            onClick={() => createMutation.mutate()}
            disabled={!userId || !dateStart || !dateEnd || createMutation.isPending}
          >
            {createMutation.isPending ? <CircularProgress size={16} /> : "Enregistrer"}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
