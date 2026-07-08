import { useEffect, useRef, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { useMutation, useQuery } from "@tanstack/react-query";
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Divider,
  Stack,
  TextField,
  Typography,
} from "@mui/material";

import {
  checkInvitation,
  completeInvitation,
} from "../features/invitation/api/invitation.api";
import { AvatarUploader } from "../ui/avatar/AvatarUploader";
import { markAccountJustActivated } from "../features/me/justActivatedAccountFlag";

function validateForm(fields: {
  firstname: string;
  lastname: string;
  phone: string;
  password: string;
  confirmPassword: string;
}): Record<string, string> {
  const errors: Record<string, string> = {};

  if (!fields.firstname.trim()) errors.firstname = "Le prénom est requis.";
  if (!fields.lastname.trim()) errors.lastname = "Le nom est requis.";
  if (!fields.phone.trim()) errors.phone = "Le téléphone est requis.";

  if (!fields.password) {
    errors.password = "Le mot de passe est requis.";
  } else if (fields.password.length < 8) {
    errors.password = "Le mot de passe doit contenir au moins 8 caractères.";
  }

  if (!fields.confirmPassword) {
    errors.confirmPassword = "La confirmation est requise.";
  } else if (fields.password !== fields.confirmPassword) {
    errors.confirmPassword = "Les mots de passe ne correspondent pas.";
  }

  return errors;
}

