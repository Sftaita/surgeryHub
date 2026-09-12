import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { EncodingStateChip, FinancialStateChip } from "./EncodingStateChip";
import type { EncodingFinancialState, EncodingState } from "../api/encodingTracking.api";
import { ENCODING_STATE_CONFIG, FINANCIAL_STATE_CONFIG } from "../encodingStateMeta";

const ALL_ENCODING_STATES: EncodingState[] = [
  "UPCOMING", "TO_ENCODE", "IN_PROGRESS", "SUBMITTED", "VALIDATED", "LOCKED", "NOT_APPLICABLE",
];
const ALL_FINANCIAL_STATES: EncodingFinancialState[] = [
  "NOT_CALCULABLE", "TO_CALCULATE", "CALCULATED", "DOCUMENTED", "PAID", "ANOMALY",
];

/**
 * Vérifie que les 7 EncodingState et 6 EncodingFinancialState backend (D-118) ont chacun
 * un mapping présentationnel — jamais que le composant redérive une logique.
 */
describe("EncodingStateChip", () => {
  it.each(ALL_ENCODING_STATES)("affiche le libellé français pour %s", (state) => {
    render(<EncodingStateChip state={state} />);
    expect(screen.getByText(ENCODING_STATE_CONFIG[state].label)).toBeInTheDocument();
  });
});

describe("FinancialStateChip", () => {
  it.each(ALL_FINANCIAL_STATES)("affiche le libellé français pour %s", (state) => {
    render(<FinancialStateChip state={state} />);
    expect(screen.getByText(FINANCIAL_STATE_CONFIG[state].label)).toBeInTheDocument();
  });
});
