import { Box, Typography } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { fetchAppVersion, type AppVersion } from "./appVersion.api";
import { BUILD_REVISION } from "./buildRevision";

/** SHA court du bundle, ou null hors d'une archive de déploiement (développement). */
function bundleCommit(revision: string = BUILD_REVISION): string | null {
  return /^[0-9a-f]{40}$/.test(revision) ? revision.slice(0, 7) : null;
}

function label(v: AppVersion): string {
  if (v.status === "DEVELOPMENT" || !v.commit) return "SurgicalHub · développement";
  if (v.status === "OFFICIAL" && v.version) return `SurgicalHub · ${v.version} · ${v.commit}`;
  return `SurgicalHub · version en cours de validation · ${v.commit}`;
}

/**
 * D-162 — version de SurgicalHub, en bas des paramètres utilisateur (même composant pour
 * les trois rôles). Tout vient du serveur (`GET /api/version`) : tag officiel seulement après
 * validation du déploiement, SHA du code réellement exécuté — rien n'est écrit en dur ici.
 * Relu à chaque ouverture (jamais servi depuis un cache) ; si le bundle chargé (PWA en cache)
 * ne correspond pas au code du serveur, propose d'actualiser.
 */
export function AppVersionLine({ revision }: { revision?: string }) {
  const query = useQuery({
    queryKey: ["app-version"],
    queryFn: fetchAppVersion,
    staleTime: 0,
    gcTime: 0,
    refetchOnMount: "always",
    retry: false,
  });

  const local = bundleCommit(revision);
  const outdated = Boolean(query.data?.commit && local && query.data.commit !== local);

  return (
    <Box data-testid="app-version" sx={{ textAlign: "center", pt: 1, pb: 0.5 }}>
      <Typography variant="caption" sx={{ color: "text.disabled", fontSize: 11, letterSpacing: "0.02em" }}>
        {query.isLoading ? "SurgicalHub" : query.data ? label(query.data) : "SurgicalHub · version indisponible"}
      </Typography>
      {outdated && (
        <Typography variant="caption" component="div" sx={{ color: "text.secondary", fontSize: 11 }}>
          Une nouvelle version est disponible.{" "}
          <Box
            component="button"
            type="button"
            onClick={() => window.location.reload()}
            sx={{ border: "none", background: "transparent", p: 0, font: "inherit", color: "primary.main", fontWeight: 700, cursor: "pointer" }}
          >
            Actualiser
          </Box>
        </Typography>
      )}
    </Box>
  );
}
