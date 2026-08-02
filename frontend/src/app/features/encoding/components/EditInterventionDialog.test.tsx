import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import EditInterventionDialog from "./EditInterventionDialog";
import type { MissionEncodingInterventionEntry } from "../api/encoding.types";

const apiGetMock = vi.fn();

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
  },
}));

const TYPES = [
  { id: 1, code: "LCA", label: "Ligamentoplastie croisé antérieur" },
  { id: 2, code: "PTG", label: "Prothèse totale de genou" },
];
const FIRMS = [
  { id: 10, name: "Arthrex", active: true },
  { id: 11, name: "Medacta", active: true },
];

function makeIntervention(overrides: Partial<MissionEncodingInterventionEntry> = {}): MissionEncodingInterventionEntry {
  return {
    kind: "INTERVENTION",
    id: 1,
    requestId: null,
    orderIndex: 0,
    label: "LCA",
    interventionType: TYPES[0],
    firm: { id: 10, name: "Arthrex" },
    requestedFirmNameSnapshot: null,
    status: "CATALOGUED",
    readOnly: false,
    materialLines: [],
    materialItemRequests: [],
    representativePresent: null,
    ...overrides,
  };
}

function renderDialog(props: Partial<React.ComponentProps<typeof EditInterventionDialog>> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const onSubmit = props.onSubmit ?? vi.fn();
  const onClose = props.onClose ?? vi.fn();
  render(
    <QueryClientProvider client={client}>
      <EditInterventionDialog
        open
        loading={false}
        intervention={makeIntervention()}
        interventionTypes={TYPES}
        firms={FIRMS}
        onClose={onClose}
        onSubmit={onSubmit}
        {...props}
      />
    </QueryClientProvider>,
  );
  return { onSubmit, onClose };
}

beforeEach(() => {
  apiGetMock.mockReset();
  apiGetMock.mockImplementation(() => Promise.resolve({ data: [] }));
});

