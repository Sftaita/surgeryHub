import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import {
  MonthGrid,
  WeekStrip,
  MissionListRow,
  EmptyStateRow,
  type MonthDayMeta,
} from "./planningPrimitives";

/**
 * Régression instrumentiste (Lot 2, D-095, §15) — ces primitives ont été extraites de
 * pages/instrumentist/PlanningPage.tsx sans changement de comportement (déplacement,
 * pas une réécriture ; verrouillé aussi par PlanningPage.test.tsx qui exerce le rendu
 * complet de la page instrumentiste). Ce fichier teste directement le contrat du module
 * partagé : les valeurs par défaut de MonthGrid (secondaryLabel/secondaryBg/secondaryDot)
 * doivent rester exactement "À encoder"/ambre — c'est ce dont dépend
 * PlanningPage.tsx, qui appelle <MonthGrid ... /> sans jamais passer ces props. Le
 * planning chirurgien (SurgeonPlanningPage.tsx) est le seul à les surcharger
 * ("À couvrir"). Une régression sur les défauts casserait silencieusement
 * l'instrumentiste sans qu'aucun test de SurgeonPlanningPage ne puisse le détecter.
 */

describe("MonthGrid — valeurs par défaut (contrat instrumentiste, ne jamais changer sans mettre à jour PlanningPage.tsx)", () => {
  it("légende par défaut : 'À encoder' (jamais 'À couvrir' sans prop explicite)", () => {
    const dayMeta = new Map<string, MonthDayMeta>([
      ["2026-08-10", { hasConflict: false, hasSecondary: true, hasMission: true }],
    ]);
    render(<MonthGrid date="2026-08-06" todayYmd="2026-08-06" dayMeta={dayMeta} onDayClick={vi.fn()} />);

    expect(screen.getByText("À encoder")).toBeInTheDocument();
    expect(screen.queryByText("À couvrir")).not.toBeInTheDocument();
  });

  it("légende 'Mission' toujours présente (inchangée, les deux rôles la partagent)", () => {
    render(<MonthGrid date="2026-08-06" todayYmd="2026-08-06" dayMeta={new Map()} onDayClick={vi.fn()} />);
    expect(screen.getByText("Mission")).toBeInTheDocument();
  });
});

describe("MonthGrid — généralisation chirurgien (secondaryLabel personnalisé, sans régresser le défaut)", () => {
  it("accepte un secondaryLabel personnalisé ('À couvrir') sans affecter la légende par défaut ailleurs", () => {
    const dayMeta = new Map<string, MonthDayMeta>([
      ["2026-08-10", { hasConflict: false, hasSecondary: true, hasMission: true }],
    ]);
    render(
      <MonthGrid
        date="2026-08-06"
        todayYmd="2026-08-06"
        dayMeta={dayMeta}
        onDayClick={vi.fn()}
        secondaryLabel="À couvrir"
      />,
    );

    expect(screen.getByText("À couvrir")).toBeInTheDocument();
    expect(screen.queryByText("À encoder")).not.toBeInTheDocument();
  });
});

describe("MonthGrid — interaction", () => {
  it("clic sur un jour avec mission déclenche onDayClick avec la bonne clé, jour sans mission désactivé", async () => {
    const onDayClick = vi.fn();
    const dayMeta = new Map<string, MonthDayMeta>([
      ["2026-08-10", { hasConflict: false, hasSecondary: false, hasMission: true }],
    ]);
    const user = userEvent.setup();
    render(<MonthGrid date="2026-08-06" todayYmd="2026-08-06" dayMeta={dayMeta} onDayClick={onDayClick} />);

    await user.click(screen.getByText("10"));
    expect(onDayClick).toHaveBeenCalledWith("2026-08-10");

    const dayWithoutMission = screen.getByText("11").closest("button");
    expect(dayWithoutMission).toBeDisabled();
  });
});

describe("WeekStrip", () => {
  it("affiche 7 jours à partir du lundi, avec un indicateur uniquement sur les jours ayant une mission", () => {
    const hasMissionOn = (key: string) => key === "2026-08-26";
    render(<WeekStrip date="2026-08-27" todayYmd="2026-08-27" hasMissionOn={hasMissionOn} onDayClick={vi.fn()} />);

    expect(screen.getByText("LUN.")).toBeInTheDocument();
    expect(screen.getByText("DIM.")).toBeInTheDocument();
    // Semaine du 24-30 août 2026 : lundi 24 → dimanche 30.
    expect(screen.getByText("24")).toBeInTheDocument();
    expect(screen.getByText("30")).toBeInTheDocument();
  });

  it("clic sur un jour déclenche onDayClick avec la bonne clé YYYY-MM-DD", async () => {
    const onDayClick = vi.fn();
    const user = userEvent.setup();
    render(<WeekStrip date="2026-08-27" todayYmd="2026-08-27" hasMissionOn={() => false} onDayClick={onDayClick} />);

    await user.click(screen.getByText("26"));
    expect(onDayClick).toHaveBeenCalledWith("2026-08-26");
  });
});

describe("MissionListRow — composant de présentation pur (subtitle/statut fournis par l'appelant)", () => {
  const mission = {
    id: 1,
    startAt: "2026-08-26T10:00:00+02:00",
    endAt: "2026-08-26T20:00:00+02:00",
    site: { name: "CHIREC - Hôpital Delta" },
  };

  it("instrumentiste : subtitlePerson = chirurgien", () => {
    render(
      <MissionListRow
        mission={mission}
        subtitlePerson="Dr. Jean Dupont"
        statusInfo={{ variant: "confirmee", label: "Couverte" }}
        dateTileVariant="confirmee"
        onClick={vi.fn()}
      />,
    );
    expect(screen.getByText(/Dr\. Jean Dupont/)).toBeInTheDocument();
    expect(screen.getByText("Couverte")).toBeInTheDocument();
  });

  it("chirurgien : subtitlePerson = instrumentiste (même composant, aucune divergence de comportement)", () => {
    render(
      <MissionListRow
        mission={mission}
        subtitlePerson="Salve Decorte"
        statusInfo={{ variant: "aEncoder", label: "À couvrir" }}
        dateTileVariant="aEncoder"
        onClick={vi.fn()}
      />,
    );
    expect(screen.getByText(/Salve Decorte/)).toBeInTheDocument();
    expect(screen.getByText("À couvrir")).toBeInTheDocument();
  });

  it("clic déclenche onClick", async () => {
    const onClick = vi.fn();
    const user = userEvent.setup();
    render(
      <MissionListRow
        mission={mission}
        subtitlePerson="X"
        statusInfo={{ variant: "confirmee", label: "Couverte" }}
        dateTileVariant="confirmee"
        onClick={onClick}
      />,
    );
    await user.click(screen.getByText("CHIREC - Hôpital Delta"));
    expect(onClick).toHaveBeenCalledTimes(1);
  });
});

describe("EmptyStateRow", () => {
  it("affiche le texte fourni tel quel", () => {
    render(<EmptyStateRow text="Aucune mission sur cette période" />);
    expect(screen.getByText("Aucune mission sur cette période")).toBeInTheDocument();
  });
});
