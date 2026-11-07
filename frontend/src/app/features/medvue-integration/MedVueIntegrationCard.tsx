import * as React from "react";
import { Alert, Box, Button, CircularProgress, Paper, Stack, TextField, Typography } from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  fetchMedVueLink,
  linkMedVueAccount,
  medvueLinkErrorMessage,
  medvueLinkQueryKey,
  revokeMedVueLink,
} from "./api/medvueLink.api";

type Props = {
  /** Compte SurgicalHub concerné : l'utilisateur courant, ou la fiche ouverte par un ADMIN. */
  userId: number;
  /** Fiche d'un autre utilisateur (tiroir admin) : textes à la troisième personne. */
  forOtherUser?: boolean;
  /** Rendu sans cadre, pour s'insérer dans une section existante (tiroir admin). */
  bare?: boolean;
};

const dateFormat = new Intl.DateTimeFormat("fr-BE", { day: "numeric", month: "long", year: "numeric" });

/**
 * D-140 — « Intégration MedVue » : associer un compte MedVue avec le code temporaire généré
 * dans MedVue, voir l'association, la révoquer. Le droit est décidé par le backend
 * (MedVueIntegrationVoter) ; l'état affiché est toujours celui du serveur.
 */
export function MedVueIntegrationCard({ userId, forOtherUser = false, bare = false }: Props) {
  const qc = useQueryClient();
  const [code, setCode] = React.useState("");
  const [confirmRevoke, setConfirmRevoke] = React.useState(false);

  const statusQuery = useQuery({
    queryKey: medvueLinkQueryKey(userId),
    queryFn: () => fetchMedVueLink(userId),
  });

  const linkMutation = useMutation({
    mutationFn: () => linkMedVueAccount(userId, code),
    onSuccess: (data) => {
      // Le code est à usage unique : on l'efface dès la réponse, réussie ou non.
      setCode("");
      qc.setQueryData(medvueLinkQueryKey(userId), data);
    },
    onError: () => setCode(""),
  });

  const revokeMutation = useMutation({
    mutationFn: () => revokeMedVueLink(userId),
    onSuccess: () => {
      setConfirmRevoke(false);
      qc.invalidateQueries({ queryKey: medvueLinkQueryKey(userId) });
    },
  });

  const content = (
    <>
      <Typography variant={bare ? "subtitle2" : "subtitle1"} fontWeight={700} color={bare ? "text.secondary" : undefined} mb={1}>
        Intégration MedVue
      </Typography>

      {statusQuery.isLoading && <CircularProgress size={20} />}

      {statusQuery.isError && (
        <Alert severity="error">Impossible de charger l'état de l'intégration MedVue.</Alert>
      )}

      {statusQuery.data && !statusQuery.data.configured && !statusQuery.data.linked && (
        <Typography variant="body2" color="text.secondary">
          L'intégration MedVue n'est pas disponible sur ce serveur.
        </Typography>
      )}

      {statusQuery.data?.linked && (
        <Stack spacing={1.25}>
          <Typography variant="body2">
            {forOtherUser ? "Ce compte est associé à un compte MedVue" : "Votre compte est associé à votre compte MedVue"}
            {statusQuery.data.linkedAt && ` depuis le ${dateFormat.format(new Date(statusQuery.data.linkedAt))}`}
            {statusQuery.data.linkedBy && ` (par ${statusQuery.data.linkedBy.displayName})`}.
          </Typography>
          <Typography variant="body2" color="text.secondary">
            MedVue lit les congés de ce compte pour les reporter dans son calendrier. Aucune donnée
            de MedVue n'est envoyée à SurgicalHub.
          </Typography>

          {revokeMutation.isError && (
            <Alert severity="error">{medvueLinkErrorMessage(revokeMutation.error)}</Alert>
          )}

          {confirmRevoke ? (
            <Stack spacing={1}>
              <Typography variant="body2">
                MedVue ne pourra plus lire ces congés. Confirmer ?
              </Typography>
              <Stack direction="row" spacing={1} justifyContent="flex-end">
                <Button size="small" color="inherit" onClick={() => setConfirmRevoke(false)} disabled={revokeMutation.isPending}>
                  Annuler
                </Button>
                <Button size="small" color="error" variant="contained" onClick={() => revokeMutation.mutate()} disabled={revokeMutation.isPending}>
                  Dissocier
                </Button>
              </Stack>
            </Stack>
          ) : (
            <Box>
              <Button size="small" color="error" variant="outlined" onClick={() => setConfirmRevoke(true)}>
                Dissocier
              </Button>
            </Box>
          )}
        </Stack>
      )}

      {statusQuery.data && statusQuery.data.configured && !statusQuery.data.linked && (
        <Stack
          spacing={1.25}
          component="form"
          onSubmit={(e: React.FormEvent) => {
            e.preventDefault();
            if (code.trim() !== "") linkMutation.mutate();
          }}
        >
          <Typography variant="body2" color="text.secondary">
            {forOtherUser
              ? "Saisissez le code temporaire généré par cette personne dans MedVue (Mon compte → SurgicalHub). Il prouve qu'elle autorise l'association."
              : "Dans MedVue, ouvrez Mon compte → SurgicalHub et générez un code temporaire, puis saisissez-le ici. Vos congés SurgicalHub apparaîtront dans votre calendrier MedVue."}
          </Typography>

          {linkMutation.isError && (
            <Alert severity="error">{medvueLinkErrorMessage(linkMutation.error)}</Alert>
          )}

          <Stack direction="row" spacing={1} alignItems="flex-start">
            <TextField
              size="small"
              label="Code MedVue"
              placeholder="XXXX-XXXX-XXXX"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              autoComplete="off"
              inputProps={{ maxLength: 20, spellCheck: false, autoCapitalize: "characters" }}
              sx={{ flex: 1 }}
            />
            <Button type="submit" variant="contained" disabled={code.trim() === "" || linkMutation.isPending}>
              {linkMutation.isPending ? <CircularProgress size={18} color="inherit" /> : "Associer"}
            </Button>
          </Stack>
        </Stack>
      )}
    </>
  );

  if (bare) {
    return <Box>{content}</Box>;
  }

  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 3 }}>
      {content}
    </Paper>
  );
}
