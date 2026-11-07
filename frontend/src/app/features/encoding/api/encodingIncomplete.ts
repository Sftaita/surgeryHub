/**
 * D-143 / D-146 bis — refus serveur `ENCODING_INCOMPLETE` : message + liste de ce qui
 * manque (heures, firme / réponse délégué / système choisi par intervention, demandes
 * encore en attente), exactement tels que décidés par le backend — rien n'est recalculé ici.
 */
export function encodingIncompleteText(err: unknown): string | null {
  const error = (err as { response?: { data?: { error?: { code?: string; message?: string; missing?: { label?: string }[] } } } })
    ?.response?.data?.error;
  if (error?.code !== "ENCODING_INCOMPLETE") return null;
  const labels = (error.missing ?? []).map((m) => m.label).filter((l): l is string => !!l);
  const message = error.message ?? "L'encodage est incomplet.";
  return labels.length > 0 ? `${message} ${labels.join(" · ")}` : message;
}
