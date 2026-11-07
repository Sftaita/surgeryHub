import { beforeEach, describe, expect, it } from "vitest";
import { recordPushInvitationDismissal, shouldShowPushInvitation } from "./pushInvitationStorage";

const DAY = 24 * 60 * 60 * 1000;

describe("pushInvitationStorage — report de l'invitation push (D-143)", () => {
  beforeEach(() => window.localStorage.clear());

  it("proposée tant qu'aucun report n'a eu lieu", () => {
    expect(shouldShowPushInvitation()).toBe(true);
  });

  it("1er report : 7 jours, 2e : 14 jours, 3e : plus jamais automatiquement", () => {
    const t0 = 1_000_000_000_000;
    recordPushInvitationDismissal(t0);
    expect(shouldShowPushInvitation(t0 + 6 * DAY)).toBe(false);
    expect(shouldShowPushInvitation(t0 + 7 * DAY)).toBe(true);

    recordPushInvitationDismissal(t0 + 7 * DAY);
    expect(shouldShowPushInvitation(t0 + 20 * DAY)).toBe(false);
    expect(shouldShowPushInvitation(t0 + 21 * DAY)).toBe(true);

    recordPushInvitationDismissal(t0 + 21 * DAY);
    expect(shouldShowPushInvitation(t0 + 400 * DAY)).toBe(false);
  });
});
