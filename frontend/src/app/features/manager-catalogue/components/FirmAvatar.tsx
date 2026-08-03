import { PersonAvatar, type AvatarSize } from "../../../ui/avatar/PersonAvatar";
import { resolveApiAssetUrl } from "../../../api/apiAssetUrl";

interface FirmAvatarProps {
  name: string;
  logoPath?: string | null;
  size?: AvatarSize;
}

/**
 * Seule implémentation du rendu logo/fallback-initiales pour une firme — réutilisée
 * partout où une firme est affichée (sélecteur, bandeau de contexte, référentiel,
 * détail de prestation). Le logo est une propriété exclusive de Firm.logoPath,
 * jamais dupliquée par écran — voir docs/design/screens/catalogue-prestations/README.md.
 * Wrap PersonAvatar (déjà générique nom+photo, utilisé pour chirurgiens/instrumentistes)
 * plutôt que de réimplémenter le fallback initiales.
 */
export function FirmAvatar({ name, logoPath, size = "sm" }: FirmAvatarProps) {
  return <PersonAvatar name={name} photoUrl={resolveApiAssetUrl(logoPath)} size={size} />;
}
