import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AdminOutboundNotificationDrawer } from "./AdminOutboundNotificationDrawer";
import type { OutboundNotificationDetail } from "../api/admin.types";

vi.mock("../api/admin.api", () => ({
  getAdminOutboundNotification: vi.fn(),
}));

import { getAdminOutboundNotification } from "../api/admin.api";

function buildDetail(overrides: Partial<OutboundNotificationDetail> = {}): OutboundNotificationDetail {
  return {
    id: 1,
    createdAt: "2026-07-26T08:05:00+02:00",
    recipient: { id: 12, name: "Jane Doe", email: "jane@example.com" },
    channel: "PUSH",
    notificationType: "ENCODING_REMINDER_D1",
    status: "SENT",
    title: "Encodage à finaliser",
    subject: null,
    missionId: 690,
    attemptCount: 1,
    fallback: false,
    bodyText: "La mission d'hier n'a pas encore été soumise.",
    bodyHtml: null,
    payload: { missionId: 690, url: "/app/i/missions/690" },
    planningVersionId: null,
    queuedAt: null,
    sentAt: "2026-07-26T08:05:01+02:00",
    failedAt: null,
    failureCode: null,
    failureMessage: null,
    fallbackOfId: null,
    fallbackReason: null,
    attempts: [
      { attemptNumber: 1, startedAt: "2026-07-26T08:05:00+02:00", finishedAt: "2026-07-26T08:05:01+02:00", success: true, statusCode: 201, reason: null, provider: "FCM" },
    ],
    ...overrides,
  };
}

beforeEach(() => {
  vi.mocked(getAdminOutboundNotification).mockReset();
});

function renderDrawer(id: number | null) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AdminOutboundNotificationDrawer id={id} onClose={() => {}} />
    </QueryClientProvider>,
  );
}

describe("AdminOutboundNotificationDrawer", () => {
  it("affiche le contenu texte d'une notification Push", async () => {
    vi.mocked(getAdminOutboundNotification).mockResolvedValue(buildDetail());

    renderDrawer(1);

    await screen.findByText("La mission d'hier n'a pas encore été soumise.");
    expect(screen.getByText("Jane Doe")).toBeInTheDocument();
    expect(screen.getByText("jane@example.com")).toBeInTheDocument();
  });

  it("affiche le détail d'une tentative Push (fournisseur, code HTTP)", async () => {
    vi.mocked(getAdminOutboundNotification).mockResolvedValue(buildDetail());

    renderDrawer(1);

    await screen.findByText(/FCM/);
    expect(screen.getByText(/HTTP 201/)).toBeInTheDocument();
  });

  it("affiche le contenu d'une notification email (sujet, aperçu HTML sécurisé)", async () => {
    vi.mocked(getAdminOutboundNotification).mockResolvedValue(buildDetail({
      channel: "EMAIL",
      title: null,
      subject: "SurgicalHub — Encodage à finaliser",
      bodyHtml: "<p>Bonjour Jane</p>",
      attempts: [
        { attemptNumber: 1, startedAt: "2026-07-26T08:05:00+02:00", finishedAt: "2026-07-26T08:05:01+02:00", success: true, statusCode: null, reason: null, provider: "SMTP" },
      ],
    }));
    const user = userEvent.setup();

    renderDrawer(1);

    await screen.findByText("SurgicalHub — Encodage à finaliser");
    // Text tab is the default — no raw HTML rendered as markup.
    expect(screen.getByText("La mission d'hier n'a pas encore été soumise.")).toBeInTheDocument();

    await user.click(screen.getByRole("tab", { name: "Aperçu HTML" }));
    const iframe = screen.getByTitle("Aperçu email") as HTMLIFrameElement;
    expect(iframe.tagName).toBe("IFRAME");
    expect(iframe.getAttribute("sandbox")).toBe("");
    expect(iframe.getAttribute("srcdoc") ?? iframe.getAttribute("srcDoc")).toContain("Bonjour Jane");
  });

  it("affiche le repli email et sa raison", async () => {
    vi.mocked(getAdminOutboundNotification).mockResolvedValue(buildDetail({
      channel: "EMAIL",
      fallback: true,
      fallbackOfId: 41,
      fallbackReason: "NO_SUBSCRIPTION",
    }));

    renderDrawer(1);

    await screen.findByText("Repli email");
    expect(screen.getByText("Aucun abonnement Push")).toBeInTheDocument();
  });

  it("affiche l'aide « accepté ne garantit pas la lecture »", async () => {
    vi.mocked(getAdminOutboundNotification).mockResolvedValue(buildDetail());

    renderDrawer(1);

    await screen.findByText(/ne garantit pas que le message a été lu/);
  });

  it("n'affiche jamais d'endpoint, de clé ou de secret", async () => {
    vi.mocked(getAdminOutboundNotification).mockResolvedValue(buildDetail());

    const { container } = renderDrawer(1);
    await screen.findByText("Jane Doe");

    const text = container.textContent ?? "";
    expect(text).not.toMatch(/fcm\.googleapis\.com\/fcm\/send\//);
    expect(text).not.toMatch(/p256dh/i);
    expect(text).not.toMatch(/VAPID/);
  });

  it("n'affiche rien tant qu'aucun id n'est fourni", () => {
    renderDrawer(null);

    expect(screen.queryByText("Jane Doe")).not.toBeInTheDocument();
    expect(getAdminOutboundNotification).not.toHaveBeenCalled();
  });
});