describe("EditInterventionDialog — présence d'un délégué (D-092)", () => {
  it("préremplit la réponse Oui existante et permet d'enregistrer sans y retoucher", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => {
      if (url.includes("/firms/10/service-offerings")) {
        return Promise.resolve({ data: [{ id: 501, interventionType: TYPES[0], active: true, representativePresenceRelevant: true }] });
      }
      return Promise.resolve({ data: [] });
    });
    const { onSubmit } = renderDialog({ intervention: makeIntervention({ representativePresent: true }) });

    expect(await screen.findByText("Un délégué de Arthrex était-il présent ?")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Enregistrer" })).not.toBeDisabled();

    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(onSubmit).toHaveBeenCalledWith({ interventionTypeId: 1, primaryFirmId: 10, representativePresent: true });
  });

  it("préremplit la réponse Non existante", async () => {
    apiGetMock.mockImplementation((url: string) => {
      if (url.includes("/firms/10/service-offerings")) {
        return Promise.resolve({ data: [{ id: 501, interventionType: TYPES[0], active: true, representativePresenceRelevant: true }] });
      }
      return Promise.resolve({ data: [] });
    });
    renderDialog({ intervention: makeIntervention({ representativePresent: false }) });

    expect(await screen.findByText("Un délégué de Arthrex était-il présent ?")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Enregistrer" })).not.toBeDisabled();
  });

  it("ancienne intervention avec representativePresent=null : le formulaire s'ouvre normalement mais exige une réponse avant sauvegarde", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => {
      if (url.includes("/firms/10/service-offerings")) {
        return Promise.resolve({ data: [{ id: 501, interventionType: TYPES[0], active: true, representativePresenceRelevant: true }] });
      }
      return Promise.resolve({ data: [] });
    });
    const { onSubmit } = renderDialog({ intervention: makeIntervention({ representativePresent: null }) });

    expect(await screen.findByText("Un délégué de Arthrex était-il présent ?")).toBeInTheDocument();
    expect(screen.getByText("Indiquez si un délégué de Arthrex était présent.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeDisabled();

    await user.click(screen.getByText("Non"));
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(onSubmit).toHaveBeenCalledWith({ interventionTypeId: 1, primaryFirmId: 10, representativePresent: false });
  });

  it("prestation non concernée : la question n'apparaît jamais", async () => {
    apiGetMock.mockImplementation((url: string) => {
      if (url.includes("/firms/10/service-offerings")) {
        return Promise.resolve({ data: [{ id: 501, interventionType: TYPES[0], active: true, representativePresenceRelevant: false }] });
      }
      return Promise.resolve({ data: [] });
    });
    renderDialog();

    await screen.findByRole("button", { name: "Enregistrer" });
    expect(screen.queryByText(/délégué/i)).not.toBeInTheDocument();
  });

  it("changer de type vers une prestation pertinente fait apparaître la question sans valeur préremplie", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => {
      if (url.includes("/firms/10/service-offerings")) {
        return Promise.resolve({
          data: [
            { id: 501, interventionType: TYPES[0], active: true, representativePresenceRelevant: false },
            { id: 502, interventionType: TYPES[1], active: true, representativePresenceRelevant: true },
          ],
        });
      }
      return Promise.resolve({ data: [] });
    });
    const { onSubmit } = renderDialog({ intervention: makeIntervention({ representativePresent: null }) });

    await screen.findByRole("button", { name: "Enregistrer" });
    expect(screen.queryByText(/délégué/i)).not.toBeInTheDocument();

    await user.click(screen.getByLabelText(/type d'intervention/i));
    await user.click(await screen.findByText("PTG — Prothèse totale de genou"));

    expect(await screen.findByText("Un délégué de Arthrex était-il présent ?")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeDisabled();

    await user.click(screen.getByText("Oui"));
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(onSubmit).toHaveBeenCalledWith({ interventionTypeId: 2, primaryFirmId: 10, representativePresent: true });
  });

  it("changer de type vers une prestation non pertinente fait disparaître la question et efface explicitement l'ancienne réponse", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => {
      if (url.includes("/firms/10/service-offerings")) {
        return Promise.resolve({
          data: [
            { id: 501, interventionType: TYPES[0], active: true, representativePresenceRelevant: true },
            { id: 502, interventionType: TYPES[1], active: true, representativePresenceRelevant: false },
          ],
        });
      }
      return Promise.resolve({ data: [] });
    });
    const { onSubmit } = renderDialog({ intervention: makeIntervention({ representativePresent: true }) });

    await screen.findByText("Un délégué de Arthrex était-il présent ?");

    await user.click(screen.getByLabelText(/type d'intervention/i));
    await user.click(await screen.findByText("PTG — Prothèse totale de genou"));

    expect(screen.queryByText(/délégué/i)).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    // La réponse "Oui" liée à l'ancienne prestation (LCA) ne doit jamais rester
    // orpheline en base une fois la prestation devenue non pertinente (PTG) — clé
    // envoyée explicitement à null, jamais omise.
    expect(onSubmit).toHaveBeenCalledWith({ interventionTypeId: 2, primaryFirmId: 10, representativePresent: null });
  });

  it("changer de firme (Arthrex → Medacta) ne réutilise jamais l'ancienne réponse même si la nouvelle prestation est aussi pertinente", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => {
      if (url.includes("/firms/10/service-offerings")) {
        return Promise.resolve({ data: [{ id: 501, interventionType: TYPES[0], active: true, representativePresenceRelevant: true }] });
      }
      if (url.includes("/firms/11/service-offerings")) {
        return Promise.resolve({ data: [{ id: 502, interventionType: TYPES[0], active: true, representativePresenceRelevant: true }] });
      }
      return Promise.resolve({ data: [] });
    });
    const { onSubmit } = renderDialog({ intervention: makeIntervention({ representativePresent: true }) });

    await screen.findByText("Un délégué de Arthrex était-il présent ?");

    await user.click(screen.getByLabelText(/firme principale/i));
    await user.click(await screen.findByText("Medacta"));

    expect(await screen.findByText("Un délégué de Medacta était-il présent ?")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeDisabled();

    await user.click(screen.getByText("Non"));
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(onSubmit).toHaveBeenCalledWith({ interventionTypeId: 1, primaryFirmId: 11, representativePresent: false });
  });
});
