import * as React from "react";
import { useAuth } from "../../auth/AuthContext";
import { useProfilePhotoReminder } from "./useProfilePhotoReminder";
import { wasAccountJustActivated } from "./justActivatedAccountFlag";
import { ProfilePhotoPromptModal } from "./ProfilePhotoPromptModal";

/** Long enough not to read as an interstitial popping up at login, short enough to still catch the user. */
const SHOW_DELAY_MS = 2500;

/**
 * Mounted once above every authenticated route (RequireAppAccess), regardless
 * of role/layout. Shows ProfilePhotoPromptModal when the current user has no
 * profile picture yet and the reminder schedule (useProfilePhotoReminder) says
 * it's due — but never immediately on load, and never right after onboarding.
 */
export function ProfilePhotoPromptGate() {
  const { state } = useAuth();

  if (state.status !== "authenticated") return null;

  const { user } = state;
  const hasPhoto = !!user.profilePictureUrl;

  return <Gate userId={user.id} hasPhoto={hasPhoto} />;
}

function Gate({ userId, hasPhoto }: { userId: number; hasPhoto: boolean }) {
  const { isDue, dismiss } = useProfilePhotoReminder(userId);
  const [delayElapsed, setDelayElapsed] = React.useState(false);
  const [justActivated] = React.useState(() => wasAccountJustActivated());

  React.useEffect(() => {
    const timer = setTimeout(() => setDelayElapsed(true), SHOW_DELAY_MS);
    return () => clearTimeout(timer);
  }, []);

  const shouldShow = !hasPhoto && isDue && delayElapsed && !justActivated;

  return <ProfilePhotoPromptModal open={shouldShow} onDismiss={dismiss} />;
}
