const TZ = 'Europe/Brussels';

const PARTS = new Intl.DateTimeFormat('en-GB', {
  timeZone: TZ, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
});

function parts(iso: string): Record<string, string> {
  return Object.fromEntries(PARTS.formatToParts(new Date(iso)).map((p) => [p.type, p.value]));
}

/** 2026-10-24T08:39:00+02:00 → "24/10" (heure de Bruxelles) */
export function dayMonth(iso: string): string {
  const p = parts(iso);
  return p.day + '/' + p.month;
}

/** 2026-10-23T21:13:00+02:00 → "21h13" (format belge, heure de Bruxelles) */
export function hhmm(iso: string): string {
  const p = parts(iso);
  return p.hour + 'h' + p.minute;
}

/** "Samy Ftaita" → "S.F." ; "Jean-Marc De Smet" → "J.D." (2 premières parties max) */
export function initials(fullName: string): string {
  return fullName
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase() + '.')
    .join('');
}
