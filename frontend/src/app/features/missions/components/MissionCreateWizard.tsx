import * as React from "react";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import timezone from "dayjs/plugin/timezone";
import {
  Alert,
  Box,
  Button,
  Divider,
  Stack,
  Step,
  StepLabel,
  Stepper,
} from "@mui/material";

import type { MissionType, SchedulePrecision } from "../api/missions.types";
import type {
  PublishMissionBody,
  PublishScope,
} from "../api/missions.requests";
import { createMission, createMissionAndPublish } from "../api/missions.api";

import MissionCreateStepContext from "./MissionCreateStepContext";
import MissionCreateStepSchedule from "./MissionCreateStepSchedule";
import MissionCreateSummary from "./MissionCreateSummary";

dayjs.extend(utc);
dayjs.extend(timezone);
dayjs.tz.setDefault("Europe/Brussels");

type Props = {
  sites: Array<{ id: number; name: string }>;
  surgeons: Array<{ id: number; label: string }>;
  onDone: (result: { missionId: number; mode: "DRAFT" | "PUBLISH" }) => void;
  onCancel: () => void;
};

type FormState = {
  step: 0 | 1 | 2;

  siteId?: number;
  surgeonUserId?: number;
  type: MissionType;

  schedulePrecision: SchedulePrecision;
  startLocal: string; // datetime-local "YYYY-MM-DDTHH:mm"
  endLocal: string;

  publishScope: PublishScope; // POOL | TARGETED
  targetUserId?: number;
};

function toApiZonedDate(localValue: string) {
  return dayjs.tz(localValue, "Europe/Brussels").format("YYYY-MM-DDTHH:mm:ssZ");
}

function validateRequired(s: FormState) {
  const errors: string[] = [];
  if (!s.siteId) errors.push("Site requis.");
  if (!s.surgeonUserId) errors.push("Chirurgien requis.");
  if (!s.type) errors.push("Type requis.");
  return errors;
}

function validateSchedule(s: FormState) {
  const errors: string[] = [];
  if (!s.schedulePrecision) errors.push("Précision requise.");
  if (!s.startLocal) errors.push("Heure de début requise.");
  if (!s.endLocal) errors.push("Heure de fin requise.");

  if (s.startLocal) {
    const d = dayjs.tz(s.startLocal, "Europe/Brussels");
    if (!d.isValid()) errors.push("Date de début invalide.");
  }
  if (s.endLocal) {
    const d = dayjs.tz(s.endLocal, "Europe/Brussels");
    if (!d.isValid()) errors.push("Date de fin invalide.");
  }

  if (s.startLocal && s.endLocal) {
    const start = dayjs.tz(s.startLocal, "Europe/Brussels");
    const end = dayjs.tz(s.endLocal, "Europe/Brussels");
    if (start.isValid() && end.isValid() && !end.isAfter(start)) {
      errors.push(
        "La date de fin doit être strictement après la date de début."
      );
    }
  }

  return errors;
}

function validatePublish(s: FormState, mode: "DRAFT" | "PUBLISH") {
  const errors: string[] = [];
  if (mode === "PUBLISH") {
    if (s.publishScope === "TARGETED" && !s.targetUserId) {
      errors.push("Cible requise pour une publication TARGETED.");
    }
  }
  return errors;
}

function backendErrorToString(e: any): string {
  const data = e?.response?.data;
  if (typeof data === "string") return data;
  if (data?.message) return String(data.message);
  if (data?.detail) return String(data.detail);
  if (data?.error?.message) return String(data.error.message);
  if (e?.message) return String(e.message);
  return "Erreur inconnue";
}

