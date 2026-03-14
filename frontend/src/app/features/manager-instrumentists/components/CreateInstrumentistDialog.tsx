import * as React from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Autocomplete,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { MuiTelInput } from "mui-tel-input";

import { createInstrumentist } from "../api/instrumentists.api";
import { fetchSites, type Site } from "../../sites/api/sites.api";
import { useToast } from "../../../ui/toast/useToast";

type Props = {
  open: boolean;
  onClose: () => void;
};

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    "Une erreur est survenue."
  );
}

function buildWarningsMessage(
  warnings: { code: string; message?: string }[],
): string {
  if (!warnings.length) {
    return "";
  }

  return warnings
    .map((warning) => {
      if (warning.message && warning.message.trim() !== "") {
        return warning.message;
      }

      switch (warning.code) {
        case "INVITATION_EMAIL_NOT_SENT":
          return "Invitation créée, mais l’email d’invitation n’a pas pu être envoyé.";
        default:
          return warning.code;
      }
    })
    .join(" ");
}

function normalizePhoneValue(value: string): string {
  const trimmed = value.trim();

  if (trimmed === "") {
    return "";
  }

  return trimmed.replace(/[^\d+]/g, "");
}

export function CreateInstrumentistDialog({ open, onClose }: Props) {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [firstname, setFirstname] = React.useState("");
  const [lastname, setLastname] = React.useState("");
  const [email, setEmail] = React.useState("");
  const [phone, setPhone] = React.useState("");
  const [selectedSites, setSelectedSites] = React.useState<Site[]>([]);
  const [loading, setLoading] = React.useState(false);
  const [emailError, setEmailError] = React.useState<string>("");

  const {
    data: sites = [],
    isLoading: sitesLoading,
    isError: sitesError,
  } = useQuery<Site[]>({
    queryKey: ["sites"],
    queryFn: fetchSites,
    enabled: open,
  });

  React.useEffect(() => {
    if (!open) {
      return;
    }

    setFirstname("");
    setLastname("");
    setEmail("");
    setPhone("");
    setSelectedSites([]);
    setLoading(false);
    setEmailError("");
  }, [open]);

  const handleSubmit = async () => {
    if (loading) {
      return;
    }

    const trimmedFirstname = firstname.trim();
    const trimmedLastname = lastname.trim();
    const trimmedEmail = email.trim();
    const normalizedPhone = normalizePhoneValue(phone);
    const siteIds = selectedSites.map((site) => site.id);

    if (trimmedEmail === "") {
      setEmailError("L’email est requis.");
      return;
    }

    setEmailError("");
    setLoading(true);

    try {
      const response = await createInstrumentist({
        firstname: trimmedFirstname,
        lastname: trimmedLastname,
        email: trimmedEmail,
        phone: normalizedPhone,
        siteIds,
      });

      await queryClient.invalidateQueries({ queryKey: ["instrumentists"] });

      toast.success("Instrumentiste créé.");

      if (response.warnings.length > 0) {
        toast.error(buildWarningsMessage(response.warnings));
      }

      onClose();
    } catch (err: any) {
      toast.error(extractErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog
      open={open}
      onClose={loading ? undefined : onClose}
      fullWidth
      maxWidth="sm"
    >
      <DialogTitle>Nouvel instrumentiste</DialogTitle>

      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <Typography variant="body2" color="text.secondary">
            Création rapide d’un instrumentiste.
          </Typography>

          <TextField
            label="Prénom"
            value={firstname}
            onChange={(event) => setFirstname(event.target.value)}
            disabled={loading}
            fullWidth
            size="small"
          />

          <TextField
            label="Nom"
            value={lastname}
            onChange={(event) => setLastname(event.target.value)}
            disabled={loading}
            fullWidth
            size="small"
          />

          <TextField
            label="Email"
            value={email}
            onChange={(event) => {
              setEmail(event.target.value);
              if (emailError) {
                setEmailError("");
              }
            }}
            disabled={loading}
            fullWidth
            size="small"
            type="email"
            error={emailError !== ""}
            helperText={emailError}
          />

          <MuiTelInput
            label="Téléphone"
            value={phone}
            onChange={(value) => setPhone(value)}
            defaultCountry="BE"
            disabled={loading}
            fullWidth
            size="small"
            forceCallingCode
          />

          <Autocomplete
            multiple
            options={sites}
            value={selectedSites}
            onChange={(_event, value) => setSelectedSites(value)}
            disabled={loading || sitesLoading}
            loading={sitesLoading}
            getOptionLabel={(option) => option.name}
            isOptionEqualToValue={(option, value) => option.id === value.id}
            noOptionsText={
              sitesError
                ? "Impossible de charger les sites"
                : "Aucun site disponible"
            }
            renderTags={(value, getTagProps) =>
              value.map((option, index) => (
                <Chip
                  label={option.name}
                  size="small"
                  {...getTagProps({ index })}
                  key={option.id}
                />
              ))
            }
            renderInput={(params) => (
              <TextField
                {...params}
                label="Sites d’activité"
                placeholder={
                  selectedSites.length === 0
                    ? "Rechercher un ou plusieurs sites"
                    : ""
                }
                helperText={
                  sitesError
                    ? "Impossible de charger les sites. Vous pouvez créer l’instrumentiste sans site."
                    : undefined
                }
              />
            )}
          />

          {sitesError ? (
            <Alert severity="warning">
              Impossible de charger les sites. Vous pouvez créer
              l’instrumentiste sans site.
            </Alert>
          ) : null}
        </Stack>
      </DialogContent>

      <DialogActions>
        <Button onClick={onClose} disabled={loading}>
          Annuler
        </Button>

        <Button variant="contained" onClick={handleSubmit} disabled={loading}>
          {loading ? "Création..." : "Créer"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
