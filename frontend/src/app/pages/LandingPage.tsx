import * as React from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "../auth/AuthContext";
import { isDesktopRole, isMobileRole } from "../auth/roles";

// ── Brand colours ─────────────────────────────────────────────────────────────
const C = {
  green:      "#63C9A3",
  greenDark:  "#42A882",
  greenXDark: "#2E7A5E",
  greenLight: "#E8F8F2",
  greenMid:   "#A8E4CE",
  offWhite:   "#F7FDFB",
  gray100:    "#F3F4F6",
  gray200:    "#E5E7EB",
  gray400:    "#9CA3AF",
  gray600:    "#4B5563",
  gray800:    "#1F2937",
};

const s: Record<string, React.CSSProperties> = {
  page: { fontFamily: "'Inter', system-ui, sans-serif", color: C.gray800, background: "#fff", lineHeight: 1.6, WebkitFontSmoothing: "antialiased" },
  container: { maxWidth: 1140, margin: "0 auto", padding: "0 24px" },
  nav: { position: "sticky", top: 0, zIndex: 100, background: "rgba(255,255,255,.94)", backdropFilter: "blur(12px)", borderBottom: `1px solid ${C.gray200}` },
  navInner: { display: "flex", alignItems: "center", justifyContent: "space-between", height: 68 },
  logo: { display: "flex", alignItems: "center", gap: 12, textDecoration: "none", cursor: "pointer" },
  logoText: { fontSize: "1.2rem", fontWeight: 800, color: C.greenDark, letterSpacing: -0.5, lineHeight: 1.1 },
  navLinks: { display: "flex", gap: 32, listStyle: "none", margin: 0, padding: 0 },
  navLink: { textDecoration: "none", fontSize: ".9rem", fontWeight: 500, color: C.gray600 },
  navCta: { display: "flex", gap: 12, alignItems: "center" },
  btnOutline: { display: "inline-flex", alignItems: "center", gap: 6, padding: "9px 20px", borderRadius: 999, fontSize: ".88rem", fontWeight: 600, textDecoration: "none", cursor: "pointer", border: `1.5px solid ${C.greenDark}`, background: "transparent", color: C.greenDark },
  btnPrimary: { display: "inline-flex", alignItems: "center", gap: 6, padding: "10px 24px", borderRadius: 999, fontSize: ".88rem", fontWeight: 600, textDecoration: "none", cursor: "pointer", border: "none", background: C.green, color: "#fff", boxShadow: "0 4px 14px rgba(99,201,163,.4)" },
  btnSecondary: { display: "inline-flex", alignItems: "center", gap: 6, padding: "10px 24px", borderRadius: 999, fontSize: ".88rem", fontWeight: 600, textDecoration: "none", cursor: "pointer", border: "none", background: C.gray800, color: "#fff" },
  btnLg: { padding: "14px 32px", fontSize: "1rem" },
  section: { padding: "88px 0" },
  sectionTag: { display: "inline-block", fontSize: ".72rem", fontWeight: 700, textTransform: "uppercase" as const, letterSpacing: 1, color: C.greenDark, background: C.greenLight, padding: "5px 14px", borderRadius: 999, marginBottom: 14 },
  sectionTitle: { fontSize: "clamp(1.7rem,3vw,2.4rem)", fontWeight: 800, letterSpacing: -1, lineHeight: 1.2, margin: "0 0 14px" },
  sectionSub: { fontSize: "1rem", color: C.gray600, maxWidth: 560, lineHeight: 1.7, margin: 0 },
  sectionHead: { textAlign: "center" as const, marginBottom: 56 },
};

const SurgeryHubLogo = ({ size = 38 }: { size?: number }) => (
  <svg width={size} height={size} viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
    <circle cx="40" cy="40" r="37" stroke={C.green} strokeWidth="4" />
    <line x1="27" y1="57" x2="50" y2="25" stroke={C.green} strokeWidth="3.5" strokeLinecap="round" />
    <path d="M46 21 L54 30 Q58 25 53 21 Z" fill={C.green} />
    <line x1="30" y1="55" x2="35" y2="59" stroke={C.green} strokeWidth="2" strokeLinecap="round" />
    <line x1="55" y1="57" x2="32" y2="25" stroke={C.green} strokeWidth="3.5" strokeLinecap="round" />
    <path d="M30 23 Q27 29 31 31 L35 23 Z" fill={C.green} />
    <rect x="50" y="50" width="8" height="3.5" rx="1.75" transform="rotate(-55 54 52)" fill={C.green} />
  </svg>
);

