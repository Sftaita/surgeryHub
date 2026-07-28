import {
  GREEN_50, GREEN_100, GREEN_300, GREEN_500, GREEN_700, GREEN_800,
  GRAY_200, GRAY_500, GRAY_600, GRAY_800, GRAY_900,
  AMBER_50, AMBER_700, BLUE_100, BLUE_700,
} from "./onboardingTokens";

/**
 * Illustrations portées de handoff-onboarding-react/src/illustrations.tsx et
 * screens3-4-mockups.tsx — SVG/DOM inline, aucun asset externe. Seule la
 * palette a été remappée (variables CSS custom properties du handoff, absentes
 * de SurgicalHub, vers les constantes de onboardingTokens.ts).
 */

/** Écran 1 — trois domaines d'activité (calendrier/horaire/matériel) autour d'un téléphone. */
export function WelcomeIllustration() {
  return (
    <div style={{ position: "relative", width: 220, height: 200 }}>
      <div style={{ position: "absolute", left: "50%", top: 8, transform: "translateX(-50%)", width: 150, height: 150, borderRadius: "50%", background: `radial-gradient(circle, ${GREEN_50} 0%, rgba(239,250,245,0) 72%)` }} />
      <svg width={220} height={200} viewBox="0 0 220 200" fill="none" style={{ position: "relative" }}>
        <rect x="70" y="118" width="80" height="130" rx="18" fill={GRAY_900} />
        <rect x="75" y="126" width="70" height="102" rx="4" fill="#fff" />
        <rect x="86" y="140" width="48" height="8" rx="4" fill={GREEN_100} />
        <rect x="86" y="154" width="34" height="8" rx="4" fill={GREEN_100} />
        <circle cx="110" cy="182" r="16" fill={GREEN_500} />
        <path d="M103 182l5 5 9-10" stroke="#fff" strokeWidth={2.6} strokeLinecap="round" strokeLinejoin="round" fill="none" />
        <g>
          <rect x="10" y="30" width="42" height="42" rx="12" fill={GREEN_100} />
          <rect x="21" y="41" width="20" height="17" rx="2" stroke={GREEN_700} strokeWidth={2.2} />
          <path d="M25 41v-4a3 3 0 0 1 3-3h4a3 3 0 0 1 3 3v4" stroke={GREEN_700} strokeWidth={2.2} fill="none" />
        </g>
        <g>
          <rect x="160" y="18" width="42" height="42" rx="12" fill={BLUE_100} />
          <circle cx="181" cy="39" r="11" stroke={BLUE_700} strokeWidth={2.2} />
          <path d="M181 33v6l4 3" stroke={BLUE_700} strokeWidth={2.2} strokeLinecap="round" fill="none" />
        </g>
        <g>
          <rect x="150" y="130" width="46" height="46" rx="13" fill={AMBER_50} />
          <rect x="163" y="144" width="20" height="18" rx="2.5" stroke={AMBER_700} strokeWidth={2.2} />
          <path d="M163 150h20" stroke={AMBER_700} strokeWidth={2.2} />
        </g>
      </svg>
    </div>
  );
}