export default function CompleteAccountPage() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const token = searchParams.get("token") ?? "";
  const firstnameRef = useRef<HTMLInputElement>(null);

  const [firstname, setFirstname] = useState("");
  const [lastname, setLastname] = useState("");
  const [phone, setPhone] = useState("");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [companyName, setCompanyName] = useState("");
  const [vatNumber, setVatNumber] = useState("");
  const [profilePicture, setProfilePicture] = useState<File | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const tokenCheckQuery = useQuery({
    queryKey: ["invitation-check", token],
    queryFn: () => checkInvitation(token),
    enabled: token.length > 0,
    retry: false,
  });

  useEffect(() => {
    const data = tokenCheckQuery.data;
    if (!data) return;

    if (data.status === "used") {
      navigate("/login", { replace: true });
      return;
    }

    if (data.invitation) {
      setFirstname(data.invitation.firstname ?? "");
      setLastname(data.invitation.lastname ?? "");
    }
  }, [tokenCheckQuery.data, navigate]);

  const completeMutation = useMutation({
    mutationFn: (formData: FormData) => completeInvitation(formData),
    onSuccess: () => {
      markAccountJustActivated();
      setDone(true);
    },
    onError: (err: any) => {
      const status = err?.response?.status;
      const message =
        err?.response?.data?.error?.message ??
        err?.response?.data?.message ??
        err?.message ??
        "Une erreur est survenue.";

      if (status === 409) {
        navigate("/login", { replace: true });
        return;
      }

      setSubmitError(message);
    },
  });

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    setSubmitError(null);

    const errors = validateForm({
      firstname,
      lastname,
      phone,
      password,
      confirmPassword,
    });

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    setFieldErrors({});

    const formData = new FormData();
    formData.append("token", token);
    formData.append("firstname", firstname.trim());
    formData.append("lastname", lastname.trim());
    formData.append("phone", phone.trim());
    formData.append("password", password);
    formData.append("confirmPassword", confirmPassword);
    if (companyName.trim()) formData.append("companyName", companyName.trim());
    if (vatNumber.trim()) formData.append("vatNumber", vatNumber.trim());
    if (profilePicture) formData.append("profilePicture", profilePicture);

    completeMutation.mutate(formData);
  };

  const clearFieldError = (field: string) => {
    if (fieldErrors[field]) {
      setFieldErrors((prev) => ({ ...prev, [field]: "" }));
    }
  };

  // ── Rendu ──────────────────────────────────────────────────────────────────

  if (!token) {
    return (
      <PageShell>
        <Alert severity="error">Lien d'invitation invalide ou manquant.</Alert>
      </PageShell>
    );
  }

  if (tokenCheckQuery.isLoading) {
    return (
      <PageShell>
        <Stack alignItems="center" spacing={2}>
          <CircularProgress size={28} />
          <Typography variant="body2" color="text.secondary">
            Vérification du lien…
          </Typography>
        </Stack>
      </PageShell>
    );
  }

  if (tokenCheckQuery.isError) {
    return (
      <PageShell>
        <Alert severity="error">
          Impossible de vérifier ce lien. Vérifie ta connexion et réessaie.
        </Alert>
      </PageShell>
    );
  }

  if (tokenCheckQuery.data?.status === "invalid") {
    return (
      <PageShell>
        <Alert severity="error">
          Ce lien d'invitation est invalide. Contacte ton manager.
        </Alert>
      </PageShell>
    );
  }

  if (tokenCheckQuery.data?.status === "expired") {
    return (
      <PageShell>
        <Alert severity="warning">
          Ce lien d'invitation a expiré. Demande à ton manager de t'en envoyer un nouveau.
        </Alert>
      </PageShell>
    );
  }

  if (done) {
    return (
      <PageShell>
        <Stack spacing={2}>
          <Alert severity="success">
            Ton compte est activé ! Tu peux maintenant te connecter.
          </Alert>
          <Button variant="contained" onClick={() => navigate("/login")}>
            Se connecter
          </Button>
        </Stack>
      </PageShell>
    );
  }

  const invitation = tokenCheckQuery.data?.invitation;
  const isPending = completeMutation.isPending;

  return (
    <PageShell>
      <Stack spacing={0.5} sx={{ mb: 1 }}>
        <Typography variant="h5" fontWeight={700}>
          Finaliser mon compte
        </Typography>
        {invitation && (
          <Typography variant="body2" color="text.secondary">
            Bienvenue,{" "}
            <strong>{invitation.displayName || invitation.email}</strong>
          </Typography>
        )}
      </Stack>

      <Divider />

      <Box component="form" onSubmit={handleSubmit} noValidate>
        <Stack spacing={2} sx={{ mt: 2 }}>
          {/* Photo de profil — étape mise en avant */}
          <Box
            sx={{
              p: 2,
              borderRadius: 3,
              border: "1px solid",
              borderColor: "divider",
              bgcolor: "grey.50",
            }}
          >
            <Stack spacing={1.5} alignItems="center" textAlign="center">
              <Typography variant="subtitle1" fontWeight={700}>
                Ajoutez une photo de profil
              </Typography>
              <Typography variant="body2" color="text.secondary">
                Elle aide les managers, chirurgiens et instrumentistes à vous identifier rapidement.
              </Typography>

              <AvatarUploader
                name={[firstname, lastname].filter(Boolean).join(" ") || "Vous"}
                size="xl"
                disabled={isPending}
                onFileReady={(file) => setProfilePicture(file)}
                onRemove={() => setProfilePicture(null)}
              />

              {!profilePicture && (
                <Button
                  variant="text"
                  color="inherit"
                  onClick={() => firstnameRef.current?.focus()}
                  disabled={isPending}
                >
                  Continuer sans photo
                </Button>
              )}

              <Typography variant="caption" color="text.secondary">
                JPEG, PNG ou WebP — 5 Mo max. Optionnel : vous pouvez l'ajouter plus tard depuis votre profil.
              </Typography>
            </Stack>
          </Box>

          <Divider />

          {/* Identité */}
          <Stack direction={{ xs: "column", sm: "row" }} spacing={1.5}>
            <TextField
              inputRef={firstnameRef}
              label="Prénom"
              value={firstname}
              onChange={(e) => {
                setFirstname(e.target.value);
                clearFieldError("firstname");
              }}
              error={!!fieldErrors.firstname}
              helperText={fieldErrors.firstname}
              required
              fullWidth
              disabled={isPending}
            />
            <TextField
              label="Nom"
              value={lastname}
              onChange={(e) => {
                setLastname(e.target.value);
                clearFieldError("lastname");
              }}
              error={!!fieldErrors.lastname}
              helperText={fieldErrors.lastname}
              required
              fullWidth
              disabled={isPending}
            />
          </Stack>

          <TextField
            label="Téléphone"
            value={phone}
            onChange={(e) => {
              setPhone(e.target.value);
              clearFieldError("phone");
            }}
            error={!!fieldErrors.phone}
            helperText={fieldErrors.phone}
            required
            fullWidth
            disabled={isPending}
            inputProps={{ inputMode: "tel" }}
          />

          <Divider />

          {/* Informations professionnelles (optionnel) */}
          <Stack spacing={1.5}>
            <Typography variant="subtitle2" color="text.secondary">
              Informations professionnelles (optionnel)
            </Typography>
            <TextField
              label="Nom de société"
              value={companyName}
              onChange={(e) => setCompanyName(e.target.value)}
              fullWidth
              disabled={isPending}
            />
            <TextField
              label="Numéro de TVA"
              value={vatNumber}
              onChange={(e) => setVatNumber(e.target.value)}
              fullWidth
              disabled={isPending}
            />
          </Stack>

          <Divider />

          {/* Mot de passe */}
          <Stack spacing={1.5}>
            <Typography variant="subtitle2">Mot de passe</Typography>
            <TextField
              label="Mot de passe"
              type="password"
              value={password}
              onChange={(e) => {
                setPassword(e.target.value);
                clearFieldError("password");
                clearFieldError("confirmPassword");
              }}
              error={!!fieldErrors.password}
              helperText={fieldErrors.password || "8 caractères minimum."}
              required
              fullWidth
              disabled={isPending}
              autoComplete="new-password"
            />
            <TextField
              label="Confirmer le mot de passe"
              type="password"
              value={confirmPassword}
              onChange={(e) => {
                setConfirmPassword(e.target.value);
                clearFieldError("confirmPassword");
              }}
              error={!!fieldErrors.confirmPassword}
              helperText={fieldErrors.confirmPassword}
              required
              fullWidth
              disabled={isPending}
              autoComplete="new-password"
            />
          </Stack>

          {submitError && <Alert severity="error">{submitError}</Alert>}

          <Button
            type="submit"
            variant="contained"
            size="large"
            disabled={isPending}
            fullWidth
          >
            {isPending ? "Activation en cours…" : "Activer mon compte"}
          </Button>
        </Stack>
      </Box>
    </PageShell>
  );
}

function PageShell({ children }: { children: React.ReactNode }) {
  return (
    <Box
      sx={{
        minHeight: "100vh",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        p: 2,
        bgcolor: "grey.50",
      }}
    >
      <Box
        sx={{
          width: "100%",
          maxWidth: 540,
          bgcolor: "background.paper",
          border: "1px solid",
          borderColor: "divider",
          borderRadius: 3,
          p: { xs: 2.5, sm: 4 },
        }}
      >
        <Stack spacing={2.5}>{children}</Stack>
      </Box>
    </Box>
  );
}
