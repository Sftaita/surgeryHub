import { Box } from "@mui/material";
import { usePwaInstall } from "./PwaInstallProvider";

const GREEN_500 = "#42A882";
const GREEN_700 = "#2C7D5F";
const GRAY_500  = "#727E8C";
const GRAY_600  = "#566270";
const GRAY_900  = "#16202B";

const TITLE = "Installez SurgicalHub";
const SUBTITLE = "Accédez plus rapidement à votre planning et recevez vos notifications importantes.";

/**
 * Bannière d'invitation à l'installation — mobile uniquement (montée dans MobileLayout,
 * jamais DesktopLayout, voir D-082 : le point d'entrée permanent du menu compte reste,
 * lui, disponible partout). Ne rend rien tant que `showBanner` est faux — jamais sur
 * l'écran de login (non authentifié), jamais si déjà installée, jamais au-delà du seuil
 * de reports (voir pwaInstallStorage.ts).
 */
export function PwaInstallBanner() {
  const { status, showBanner, dismissBanner, openIosGuide, promptAndroidInstall } = usePwaInstall();

  if (!showBanner) return null;

  const isIos = status === "ios-installable";

  async function handleInstallClick() {
    await promptAndroidInstall();
    // Le statut se recalcule seul au prochain rendu (canPromptAndroid retombe à false
    // après usage) — aucune action supplémentaire nécessaire ici, y compris sur refus
    // ou erreur : voir PwaInstallProvider::promptAndroidInstall.
  }

  return (
    <Box
      sx={{
        display: "flex",
        alignItems: "center",
        gap: "12px",
        mb: "14px",
        px: "14px",
        py: "12px",
        borderRadius: "14px",
        background: "#fff",
        boxShadow: "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)",
      }}
    >
      <Box
        sx={{
          width: 38, height: 38, borderRadius: "10px", flexShrink: 0,
          background: `linear-gradient(140deg, ${GREEN_700}, ${GREEN_500})`,
          color: "#fff", fontSize: 12, fontWeight: 800,
          display: "flex", alignItems: "center", justifyContent: "center",
        }}
      >
        SH
      </Box>
      <Box sx={{ flex: 1, minWidth: 0 }}>
        <Box sx={{ fontSize: 13, fontWeight: 800, color: GRAY_900 }}>{TITLE}</Box>
        <Box sx={{ fontSize: 11.5, color: GRAY_500, lineHeight: 1.35, mt: "1px" }}>{SUBTITLE}</Box>
      </Box>
      <Box sx={{ display: "flex", flexDirection: "column", alignItems: "flex-end", gap: "4px", flexShrink: 0 }}>
        <Box
          component="button"
          type="button"
          onClick={isIos ? openIosGuide : handleInstallClick}
          sx={{ border: "none", background: "transparent", color: GREEN_700, fontWeight: 700, fontSize: 12.5, cursor: "pointer", p: 0, whiteSpace: "nowrap" }}
        >
          {isIos ? "Voir comment faire" : "Installer"}
        </Box>
        <Box
          component="button"
          type="button"
          onClick={dismissBanner}
          sx={{ border: "none", background: "transparent", color: GRAY_600, fontWeight: 600, fontSize: 11, cursor: "pointer", p: 0 }}
        >
          Plus tard
        </Box>
      </Box>
    </Box>
  );
}
