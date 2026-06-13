import { useEffect, useRef, useState } from "react";
import { apiClient } from "../../api/apiClient";

async function getVapidPublicKey(): Promise<string> {
  const res = await apiClient.get<{ publicKey: string }>("/api/push/vapid-public-key");
  return res.data.publicKey;
}

function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const rawData = atob(base64);
  return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
}

async function doSubscribe(): Promise<void> {
  const registration = await navigator.serviceWorker.ready;
  const vapidPublicKey = await getVapidPublicKey();
  const applicationServerKey = urlBase64ToUint8Array(vapidPublicKey);

  let subscription = await registration.pushManager.getSubscription();
  if (!subscription) {
    subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: applicationServerKey as unknown as BufferSource,
    });
  }

  const json = subscription.toJSON();
  await apiClient.post("/api/push/subscribe", {
    endpoint: json.endpoint,
    keys: {
      p256dh: json.keys?.p256dh,
      auth: json.keys?.auth,
    },
  });
}

type PushState = "unsupported" | "prompt" | "granted" | "denied";

export function usePushNotifications(): {
  pushState: PushState;
  requestPermission: () => Promise<void>;
} {
  const swRegistered = useRef(false);
  const [pushState, setPushState] = useState<PushState>(() => {
    if (!("Notification" in window) || !("serviceWorker" in navigator) || !("PushManager" in window)) {
      return "unsupported";
    }
    return Notification.permission as PushState;
  });

  // Enregistre le SW au montage (sans demander la permission)
  useEffect(() => {
    if (swRegistered.current) return;
    swRegistered.current = true;
    if (!("serviceWorker" in navigator)) return;

    navigator.serviceWorker.register("/sw.js").catch(() => {});

    // Si déjà accordé, on s'abonne silencieusement
    if (Notification.permission === "granted") {
      doSubscribe().catch(() => {});
    }
  }, []);

  const requestPermission = async () => {
    if (!("Notification" in window)) return;
    const result = await Notification.requestPermission();
    setPushState(result as PushState);
    if (result === "granted") {
      doSubscribe().catch(() => {});
    }
  };

  return { pushState, requestPermission };
}