export default function MissionCreateWizard(props: Props) {
  const { sites, surgeons, onCancel, onDone } = props;

  const [state, setState] = React.useState<FormState>({
    step: 0,
    siteId: undefined,
    surgeonUserId: undefined,
    type: "BLOCK",
    schedulePrecision: "EXACT",
    startLocal: "",
    endLocal: "",
    publishScope: "POOL",
    targetUserId: undefined,
  });

  const [errors, setErrors] = React.useState<string[]>([]);
  const [backendError, setBackendError] = React.useState<string | null>(null);
  const [submitting, setSubmitting] = React.useState(false);

  const steps = ["Contexte", "Horaire", "Confirmation"];

  function next() {
    setBackendError(null);

    const stepErrors =
      state.step === 0
        ? validateRequired(state)
        : state.step === 1
        ? validateSchedule(state)
        : [];

    setErrors(stepErrors);
    if (stepErrors.length > 0) return;

    setState((s) => ({ ...s, step: (s.step + 1) as 0 | 1 | 2 }));
  }

  function back() {
    setBackendError(null);
    setErrors([]);
    setState((s) => ({ ...s, step: (s.step - 1) as 0 | 1 | 2 }));
  }

  async function submit(mode: "DRAFT" | "PUBLISH") {
    setBackendError(null);

    const all = [
      ...validateRequired(state),
      ...validateSchedule(state),
      ...validatePublish(state, mode),
    ];

    setErrors(all);
    if (all.length > 0) return;

    const body = {
      siteId: Number(state.siteId),
      type: state.type,
      schedulePrecision: state.schedulePrecision,
      startAt: toApiZonedDate(state.startLocal),
      endAt: toApiZonedDate(state.endLocal),
      surgeonUserId: Number(state.surgeonUserId),
    };

    setSubmitting(true);
    try {
      if (mode === "DRAFT") {
        const created = await createMission(body);
        onDone({ missionId: created.id, mode: "DRAFT" });
        return;
      }

      const publishBody: PublishMissionBody =
        state.publishScope === "POOL"
          ? { scope: "POOL" }
          : { scope: "TARGETED", targetUserId: Number(state.targetUserId) };

      const published = await createMissionAndPublish(body, publishBody);
      onDone({ missionId: published.id, mode: "PUBLISH" });
    } catch (e: any) {
      setBackendError(backendErrorToString(e));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Box sx={{ mt: 3 }}>
      <Stepper activeStep={state.step}>
        {steps.map((label) => (
          <Step key={label}>
            <StepLabel>{label}</StepLabel>
          </Step>
        ))}
      </Stepper>

      <Box sx={{ mt: 3 }}>
        {backendError ? (
          <Alert severity="error" sx={{ mb: 2 }}>
            {backendError}
          </Alert>
        ) : null}

        {errors.length > 0 ? (
          <Alert severity="warning" sx={{ mb: 2 }}>
            <Stack spacing={0.5}>
              {errors.map((e) => (
                <div key={e}>{e}</div>
              ))}
            </Stack>
          </Alert>
        ) : null}

        {state.step === 0 ? (
          <MissionCreateStepContext
            sites={sites}
            surgeons={surgeons}
            value={{
              siteId: state.siteId,
              surgeonUserId: state.surgeonUserId,
              type: state.type,
            }}
            onChange={(nextState) => setState((s) => ({ ...s, ...nextState }))}
          />
        ) : null}

        {state.step === 1 ? (
          <MissionCreateStepSchedule
            value={{
              schedulePrecision: state.schedulePrecision,
              startLocal: state.startLocal,
              endLocal: state.endLocal,
            }}
            onChange={(nextState) => setState((s) => ({ ...s, ...nextState }))}
          />
        ) : null}

        {state.step === 2 ? (
          <MissionCreateSummary
            state={state}
            sites={sites}
            surgeons={surgeons}
            onChange={(nextState) => setState((s) => ({ ...s, ...nextState }))}
          />
        ) : null}

        <Divider sx={{ my: 2 }} />

        <Stack direction="row" justifyContent="space-between" gap={2}>
          <Stack direction="row" spacing={1}>
            <Button variant="outlined" onClick={onCancel} disabled={submitting}>
              Annuler
            </Button>

            {state.step > 0 ? (
              <Button variant="outlined" onClick={back} disabled={submitting}>
                Retour
              </Button>
            ) : null}
          </Stack>

          {state.step < 2 ? (
            <Button variant="contained" onClick={next} disabled={submitting}>
              Continuer
            </Button>
          ) : (
            <Stack direction="row" spacing={1}>
              <Button
                variant="outlined"
                onClick={() => submit("DRAFT")}
                disabled={submitting}
              >
                Créer en brouillon
              </Button>
              <Button
                variant="contained"
                onClick={() => submit("PUBLISH")}
                disabled={submitting}
              >
                Créer et publier
              </Button>
            </Stack>
          )}
        </Stack>
      </Box>
    </Box>
  );
}
