import { StatusBadge } from "../../../ui/StatusBadge";
import type { EncodingFinancialState, EncodingState } from "../api/encodingTracking.api";
import { ENCODING_STATE_CONFIG, FINANCIAL_STATE_CONFIG } from "../encodingStateMeta";

/** Mappe un EncodingState déjà résolu par le backend vers un badge — aucune logique ici. */
export function EncodingStateChip({ state }: { state: EncodingState }) {
  return <StatusBadge status={state} config={ENCODING_STATE_CONFIG} />;
}

/** Mappe un EncodingFinancialState déjà résolu par le backend vers un badge. */
export function FinancialStateChip({ state }: { state: EncodingFinancialState }) {
  return <StatusBadge status={state} config={FINANCIAL_STATE_CONFIG} />;
}