export default function LandingPage() {
  const navigate = useNavigate();
  const { state } = useAuth();

  React.useEffect(() => {
    if (state.status === "authenticated") {
      const role = state.user.role;
      if (isDesktopRole(role)) { navigate("/app/m/missions", { replace: true }); return; }
      if (role === "SURGEON")   { navigate("/app/s", { replace: true }); return; }
      if (isMobileRole(role))   { navigate("/app/i/today", { replace: true }); return; }
    }
  }, [state, navigate]);

  if (state.status === "loading") return null;

  return (
    <div style={s.page}>

      {/* ── Navbar ─────────────────────────────────────── */}
      <nav style={s.nav}>
        <div style={s.container}>
          <div style={s.navInner}>
            <a href="#" style={s.logo}>
              <SurgeryHubLogo size={36} />
              <div style={s.logoText}><div>SURGERY</div><div>HUB</div></div>
            </a>
            <ul style={s.navLinks}>
              {[["#services","Nos services"],["#comment","Comment ça marche"],["#profils","Pour qui"],["#contact","Contact"]].map(([href,label]) => (
                <li key={href}><a href={href} style={s.navLink}>{label}</a></li>
              ))}
            </ul>
            <div style={s.navCta}>
              <button style={s.btnOutline} onClick={() => navigate("/login")}>Connexion</button>
              <a href="#contact" style={s.btnPrimary}>Nous contacter</a>
            </div>
          </div>
        </div>
      </nav>

      {/* ── Hero ───────────────────────────────────────── */}
      <section style={{ background: `linear-gradient(160deg, ${C.offWhite} 0%, ${C.greenLight} 55%, #D0F0E4 100%)`, padding: "100px 0 80px", position: "relative", overflow: "hidden" }}>
        <div style={{ ...s.container, position: "relative", zIndex: 1 }}>
          {/* Badge */}
          <div style={{ display: "inline-flex", alignItems: "center", gap: 7, background: "#fff", border: `1px solid ${C.greenMid}`, color: C.greenXDark, fontSize: ".74rem", fontWeight: 700, padding: "5px 14px", borderRadius: 999, marginBottom: 28, letterSpacing: .5, textTransform: "uppercase" as const }}>
            <span style={{ width: 6, height: 6, borderRadius: "50%", background: C.green, display: "inline-block" }} />
            Disponible partout en Belgique
          </div>

          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 64, alignItems: "center" }}>
            <div>
              <h1 style={{ fontSize: "clamp(2.2rem,4.5vw,3.4rem)", fontWeight: 800, lineHeight: 1.12, letterSpacing: -2, color: C.gray800, margin: "0 0 20px" }}>
                Des instrumentistes<br/>
                <span style={{ color: C.greenDark }}>qualifiés, disponibles,</span><br/>
                quand vous en avez besoin.
              </h1>
              <p style={{ fontSize: "1.1rem", color: C.gray600, maxWidth: 460, marginBottom: 36, lineHeight: 1.75 }}>
                Surgery Hub met en relation les <strong>hôpitaux et cliniques</strong> avec des <strong>instrumentistes indépendants</strong> qualifiés. Couvrez vos blocs opératoires, trouvez des missions — simplement.
              </p>

              {/* Dual CTA */}
              <div style={{ display: "flex", gap: 14, flexWrap: "wrap" as const, marginBottom: 16 }}>
                <a href="#contact-hopital" style={{ ...s.btnPrimary, ...s.btnLg }}>
                  🏥 Je suis un établissement
                </a>
                <a href="#contact-instru" style={{ ...s.btnSecondary, ...s.btnLg }}>
                  🩺 Je suis instrumentiste
                </a>
              </div>
              <p style={{ fontSize: ".78rem", color: C.gray400 }}>
                Inscription et mise en relation sans engagement
              </p>
            </div>

            {/* Visual — chiffres clés */}
            <div style={{ display: "flex", flexDirection: "column" as const, gap: 16 }}>
              {/* Card 1 */}
              <div style={{ background: "#fff", borderRadius: 16, padding: "22px 24px", boxShadow: "0 8px 32px rgba(0,0,0,.09)", border: `1px solid ${C.gray200}`, display: "flex", alignItems: "center", gap: 18 }}>
                <div style={{ width: 52, height: 52, borderRadius: 12, background: C.greenLight, display: "flex", alignItems: "center", justifyContent: "center", fontSize: "1.5rem", flexShrink: 0 }}>🩺</div>
                <div>
                  <div style={{ fontSize: "1.8rem", fontWeight: 800, color: C.greenDark, letterSpacing: -1, lineHeight: 1 }}>180+</div>
                  <div style={{ fontSize: ".82rem", color: C.gray600, marginTop: 3 }}>Instrumentistes indépendants actifs</div>
                </div>
              </div>
              {/* Card 2 */}
              <div style={{ background: "#fff", borderRadius: 16, padding: "22px 24px", boxShadow: "0 8px 32px rgba(0,0,0,.09)", border: `1px solid ${C.gray200}`, display: "flex", alignItems: "center", gap: 18 }}>
                <div style={{ width: 52, height: 52, borderRadius: 12, background: "#EFF6FF", display: "flex", alignItems: "center", justifyContent: "center", fontSize: "1.5rem", flexShrink: 0 }}>🏥</div>
                <div>
                  <div style={{ fontSize: "1.8rem", fontWeight: 800, color: "#2563EB", letterSpacing: -1, lineHeight: 1 }}>45+</div>
                  <div style={{ fontSize: ".82rem", color: C.gray600, marginTop: 3 }}>Cliniques et hôpitaux partenaires</div>
                </div>
              </div>
              {/* Card 3 */}
              <div style={{ background: "#fff", borderRadius: 16, padding: "22px 24px", boxShadow: "0 8px 32px rgba(0,0,0,.09)", border: `1px solid ${C.gray200}`, display: "flex", alignItems: "center", gap: 18 }}>
                <div style={{ width: 52, height: 52, borderRadius: 12, background: "#FFF7ED", display: "flex", alignItems: "center", justifyContent: "center", fontSize: "1.5rem", flexShrink: 0 }}>📋</div>
                <div>
                  <div style={{ fontSize: "1.8rem", fontWeight: 800, color: "#EA580C", letterSpacing: -1, lineHeight: 1 }}>2 400+</div>
                  <div style={{ fontSize: ".82rem", color: C.gray600, marginTop: 3 }}>Missions réalisées en 2025</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ── Services ───────────────────────────────────── */}
      <section id="services" style={s.section}>
        <div style={s.container}>
          <div style={s.sectionHead}>
            <div style={s.sectionTag}>Nos services</div>
            <h2 style={s.sectionTitle}>Ce que Surgery Hub fait pour vous</h2>
          </div>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 32 }}>
            {/* Pour les établissements */}
            <div style={{ borderRadius: 20, overflow: "hidden", border: `1px solid ${C.gray200}`, background: "#fff", boxShadow: "0 2px 8px rgba(0,0,0,.06)" }}>
              <div style={{ padding: 28, background: `linear-gradient(135deg, ${C.greenLight}, #C6F0E2)`, display: "flex", gap: 16, alignItems: "center" }}>
                <div style={{ width: 52, height: 52, borderRadius: 12, background: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontSize: "1.5rem", boxShadow: "0 1px 4px rgba(0,0,0,.1)" }}>🏥</div>
                <div>
                  <div style={{ fontSize: "1.05rem", fontWeight: 800, color: C.gray800 }}>Pour les établissements</div>
                  <div style={{ fontSize: ".8rem", color: C.gray600, marginTop: 2 }}>Hôpitaux, cliniques, centres chirurgicaux</div>
                </div>
              </div>
              <div style={{ padding: "24px 28px" }}>
                <ul style={{ listStyle: "none", margin: 0, padding: 0, display: "flex", flexDirection: "column" as const, gap: 12 }}>
                  {[
                    "Accès immédiat à un vivier d'instrumentistes qualifiés",
                    "Couverture des blocs en cas d'absence ou surcroît d'activité",
                    "Vérification des qualifications et des assurances",
                    "Gestion centralisée des plannings et des missions",
                    "Facturation simplifiée, suivi des interventions",
                    "Support dédié 24/7 pour les urgences de bloc",
                  ].map((f) => (
                    <li key={f} style={{ display: "flex", gap: 10, fontSize: ".86rem", color: C.gray600, lineHeight: 1.5 }}>
                      <span style={{ width: 18, height: 18, borderRadius: "50%", background: C.greenLight, display: "inline-flex", alignItems: "center", justifyContent: "center", flexShrink: 0, marginTop: 1, fontSize: ".7rem", color: C.greenDark, fontWeight: 700 }}>✓</span>
                      {f}
                    </li>
                  ))}
                </ul>
                <div style={{ marginTop: 24 }}>
                  <a id="contact-hopital" href="#contact" style={{ ...s.btnPrimary, width: "100%", justifyContent: "center" }}>Trouver des instrumentistes</a>
                </div>
              </div>
            </div>

            {/* Pour les instrumentistes */}
            <div style={{ borderRadius: 20, overflow: "hidden", border: `1px solid ${C.gray200}`, background: "#fff", boxShadow: "0 2px 8px rgba(0,0,0,.06)" }}>
              <div style={{ padding: 28, background: "linear-gradient(135deg, #EFF6FF, #DBEAFE)", display: "flex", gap: 16, alignItems: "center" }}>
                <div style={{ width: 52, height: 52, borderRadius: 12, background: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontSize: "1.5rem", boxShadow: "0 1px 4px rgba(0,0,0,.1)" }}>🩺</div>
                <div>
                  <div style={{ fontSize: "1.05rem", fontWeight: 800, color: C.gray800 }}>Pour les instrumentistes</div>
                  <div style={{ fontSize: ".8rem", color: C.gray600, marginTop: 2 }}>Indépendants et freelances du bloc</div>
                </div>
              </div>
              <div style={{ padding: "24px 28px" }}>
                <ul style={{ listStyle: "none", margin: 0, padding: 0, display: "flex", flexDirection: "column" as const, gap: 12 }}>
                  {[
                    "Accès à des missions dans des établissements partenaires",
                    "Choisissez vos créneaux selon vos disponibilités",
                    "Travaillez avec des chirurgiens dans votre spécialité",
                    "Décomptes mensuels automatiques, zéro paperasse",
                    "Application mobile pour gérer vos missions partout",
                    "Communauté d'instrumentistes indépendants en Belgique",
                  ].map((f) => (
                    <li key={f} style={{ display: "flex", gap: 10, fontSize: ".86rem", color: C.gray600, lineHeight: 1.5 }}>
                      <span style={{ width: 18, height: 18, borderRadius: "50%", background: "#DBEAFE", display: "inline-flex", alignItems: "center", justifyContent: "center", flexShrink: 0, marginTop: 1, fontSize: ".7rem", color: "#1D4ED8", fontWeight: 700 }}>✓</span>
                      {f}
                    </li>
                  ))}
                </ul>
                <div style={{ marginTop: 24 }}>
                  <a id="contact-instru" href="#contact" style={{ ...s.btnSecondary, width: "100%", justifyContent: "center" }}>Rejoindre le réseau</a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ── Comment ça marche ───────────────────────────── */}
      <section id="comment" style={{ ...s.section, background: C.offWhite }}>
        <div style={s.container}>
          <div style={s.sectionHead}>
            <div style={s.sectionTag}>Comment ça marche</div>
            <h2 style={s.sectionTitle}>Simple, rapide, fiable</h2>
            <p style={{ ...s.sectionSub, margin: "0 auto" }}>
              En quelques étapes, Surgery Hub connecte l'établissement qui a besoin de couverture avec l'instrumentiste disponible et qualifié.
            </p>
          </div>

          <div style={{ display: "grid", gridTemplateColumns: "repeat(2, 1fr)", gap: 40 }}>
            {/* Côté établissement */}
            <div>
              <div style={{ display: "inline-flex", alignItems: "center", gap: 8, background: C.greenLight, borderRadius: 999, padding: "4px 14px", fontSize: ".76rem", fontWeight: 700, color: C.greenXDark, marginBottom: 20, textTransform: "uppercase" as const, letterSpacing: .5 }}>🏥 Établissement</div>
              {[
                { n: "1", t: "Déposer un besoin", d: "Vous signalez un besoin de couverture : date, bloc, spécialité chirurgicale requise." },
                { n: "2", t: "Surgery Hub sélectionne", d: "Notre équipe identifie les instrumentistes disponibles et qualifiés dans votre spécialité." },
                { n: "3", t: "Confirmation & intervention", d: "L'instrumentiste confirme sa disponibilité. Vous recevez son profil et ses qualifications." },
                { n: "4", t: "Suivi & facturation", d: "Après l'intervention, le suivi et la facturation sont gérés directement par Surgery Hub." },
              ].map((step) => (
                <div key={step.n} style={{ display: "flex", gap: 16, marginBottom: 20 }}>
                  <div style={{ width: 34, height: 34, borderRadius: "50%", background: C.green, color: "#fff", fontWeight: 800, fontSize: ".9rem", display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0, boxShadow: `0 0 0 4px ${C.greenLight}` }}>{step.n}</div>
                  <div>
                    <div style={{ fontWeight: 700, fontSize: ".92rem", color: C.gray800, marginBottom: 3 }}>{step.t}</div>
                    <div style={{ fontSize: ".84rem", color: C.gray600, lineHeight: 1.6 }}>{step.d}</div>
                  </div>
                </div>
              ))}
            </div>

            {/* Côté instrumentiste */}
            <div>
              <div style={{ display: "inline-flex", alignItems: "center", gap: 8, background: "#DBEAFE", borderRadius: 999, padding: "4px 14px", fontSize: ".76rem", fontWeight: 700, color: "#1E40AF", marginBottom: 20, textTransform: "uppercase" as const, letterSpacing: .5 }}>🩺 Instrumentiste</div>
              {[
                { n: "1", t: "Créer son profil", d: "Renseignez vos spécialités, qualifications et disponibilités sur la plateforme." },
                { n: "2", t: "Recevoir des offres", d: "Surgery Hub vous propose des missions correspondant à votre profil et à vos disponibilités." },
                { n: "3", t: "Accepter & intervenir", d: "Vous acceptez la mission, intervenez dans l'établissement partenaire." },
                { n: "4", t: "Décompte automatique", d: "Votre décompte mensuel est généré automatiquement. Aucune démarche administrative." },
              ].map((step) => (
                <div key={step.n} style={{ display: "flex", gap: 16, marginBottom: 20 }}>
                  <div style={{ width: 34, height: 34, borderRadius: "50%", background: "#3B82F6", color: "#fff", fontWeight: 800, fontSize: ".9rem", display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0, boxShadow: "0 0 0 4px #DBEAFE" }}>{step.n}</div>
                  <div>
                    <div style={{ fontWeight: 700, fontSize: ".92rem", color: C.gray800, marginBottom: 3 }}>{step.t}</div>
                    <div style={{ fontSize: ".84rem", color: C.gray600, lineHeight: 1.6 }}>{step.d}</div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </section>

      {/* ── Spécialités ─────────────────────────────────── */}
      <section id="profils" style={s.section}>
        <div style={s.container}>
          <div style={s.sectionHead}>
            <div style={s.sectionTag}>Nos spécialités</div>
            <h2 style={s.sectionTitle}>Toutes les disciplines chirurgicales</h2>
            <p style={{ ...s.sectionSub, margin: "0 auto" }}>Notre réseau couvre un large spectre de spécialités pour répondre à tous vos besoins.</p>
          </div>
          <div style={{ display: "flex", flexWrap: "wrap" as const, gap: 12, justifyContent: "center" }}>
            {[
              { icon: "🦵", label: "Genou" },
              { icon: "💪", label: "Épaule" },
              { icon: "🦴", label: "Hanche" },
              { icon: "🏥", label: "Rachis" },
              { icon: "✋", label: "Main / Poignet" },
              { icon: "🦶", label: "Pied / Cheville" },
              { icon: "🧠", label: "Neurochirurgie" },
              { icon: "❤️", label: "Cardiothoracique" },
              { icon: "🫁", label: "Viscéral" },
              { icon: "⚕️", label: "Urologie" },
              { icon: "🌸", label: "Gynécologie" },
              { icon: "👶", label: "Pédiatrique" },
            ].map((sp) => (
              <div key={sp.label} style={{ display: "flex", alignItems: "center", gap: 8, padding: "10px 18px", borderRadius: 999, background: C.greenLight, border: `1px solid ${C.greenMid}`, fontSize: ".86rem", fontWeight: 600, color: C.greenXDark }}>
                <span>{sp.icon}</span>{sp.label}
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── Métriques ───────────────────────────────────── */}
      <section style={{ ...s.section, background: `linear-gradient(135deg, ${C.greenXDark}, ${C.greenDark} 60%, ${C.green})` }}>
        <div style={s.container}>
          <div style={{ ...s.sectionHead, marginBottom: 0 }}>
            <div style={{ ...s.sectionTag, background: "rgba(255,255,255,.15)", color: "#fff" }}>Surgery Hub en chiffres</div>
            <h2 style={{ ...s.sectionTitle, color: "#fff" }}>Un réseau qui grandit chaque jour</h2>
            <p style={{ ...s.sectionSub, margin: "0 auto", color: "rgba(255,255,255,.7)" }}>
              La confiance des établissements belges et des instrumentistes indépendants, construite mission après mission.
            </p>
          </div>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(4,1fr)", gap: 2, background: "rgba(255,255,255,.1)", borderRadius: 16, overflow: "hidden", marginTop: 48 }}>
            {[
              { num: "180",   unit: "+",  lbl: "Instrumentistes dans le réseau" },
              { num: "45",    unit: "+",  lbl: "Établissements partenaires" },
              { num: "2 400", unit: "+",  lbl: "Missions réalisées en 2025" },
              { num: "98",    unit: "%",  lbl: "Taux de satisfaction des partenaires" },
            ].map((m) => (
              <div key={m.lbl} style={{ background: "rgba(255,255,255,.05)", padding: "32px 20px", textAlign: "center" as const }}>
                <div style={{ fontSize: "2.4rem", fontWeight: 800, color: "#fff", lineHeight: 1, letterSpacing: -1 }}>
                  {m.num}<span style={{ fontSize: "1.2rem", color: C.greenMid }}>{m.unit}</span>
                </div>
                <div style={{ fontSize: ".8rem", color: "rgba(255,255,255,.6)", marginTop: 8, fontWeight: 500 }}>{m.lbl}</div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── Témoignages ─────────────────────────────────── */}
      <section style={{ ...s.section, background: C.gray100 }}>
        <div style={s.container}>
          <div style={s.sectionHead}>
            <div style={s.sectionTag}>Témoignages</div>
            <h2 style={s.sectionTitle}>Ils font confiance à Surgery Hub</h2>
          </div>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(3,1fr)", gap: 22 }}>
            {[
              { stars: "★★★★★", text: "\"Grâce à Surgery Hub, nous n'avons plus jamais de bloc sans couverture. En 48h, ils trouvent toujours quelqu'un de qualifié. C'est une vraie bouée de sauvetage pour notre service.\"", initials: "SM", name: "Sophie M.", title: "Directrice des soins — Clinique Saint-Jean", avBg: C.greenDark },
              { stars: "★★★★★", text: "\"J'ai rejoint le réseau il y a 2 ans. Aujourd'hui je travaille dans les meilleurs blocs de Belgique, à mon rythme, dans ma spécialité. La liberté de l'indépendance sans la galère administrative.\"", initials: "TL", name: "Thomas L.", title: "Instrumentiste indépendant — Spécialité genou", avBg: "#3B82F6" },
              { stars: "★★★★★", text: "\"La gestion administrative est fluide, les décomptes arrivent automatiquement. Surgery Hub m'a permis de me concentrer sur ce que j'aime : le bloc opératoire.\"", initials: "AP", name: "Alexia P.", title: "Instrumentiste freelance — Rachis & neurochirurgie", avBg: "#6366F1" },
            ].map((t) => (
              <div key={t.name} style={{ background: "#fff", borderRadius: 16, padding: 26, border: `1px solid ${C.gray200}` }}>
                <div style={{ color: "#F59E0B", marginBottom: 12, letterSpacing: 2 }}>{t.stars}</div>
                <p style={{ fontSize: ".88rem", color: C.gray600, lineHeight: 1.7, marginBottom: 18, fontStyle: "italic" as const }}>{t.text}</p>
                <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                  <div style={{ width: 36, height: 36, borderRadius: "50%", background: t.avBg, display: "flex", alignItems: "center", justifyContent: "center", fontSize: ".8rem", fontWeight: 700, color: "#fff", flexShrink: 0 }}>{t.initials}</div>
                  <div>
                    <div style={{ fontSize: ".86rem", fontWeight: 700, color: C.gray800 }}>{t.name}</div>
                    <div style={{ fontSize: ".72rem", color: C.gray400, marginTop: 1 }}>{t.title}</div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── CTA / Contact ───────────────────────────────── */}
      <section id="contact" style={{ padding: "96px 0", textAlign: "center" as const, background: `linear-gradient(160deg, ${C.offWhite}, ${C.greenLight})` }}>
        <div style={s.container}>
          <div style={s.sectionTag}>Nous rejoindre</div>
          <h2 style={{ ...s.sectionTitle, fontSize: "clamp(1.8rem,3.5vw,2.8rem)" }}>
            Prêt à travailler avec Surgery Hub ?
          </h2>
          <p style={{ ...s.sectionSub, margin: "0 auto 40px", fontSize: "1.05rem" }}>
            Que vous soyez un établissement qui cherche à couvrir ses blocs ou un instrumentiste indépendant à la recherche de missions — contactez-nous, on s'occupe du reste.
          </p>
          <div style={{ display: "flex", gap: 16, justifyContent: "center", flexWrap: "wrap" as const, marginBottom: 48 }}>
            <a href="mailto:etablissements@surgeryhub.be" style={{ ...s.btnPrimary, ...s.btnLg }}>
              🏥 Contact Établissements
            </a>
            <a href="mailto:instrumentistes@surgeryhub.be" style={{ ...s.btnSecondary, ...s.btnLg }}>
              🩺 Contact Instrumentistes
            </a>
          </div>
          <div style={{ display: "inline-flex", alignItems: "center", gap: 8, padding: "10px 20px", background: "#fff", borderRadius: 999, border: `1px solid ${C.gray200}`, fontSize: ".84rem", color: C.gray600 }}>
            <span style={{ fontSize: ".9rem" }}>📞</span>
            Vous préférez téléphoner ?&nbsp;<strong style={{ color: C.greenDark }}>+32 2 000 00 00</strong>
          </div>
        </div>
      </section>

      {/* ── Footer ─────────────────────────────────────── */}
      <footer style={{ background: C.gray800, color: "rgba(255,255,255,.65)", padding: "52px 0 26px" }}>
        <div style={s.container}>
          <div style={{ display: "grid", gridTemplateColumns: "2fr 1fr 1fr 1fr", gap: 36, marginBottom: 36 }}>
            <div>
              <a href="#" style={{ ...s.logo, textDecoration: "none" }}>
                <SurgeryHubLogo size={30} />
                <div style={{ ...s.logoText, color: C.green }}><div>SURGERY</div><div>HUB</div></div>
              </a>
              <p style={{ fontSize: ".82rem", marginTop: 12, lineHeight: 1.65, maxWidth: 230, color: "rgba(255,255,255,.45)" }}>
                Mise en relation entre instrumentistes indépendants et établissements chirurgicaux en Belgique.
              </p>
            </div>
            {[
              { title: "Services", links: [["#services","Pour les établissements"],["#services","Pour les instrumentistes"],["#comment","Comment ça marche"]] },
              { title: "Spécialités", links: [["#profils","Orthopédie"],["#profils","Neurochirurgie"],["#profils","Cardiovasculaire"],["#profils","Toutes les spécialités"]] },
              { title: "Contact", links: [["mailto:info@surgeryhub.be","info@surgeryhub.be"],["#","Support"],["#","Mentions légales"],["#","Politique de confidentialité"]] },
            ].map((col) => (
              <div key={col.title}>
                <span style={{ fontSize: ".76rem", fontWeight: 700, textTransform: "uppercase" as const, letterSpacing: .8, color: "rgba(255,255,255,.9)", marginBottom: 14, display: "block" }}>{col.title}</span>
                <ul style={{ listStyle: "none", display: "flex", flexDirection: "column" as const, gap: 9, margin: 0, padding: 0 }}>
                  {col.links.map(([href, label]) => (
                    <li key={label}><a href={href} style={{ color: "rgba(255,255,255,.45)", textDecoration: "none", fontSize: ".82rem" }}>{label}</a></li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
          <div style={{ borderTop: "1px solid rgba(255,255,255,.08)", paddingTop: 22, display: "flex", justifyContent: "space-between", fontSize: ".75rem", color: "rgba(255,255,255,.3)", flexWrap: "wrap" as const, gap: 6 }}>
            <span>© 2026 Surgery Hub SRL. Tous droits réservés.</span>
            <span>Belgique · Agréé INAMI · TVA BE0XXX.XXX.XXX</span>
          </div>
        </div>
      </footer>

    </div>
  );
}
