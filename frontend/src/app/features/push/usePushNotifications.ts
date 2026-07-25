// Public entry point for the push notification hook. The actual state machine and the
// single app-wide Service Worker registration live in PushProvider.tsx (mounted once in
// AppProviders.tsx) — this file just re-exports the consumer-facing hook so existing
// import sites (MobileLayout, DesktopLayout, ...) don't need to know that.
export { usePushNotifications } from "./PushProvider";
export type { PushNotificationStatus } from "./PushProvider";
