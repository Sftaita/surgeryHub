import { Box, Button, Chip, CircularProgress, Paper, Stack, Typography } from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import PersonOutlineOutlinedIcon from "@mui/icons-material/PersonOutlineOutlined";

import { fetchMe, uploadProfilePicture } from "../../features/me/api/me.api";
import { useAuth } from "../../auth/AuthContext";
import { useToast } from "../../ui/toast/useToast";
import { usePushNotifications } from "../../features/push/usePushNotifications";
import { AvatarUploader } from "../../ui/avatar/AvatarUploader";
import { resolveApiAssetUrl } from "../../api/apiAssetUrl";
import { PageHeader } from "../../ui/PageHeader";
import { EmptyState } from "../../ui/EmptyState";

const ROLE_LABEL: Record<string, string> = {
  MANAGER: "Manager",
  ADMIN: "Administrateur",
};

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <Stack direction="row" justifyContent="space-between" alignItems="center">
      <Typography variant="body2" color="text.secondary">
        {label}
      </Typography>
      <Typography variant="body2" fontWeight={600}>
        {value}
      </Typography>
    </Stack>
  );
}

/**
 * Page profil manager/admin — self-service, informations personnelles en lecture
 * seule (aucun endpoint self-service de mise à jour n'existe côté backend en dehors
 * de la photo ; leur modification passe par un administrateur via /app/admin/users,
 * voir AdminUserController). Seule la photo est modifiable ici, via le même
 * pipeline que le profil instrumentiste (AvatarUploader + POST /api/me/profile-picture).
 */
export default function ProfilePage() {
  const { refreshUser } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const { status: pushStatus, subscribe: subscribeToPush } = usePushNotifications();

  const meQuery = useQuery({ queryKey: ["me"], queryFn: fetchMe });

  const photoMutation = useMutation({
    mutationFn: (file: File) => uploadProfilePicture(file),
    onSuccess: async () => {
      toast.success("Photo de profil mise à jour");
      qc.invalidateQueries({ queryKey: ["me"] });
      await refreshUser();
    },
    onError: () => toast.error("Impossible de mettre à jour la photo de profil"),
  });

  if (meQuery.isLoading) {
    return (
      <Box sx={{ display: "flex", justifyContent: "center", pt: 6 }}>
        <CircularProgress size={28} />
      </Box>
    );
  }

  if (meQuery.isError || !meQuery.data) {
    return (
      <EmptyState
        title="Impossible de charger votre profil"
        description="Une erreur est survenue lors du chargement de vos informations."
        action={{ label: "Réessayer", onClick: () => meQuery.refetch() }}
      />
    );
  }

  const user = meQuery.data;
  const displayName = [user.firstname, user.lastname].filter(Boolean).join(" ") || user.email;
  const roleLabel = ROLE_LABEL[user.role] ?? user.role;

  return (
    <Box sx={{ maxWidth: 640, mx: "auto" }}>
      <PageHeader icon={PersonOutlineOutlinedIcon} title="Mon profil" subtitle="Vos informations de compte." />

      <Stack spacing={2.5}>
        <Paper variant="outlined" sx={{ p: 2, borderRadius: 3 }}>
          <Stack direction="row" spacing={2} alignItems="center">
            <AvatarUploader
              name={displayName}
              photoUrl={resolveApiAssetUrl(user.profilePictureUrl)}
              size="lg"
              onFileReady={async (file) => {
                await photoMutation.mutateAsync(file);
              }}
            />
            <Box>
              <Typography variant="h6" fontWeight={700} sx={{ lineHeight: 1.2 }}>
                {displayName}
              </Typography>
              <Chip size="small" label={roleLabel} sx={{ mt: 0.5, fontSize: "0.7rem" }} />
            </Box>
          </Stack>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2, borderRadius: 3 }}>
          <Typography variant="subtitle1" fontWeight={700} mb={1.5}>
            Informations personnelles
          </Typography>
          <Stack spacing={1.25}>
            <InfoRow label="Prénom" value={user.firstname ?? "—"} />
            <InfoRow label="Nom" value={user.lastname ?? "—"} />
            <InfoRow label="E-mail" value={user.email} />
            <InfoRow label="Rôle" value={roleLabel} />
          </Stack>
          <Typography variant="caption" color="text.secondary" sx={{ display: "block", mt: 1.5 }}>
            La modification de ces informations doit être réalisée par un administrateur.
          </Typography>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2, borderRadius: 3 }}>
          <Typography variant="subtitle1" fontWeight={700} mb={1}>
            Notifications
          </Typography>
          {pushStatus === "permission-default" && (
            <Stack direction="row" alignItems="center" justifyContent="space-between" gap={1}>
              <Typography variant="body2" color="text.secondary">
                Activez les notifications pour rester informé des nouvelles demandes.
              </Typography>
              <Button variant="outlined" size="small" onClick={() => void subscribeToPush()}>
                Activer
              </Button>
            </Stack>
          )}
          {pushStatus === "subscribed" && (
            <Typography variant="body2" color="text.secondary">
              Notifications activées sur cet appareil.
            </Typography>
          )}
          {pushStatus === "permission-denied" && (
            <Typography variant="body2" color="text.secondary">
              Notifications bloquées par le navigateur. Modifiez ce réglage dans les paramètres du navigateur pour les réactiver.
            </Typography>
          )}
          {pushStatus === "unsupported" && (
            <Typography variant="body2" color="text.secondary">
              Les notifications ne sont pas prises en charge par ce navigateur.
            </Typography>
          )}
        </Paper>
      </Stack>
    </Box>
  );
}
