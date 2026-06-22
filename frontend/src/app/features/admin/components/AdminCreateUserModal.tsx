import * as React from "react";
import {
  Alert,
  Button,
  Checkbox,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  FormControlLabel,
  FormGroup,
  FormHelperText,
  FormLabel,
  Radio,
  RadioGroup,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "../../../api/apiClient";
import { createAdminUser } from "../api/admin.api";
import type { AdminCreateUserPayload } from "../api/admin.types";
import { PhoneInputField } from "../../../components/PhoneInputField";

interface Site {
  id: number;
  name: string;
}

interface Props {
  open: boolean;
  onClose: () => void;
}

const ROLES: Array<{ value: AdminCreateUserPayload["role"]; label: string }> = [
  { value: "ROLE_INSTRUMENTIST", label: "Instrumentiste" },
  { value: "ROLE_SURGEON",       label: "Chirurgien" },
  { value: "ROLE_MANAGER",       label: "Manager" },
];

interface FormErrors {
  email?: string;
  firstname?: string;
  lastname?: string;
  phone?: string;
  siteIds?: string;
}

const EMPTY: AdminCreateUserPayload = {
  email: "",
  firstname: "",
  lastname: "",
  phone: "",
  role: "ROLE_INSTRUMENTIST",
  siteIds: [],
};

export function AdminCreateUserModal({ open, onClose }: Props) {
  const qc = useQueryClient();
  const [form, setForm] = React.useState<AdminCreateUserPayload>(EMPTY);
  const [errors, setErrors] = React.useState<FormErrors>({});

  const sitesQuery = useQuery<Site[]>({
    queryKey: ["sites-list"],
    queryFn: async () => {
      const res = await apiClient.get("/api/sites");
      return res.data;
    },
    staleTime: 5 * 60 * 1000,
  });

  const mutation = useMutation({
    mutationFn: createAdminUser,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin-users"] });
      qc.invalidateQueries({ queryKey: ["admin-invitations"] });
      handleClose();
    },
  });

  function handleClose() {
    setForm(EMPTY);
    setErrors({});
    mutation.reset();
    onClose();
  }

  function validate(): boolean {
    const e: FormErrors = {};
    if (!form.email.trim()) e.email = "Email requis";
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) e.email = "Email invalide";
    if (!form.firstname?.trim()) e.firstname = "Prénom requis";
    if (!form.lastname?.trim()) e.lastname = "Nom requis";
    if (form.phone && !/^\+\d{7,15}$/.test(form.phone)) e.phone = "Numéro invalide";
    if (form.siteIds.length === 0) e.siteIds = "Au moins un site requis";
    setErrors(e);
    return Object.keys(e).length === 0;
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!validate()) return;
    mutation.mutate(form);
  }

  function toggleSite(id: number) {
    setForm((f) => ({
      ...f,
      siteIds: f.siteIds.includes(id) ? f.siteIds.filter((s) => s !== id) : [...f.siteIds, id],
    }));
  }

  return (
    <Dialog open={open} onClose={handleClose} maxWidth="sm" fullWidth>
      <form onSubmit={handleSubmit} noValidate>
        <DialogTitle>Créer un utilisateur</DialogTitle>

        <DialogContent dividers>
          <Stack spacing={2.5} sx={{ pt: 0.5 }}>
            {mutation.isError && (
              <Alert severity="error">
                {(mutation.error as Error & { response?: { data?: { detail?: string } } })
                  ?.response?.data?.detail ?? "Une erreur est survenue."}
              </Alert>
            )}

            {mutation.data?.warnings?.map((w) => (
              <Alert key={w.code} severity="warning">{w.message}</Alert>
            ))}

            <Stack direction="row" spacing={2}>
              <TextField
                label="Prénom"
                value={form.firstname}
                onChange={(e) => setForm((f) => ({ ...f, firstname: e.target.value }))}
                error={!!errors.firstname}
                helperText={errors.firstname}
                required
                fullWidth
                size="small"
              />
              <TextField
                label="Nom"
                value={form.lastname}
                onChange={(e) => setForm((f) => ({ ...f, lastname: e.target.value }))}
                error={!!errors.lastname}
                helperText={errors.lastname}
                required
                fullWidth
                size="small"
              />
            </Stack>

            <TextField
              label="Email"
              type="email"
              value={form.email}
              onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
              error={!!errors.email}
              helperText={errors.email}
              required
              fullWidth
              size="small"
            />

            <PhoneInputField
              label="Téléphone (optionnel)"
              value={form.phone ?? ""}
              onChange={(e164) => setForm((f) => ({ ...f, phone: e164 }))}
              error={!!errors.phone}
              helperText={errors.phone}
            />

            <FormControl>
              <FormLabel required>Rôle</FormLabel>
              <RadioGroup
                row
                value={form.role}
                onChange={(e) =>
                  setForm((f) => ({ ...f, role: e.target.value as AdminCreateUserPayload["role"] }))
                }
              >
                {ROLES.map((r) => (
                  <FormControlLabel key={r.value} value={r.value} control={<Radio size="small" />} label={r.label} />
                ))}
              </RadioGroup>
            </FormControl>

            <FormControl error={!!errors.siteIds} required>
              <FormLabel>Sites</FormLabel>
              {sitesQuery.isLoading ? (
                <CircularProgress size={20} sx={{ mt: 1 }} />
              ) : sitesQuery.isError ? (
                <Typography variant="caption" color="error">Impossible de charger les sites.</Typography>
              ) : (
                <FormGroup row>
                  {(sitesQuery.data ?? []).map((s) => (
                    <FormControlLabel
                      key={s.id}
                      control={
                        <Checkbox
                          size="small"
                          checked={form.siteIds.includes(s.id)}
                          onChange={() => toggleSite(s.id)}
                        />
                      }
                      label={s.name}
                    />
                  ))}
                </FormGroup>
              )}
              {errors.siteIds && <FormHelperText>{errors.siteIds}</FormHelperText>}
            </FormControl>
          </Stack>
        </DialogContent>

        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={handleClose} disabled={mutation.isPending}>Annuler</Button>
          <Button type="submit" variant="contained" disabled={mutation.isPending}>
            {mutation.isPending ? <CircularProgress size={18} color="inherit" /> : "Créer"}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  );
}