/** Écran 2 — téléphone avec la marque SurgicalHub qui "tombe" sur l'écran d'accueil. */
export function InstallIllustration() {
  return (
    <div style={{ position: "relative", width: 200, height: 190, display: "flex", alignItems: "center", justifyContent: "center" }}>
      <svg width={112} height={112} viewBox="0 0 24 24" fill="none" style={{ position: "absolute", left: 6, top: 74, opacity: 0.9 }}>
        <rect x="2" y="2" width="20" height="20" rx="4" fill={GREEN_50} />
        <path d="M12 6a6 6 0 1 0 4.5 10" stroke={GREEN_700} strokeWidth={1.8} strokeLinecap="round" fill="none" />
        <path d="M15 5.5l1.5 1.5-1.5 1.5" stroke={GREEN_700} strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" fill="none" />
      </svg>
      <svg width={44} height={44} viewBox="0 0 24 24" fill="none" style={{ position: "absolute", right: 10, top: 78, opacity: 0.9 }}>
        <rect x="6" y="3" width="12" height="18" rx="2.4" stroke={GRAY_600} strokeWidth={1.8} />
        <circle cx="12" cy="18" r={0.9} fill={GRAY_600} />
      </svg>
      <svg width={118} height={150} viewBox="0 0 118 150" fill="none" style={{ position: "relative" }}>
        <rect x="19" y="14" width="80" height="130" rx="16" fill={GRAY_900} />
        <rect x="24" y="22" width="70" height="106" rx="4" fill="#fff" />
        <g>
          <rect x="41" y="6" width="36" height="36" rx="10" fill={GREEN_500} />
          <path d="M52 17h14M59 10v14" stroke="#fff" strokeWidth={3.4} strokeLinecap="round" />
        </g>
        <rect x="41" y="46" width="36" height="36" rx="10" fill={GREEN_50} stroke={GREEN_300} strokeWidth={1.6} strokeDasharray="3 3" />
        <rect x="34" y="136" width="50" height="9" rx="4.5" fill={GRAY_800} />
      </svg>
    </div>
  );
}

/** Écran 3 — aperçu d'une carte "Mes missions" + une carte "Offres". */
export function MissionCardsPreview() {
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
      <div style={{ background: GREEN_800, borderRadius: 16, padding: "14px 16px", color: "#fff", boxShadow: "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <span style={{ flex: 1, fontSize: 12, color: "rgba(255,255,255,.75)" }}>Mar. 14 juillet</span>
          <span style={{ display: "inline-flex", alignItems: "center", gap: 5, height: 20, padding: "0 8px", borderRadius: 999, background: "rgba(255,255,255,.16)", fontSize: 10.5, fontWeight: 700 }}>À venir</span>
        </div>
        <div style={{ marginTop: 8, fontSize: 15.5, fontWeight: 800 }}>CHU Brugmann</div>
        <div style={{ marginTop: 6, fontSize: 12.5, color: "rgba(255,255,255,.85)" }}>07h30 → 15h30 · Dr. Anouk Peeters</div>
        <div style={{ marginTop: 10, fontSize: 12, fontWeight: 700, opacity: 0.9 }}>Voir la mission →</div>
      </div>
      <div style={{ background: "#fff", border: `1.5px dashed ${GREEN_300}`, borderRadius: 14, padding: "11px 14px", display: "flex", alignItems: "center", gap: 10 }}>
        <span aria-hidden style={{ width: 34, height: 34, borderRadius: 10, background: GREEN_50, display: "flex", alignItems: "center", justifyContent: "center", color: GREEN_700 }}>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
            <path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2c0 .5.2 1 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4Z" />
            <circle cx="7.5" cy="7.5" r=".8" fill="currentColor" />
          </svg>
        </span>
        <div>
          <div style={{ fontSize: 13, fontWeight: 700, color: GRAY_800 }}>Mission disponible</div>
          <div style={{ fontSize: 11.5, color: GRAY_500 }}>Onglet « Offres »</div>
        </div>
      </div>
    </div>
  );
}

/** Écran 4 — Horaires → Interventions et matériel → Encodage terminé, aucun montant. */
export function EncodingProgress() {
  const step = (label: string, done?: boolean) => (
    <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
      <span
        aria-hidden
        style={{
          width: 36, height: 36, borderRadius: 999, display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0,
          background: done ? GREEN_700 : GREEN_100, color: done ? "#fff" : GREEN_800,
        }}
      >
        {done ? "✓" : "•"}
      </span>
      <span style={{ fontSize: 13.5, fontWeight: done ? 700 : 600, color: done ? GRAY_900 : GRAY_800 }}>{label}</span>
    </div>
  );
  const rule = <span aria-hidden style={{ width: 1, height: 16, background: GRAY_200, marginLeft: 17.5 }} />;
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 2 }}>
      {step("Horaires")}
      {rule}
      {step("Interventions et matériel")}
      {rule}
      {step("Encodage terminé", true)}
    </div>
  );
}
