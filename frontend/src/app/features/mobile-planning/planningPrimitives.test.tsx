import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import {
  MonthGrid,
  WeekStrip,
  MissionListRow,
  EmptyStateRow,
  getYmdRange,
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

/**
 * `getYmdRange()` — intégration agenda Lot D (revue 2026-09-07). Contrat : `Y-m-d` local
 * strict des deux côtés (jamais `.toISOString()`, qui décale la date en UTC et a causé le
 * bug réel corrigé dans ce lot — voir `SurgeonPlanningPage.test.tsx` pour la régression au
 * niveau composant), `to` inclusif (dernier jour affiché, contrairement à `getRange()` dont
 * le `to` est exclusif). Ces tests couvrent les frontières de calendrier — mois à 28/30/31
 * jours, changement de mois, semaine chevauchant deux mois, et les deux bascules
 * heure d'été/hiver belges 2026 — pour qu'aucun créneau du premier/dernier jour visible ne
 * disparaisse jamais, y compris quand le fuseau Europe/Brussels change d'offset UTC en
 * cours de fenêtre.
 */
describe("getYmdRange — frontières mois/semaine, jamais un décalage UTC", () => {
  it("mois d'août 2026 (31 jours) — from/to au format Y-m-d strict", () => {
    expect(getYmdRange("month", "2026-08-15")).toEqual({ from: "2026-08-01", to: "2026-08-31" });
  });

  it("mois de septembre 2026 (30 jours) — transition août → septembre", () => {
    expect(getYmdRange("month", "2026-09-07")).toEqual({ from: "2026-09-01", to: "2026-09-30" });
  });

  it("mois d'octobre 2026 (31 jours) — transition septembre → octobre, contient le passage heure d'hiver du 25/10", () => {
    expect(getYmdRange("month", "2026-10-01")).toEqual({ from: "2026-10-01", to: "2026-10-31" });
  });

  it("mois de février 2026 (28 jours, année non bissextile) — jamais un 29 ni un débordement sur mars", () => {
    expect(getYmdRange("month", "2026-02-10")).toEqual({ from: "2026-02-01", to: "2026-02-28" });
  });

  it("mois de mars 2026 — contient le passage heure d'été du 29/03, aucun impact sur les bornes date-only", () => {
    expect(getYmdRange("month", "2026-03-15")).toEqual({ from: "2026-03-01", to: "2026-03-31" });
  });

  it("semaine chevauchant août → septembre 2026 (lundi 31/08 → dimanche 06/09) — aucun des deux mois perdu", () => {
    expect(getYmdRange("week", "2026-09-03")).toEqual({ from: "2026-08-31", to: "2026-09-06" });
  });

  it("semaine contenant le passage heure d'hiver (dimanche 25/10/2026, dernier jour de la semaine) — jamais tronquée", () => {
    expect(getYmdRange("week", "2026-10-21")).toEqual({ from: "2026-10-19", to: "2026-10-25" });
  });

  it("semaine contenant le passage heure d'été (dimanche 29/03/2026, milieu de semaine) — jamais tronquée", () => {
    expect(getYmdRange("week", "2026-03-27")).toEqual({ from: "2026-03-23", to: "2026-03-29" });
  });
});
