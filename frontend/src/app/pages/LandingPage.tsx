import * as React from "react";
import { useNavigate } from "react-router-dom";
import Box from "@mui/material/Box";
import Drawer from "@mui/material/Drawer";
import IconButton from "@mui/material/IconButton";
import MenuIcon from "@mui/icons-material/Menu";
import CloseIcon from "@mui/icons-material/Close";
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

const container = { maxWidth: 1140, margin: "0 auto", px: { xs: "20px", sm: "24px" } };
const btnBase = {
  display: "inline-flex",
  alignItems: "center",
  justifyContent: "center",
  gap: "6px",
  minHeight: 44,
  borderRadius: 999,
  fontSize: ".88rem",
  fontWeight: 600,
  textDecoration: "none",
  cursor: "pointer",
  border: "none",
  whiteSpace: "nowrap" as const,
};
const btnOutline = { ...btnBase, padding: "9px 20px", border: `1.5px solid ${C.greenDark}`, background: "transparent", color: C.greenDark };
const btnPrimary = { ...btnBase, padding: "10px 24px", background: C.green, color: "#fff", boxShadow: "0 4px 14px rgba(99,201,163,.4)" };
const btnSecondary = { ...btnBase, padding: "10px 24px", background: C.gray800, color: "#fff" };
const btnLg = { minHeight: 48, padding: "14px 32px", fontSize: "1rem" };
const sectionTag = { display: "inline-block", fontSize: ".72rem", fontWeight: 700, textTransform: "uppercase" as const, letterSpacing: 1, color: C.greenDark, background: C.greenLight, padding: "5px 14px", borderRadius: 999, mb: "14px" };
const sectionTitle = { fontSize: "clamp(1.6rem,4vw,2.4rem)", fontWeight: 800, letterSpacing: -1, lineHeight: 1.2, m: "0 0 14px" };
const sectionSub = { fontSize: "1rem", color: C.gray600, maxWidth: 560, lineHeight: 1.7, m: 0 };
const sectionHead = { textAlign: "center" as const, mb: "48px" };

const NAV_LINKS: [string, string][] = [
  ["#services", "Nos services"],
  ["#comment", "Comment ça marche"],
  ["#profils", "Pour qui"],
  ["#contact", "Contact"],
];

/** Même asset que LoginPage/MobileLayout (`/logo-mark-transparent.png`) — jamais un logo réinventé localement. */
const SurgeryHubLogo = ({ size = 38 }: { size?: number }) => (
  <Box component="img" src="/logo-mark-transparent.png" alt="SurgeryHub" sx={{ width: size, height: size, objectFit: "contain", flexShrink: 0 }} />
);

const HERO_HIGHLIGHTS = [
  { icon: "🩺", bg: C.greenLight, title: "Mise en relation ciblée", desc: "Les offres de mission sont proposées aux instrumentistes selon leur spécialité et leurs disponibilités déclarées." },
  { icon: "🏥", bg: "#EFF6FF", title: "Planning centralisé", desc: "Chaque établissement gère son planning de bloc et ses besoins de couverture depuis une interface dédiée." },
  { icon: "📋", bg: "#FFF7ED", title: "Décomptes automatisés", desc: "Les missions réalisées génèrent un décompte de prestation, sans ressaisie manuelle." },
];

const ESTABLISHMENT_FEATURES = [
  "Publication de besoins de couverture pour vos blocs opératoires",
  "Mise en relation avec des instrumentistes disponibles selon leur spécialité",
  "Suivi du planning et des missions en cours",
  "Décomptes de prestations centralisés",
];

const INSTRUMENTIST_FEATURES = [
  "Consultation des offres de mission correspondant à votre profil et vos disponibilités",
  "Acceptation ou refus des missions proposées",
  "Suivi de vos missions et de vos décomptes depuis l'application",
  "Application mobile installable sur votre téléphone",
];

const ESTABLISHMENT_STEPS = [
  { n: "1", t: "Déposer un besoin", d: "Vous signalez un besoin de couverture : date, bloc, spécialité chirurgicale requise." },
  { n: "2", t: "Sélection des profils disponibles", d: "Les instrumentistes disponibles et qualifiés dans la spécialité concernée sont identifiés." },
  { n: "3", t: "Confirmation & intervention", d: "L'instrumentiste confirme sa disponibilité. Vous accédez à son profil et ses qualifications." },
  { n: "4", t: "Suivi & décompte", d: "Après l'intervention, le suivi et le décompte de la prestation sont générés sur la plateforme." },
];

const INSTRUMENTIST_STEPS = [
  { n: "1", t: "Créer son profil", d: "Renseignez vos spécialités, qualifications et disponibilités sur la plateforme." },
  { n: "2", t: "Recevoir des offres", d: "Des missions correspondant à votre profil et à vos disponibilités vous sont proposées." },
  { n: "3", t: "Accepter & intervenir", d: "Vous acceptez la mission, intervenez dans l'établissement partenaire." },
  { n: "4", t: "Décompte automatique", d: "Votre décompte de mission est généré automatiquement après validation." },
];

const SPECIALTIES = [
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
];

const VALUES = [
  { icon: "🔍", title: "Transparence", desc: "Chaque mission affiche clairement son statut, ses horaires et ses conditions avant acceptation." },
  { icon: "📱", title: "Accessible partout", desc: "L'application est utilisable depuis un navigateur ou installée comme application mobile." },
  { icon: "🔐", title: "Accès par rôle", desc: "Établissements, instrumentistes et chirurgiens disposent chacun d'un espace adapté à leurs besoins." },
];

function useMobileNav() {
  const [open, setOpen] = React.useState(false);
  return { open, onOpen: () => setOpen(true), onClose: () => setOpen(false) };
}

export default function LandingPage() {
  const navigate = useNavigate();
  const { state } = useAuth();
  const mobileNav = useMobileNav();

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
    <Box sx={{ fontFamily: "'Inter', system-ui, sans-serif", color: C.gray800, background: "#fff", lineHeight: 1.6, WebkitFontSmoothing: "antialiased", overflowX: "hidden" }}>

      {/* ── Navbar ─────────────────────────────────────── */}
      <Box component="nav" sx={{ position: "sticky", top: 0, zIndex: 100, background: "rgba(255,255,255,.94)", backdropFilter: "blur(12px)", borderBottom: `1px solid ${C.gray200}` }}>
        <Box sx={container}>
          <Box sx={{ display: "flex", alignItems: "center", justifyContent: "space-between", height: 68 }}>
            <Box component="a" href="#" sx={{ display: "flex", alignItems: "center", gap: "12px", textDecoration: "none", cursor: "pointer" }}>
              <SurgeryHubLogo size={34} />
              <Box sx={{ fontSize: "1.15rem", fontWeight: 800, color: C.greenDark, letterSpacing: -0.5, lineHeight: 1.1 }}>
                <div>SURGERY</div><div>HUB</div>
              </Box>
            </Box>

            {/* Desktop links */}
            <Box component="ul" sx={{ display: { xs: "none", md: "flex" }, gap: "32px", listStyle: "none", m: 0, p: 0 }}>
              {NAV_LINKS.map(([href, label]) => (
                <li key={href}><Box component="a" href={href} sx={{ textDecoration: "none", fontSize: ".9rem", fontWeight: 500, color: C.gray600 }}>{label}</Box></li>
              ))}
            </Box>
            <Box sx={{ display: { xs: "none", md: "flex" }, gap: "12px", alignItems: "center" }}>
              <Box component="button" sx={{ ...btnOutline, fontFamily: "inherit" }} onClick={() => navigate("/login")}>Connexion</Box>
              <Box component="a" href="#contact" sx={btnPrimary}>Nous contacter</Box>
            </Box>

            {/* Mobile burger */}
            <IconButton
              aria-label="Ouvrir le menu"
              onClick={mobileNav.onOpen}
              sx={{ display: { xs: "inline-flex", md: "none" }, color: C.gray800 }}
            >
              <MenuIcon />
            </IconButton>
          </Box>
        </Box>
      </Box>

      <Drawer anchor="right" open={mobileNav.open} onClose={mobileNav.onClose}>
        <Box sx={{ width: "min(78vw, 320px)", p: "24px 20px", display: "flex", flexDirection: "column", gap: "22px", height: "100%" }}>
          <Box sx={{ display: "flex", justifyContent: "flex-end" }}>
            <IconButton aria-label="Fermer le menu" onClick={mobileNav.onClose}><CloseIcon /></IconButton>
          </Box>
          <Box component="ul" sx={{ listStyle: "none", m: 0, p: 0, display: "flex", flexDirection: "column", gap: "6px" }}>
            {NAV_LINKS.map(([href, label]) => (
              <li key={href}>
                <Box
                  component="a"
                  href={href}
                  onClick={mobileNav.onClose}
                  sx={{ display: "block", textDecoration: "none", fontSize: "1rem", fontWeight: 600, color: C.gray800, py: "12px", minHeight: 44 }}
                >
                  {label}
                </Box>
              </li>
            ))}
          </Box>
          <Box sx={{ mt: "auto", display: "flex", flexDirection: "column", gap: "12px" }}>
            <Box component="button" sx={{ ...btnOutline, ...btnLg, width: "100%", fontFamily: "inherit" }} onClick={() => { mobileNav.onClose(); navigate("/login"); }}>Connexion</Box>
            <Box component="a" href="#contact" onClick={mobileNav.onClose} sx={{ ...btnPrimary, ...btnLg, width: "100%" }}>Nous contacter</Box>
          </Box>
        </Box>
      </Drawer>

      {/* ── Hero ───────────────────────────────────────── */}
      <Box component="section" sx={{ background: `linear-gradient(160deg, ${C.offWhite} 0%, ${C.greenLight} 55%, #D0F0E4 100%)`, py: { xs: "56px", md: "100px" }, position: "relative", overflow: "hidden" }}>
        <Box sx={{ ...container, position: "relative", zIndex: 1 }}>
          <Box sx={{ display: "inline-flex", alignItems: "center", gap: "7px", background: "#fff", border: `1px solid ${C.greenMid}`, color: C.greenXDark, fontSize: ".74rem", fontWeight: 700, padding: "5px 14px", borderRadius: 999, mb: "28px", letterSpacing: .5, textTransform: "uppercase" }}>
            <Box component="span" sx={{ width: 6, height: 6, borderRadius: "50%", background: C.green, display: "inline-block" }} />
            Disponible en Belgique
          </Box>

          <Box sx={{ display: "grid", gridTemplateColumns: { xs: "1fr", md: "1fr 1fr" }, gap: { xs: "40px", md: "64px" }, alignItems: "center" }}>
            <Box>
              <Box component="h1" sx={{ fontSize: "clamp(2rem,7vw,3.4rem)", fontWeight: 800, lineHeight: 1.14, letterSpacing: { xs: -1, md: -2 }, color: C.gray800, m: "0 0 20px" }}>
                Des instrumentistes<br />
                <Box component="span" sx={{ color: C.greenDark }}>qualifiés, disponibles,</Box><br />
                quand vous en avez besoin.
              </Box>
              <Box component="p" sx={{ fontSize: "1.05rem", color: C.gray600, maxWidth: 460, mb: "32px", lineHeight: 1.75 }}>
                Surgery Hub met en relation les <strong>hôpitaux et cliniques</strong> avec des <strong>instrumentistes indépendants</strong> qualifiés pour couvrir vos blocs opératoires.
              </Box>

              <Box sx={{ display: "flex", gap: "14px", flexWrap: "wrap", mb: "16px" }}>
                <Box component="a" href="#contact-hopital" sx={{ ...btnPrimary, ...btnLg }}>🏥 Je suis un établissement</Box>
                <Box component="a" href="#contact-instru" sx={{ ...btnSecondary, ...btnLg }}>🩺 Je suis instrumentiste</Box>
              </Box>
              <Box component="p" sx={{ fontSize: ".78rem", color: C.gray400, m: 0 }}>
                Inscription et mise en relation sans engagement
              </Box>
            </Box>

            <Box sx={{ display: "flex", flexDirection: "column", gap: "16px" }}>
              {HERO_HIGHLIGHTS.map((h) => (
                <Box key={h.title} sx={{ background: "#fff", borderRadius: "16px", padding: "20px 22px", boxShadow: "0 8px 32px rgba(0,0,0,.09)", border: `1px solid ${C.gray200}`, display: "flex", alignItems: "flex-start", gap: "16px" }}>
                  <Box sx={{ width: 48, height: 48, borderRadius: "12px", background: h.bg, display: "flex", alignItems: "center", justifyContent: "center", fontSize: "1.35rem", flexShrink: 0 }}>{h.icon}</Box>
                  <Box>
                    <Box sx={{ fontSize: ".95rem", fontWeight: 800, color: C.gray800 }}>{h.title}</Box>
                    <Box sx={{ fontSize: ".8rem", color: C.gray600, mt: "3px", lineHeight: 1.5 }}>{h.desc}</Box>
                  </Box>
                </Box>
              ))}
            </Box>
          </Box>
        </Box>
      </Box>

      {/* ── Services ───────────────────────────────────── */}
      <Box component="section" id="services" sx={{ py: { xs: "56px", md: "88px" } }}>
        <Box sx={container}>
          <Box sx={sectionHead}>
            <Box sx={sectionTag}>Nos services</Box>
            <Box component="h2" sx={sectionTitle}>Ce que Surgery Hub fait pour vous</Box>
          </Box>
          <Box sx={{ display: "grid", gridTemplateColumns: { xs: "1fr", md: "1fr 1fr" }, gap: "28px" }}>
            <Box sx={{ borderRadius: "20px", overflow: "hidden", border: `1px solid ${C.gray200}`, background: "#fff", boxShadow: "0 2px 8px rgba(0,0,0,.06)" }}>
              <Box sx={{ padding: "26px", background: `linear-gradient(135deg, ${C.greenLight}, #C6F0E2)`, display: "flex", gap: "16px", alignItems: "center" }}>
                <Box sx={{ width: 52, height: 52, borderRadius: "12px", background: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontSize: "1.5rem", boxShadow: "0 1px 4px rgba(0,0,0,.1)", flexShrink: 0 }}>🏥</Box>
                <Box>
                  <Box sx={{ fontSize: "1.02rem", fontWeight: 800, color: C.gray800 }}>Pour les établissements</Box>
                  <Box sx={{ fontSize: ".8rem", color: C.gray600, mt: "2px" }}>Hôpitaux, cliniques, centres chirurgicaux</Box>
                </Box>
              </Box>
              <Box sx={{ padding: "24px 26px" }}>
                <Box component="ul" sx={{ listStyle: "none", m: 0, p: 0, display: "flex", flexDirection: "column", gap: "12px" }}>
                  {ESTABLISHMENT_FEATURES.map((f) => (
                    <Box component="li" key={f} sx={{ display: "flex", gap: "10px", fontSize: ".86rem", color: C.gray600, lineHeight: 1.5 }}>
                      <Box component="span" sx={{ width: 18, height: 18, borderRadius: "50%", background: C.greenLight, display: "inline-flex", alignItems: "center", justifyContent: "center", flexShrink: 0, mt: "1px", fontSize: ".7rem", color: C.greenDark, fontWeight: 700 }}>✓</Box>
                      {f}
                    </Box>
                  ))}
                </Box>
                <Box sx={{ mt: "22px" }}>
                  <Box component="a" id="contact-hopital" href="#contact" sx={{ ...btnPrimary, width: "100%" }}>Trouver des instrumentistes</Box>
                </Box>
              </Box>
            </Box>

            <Box sx={{ borderRadius: "20px", overflow: "hidden", border: `1px solid ${C.gray200}`, background: "#fff", boxShadow: "0 2px 8px rgba(0,0,0,.06)" }}>
              <Box sx={{ padding: "26px", background: "linear-gradient(135deg, #EFF6FF, #DBEAFE)", display: "flex", gap: "16px", alignItems: "center" }}>
                <Box sx={{ width: 52, height: 52, borderRadius: "12px", background: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontSize: "1.5rem", boxShadow: "0 1px 4px rgba(0,0,0,.1)", flexShrink: 0 }}>🩺</Box>
                <Box>
                  <Box sx={{ fontSize: "1.02rem", fontWeight: 800, color: C.gray800 }}>Pour les instrumentistes</Box>
                  <Box sx={{ fontSize: ".8rem", color: C.gray600, mt: "2px" }}>Indépendants et freelances du bloc</Box>
                </Box>
              </Box>
              <Box sx={{ padding: "24px 26px" }}>
                <Box component="ul" sx={{ listStyle: "none", m: 0, p: 0, display: "flex", flexDirection: "column", gap: "12px" }}>
                  {INSTRUMENTIST_FEATURES.map((f) => (
                    <Box component="li" key={f} sx={{ display: "flex", gap: "10px", fontSize: ".86rem", color: C.gray600, lineHeight: 1.5 }}>
                      <Box component="span" sx={{ width: 18, height: 18, borderRadius: "50%", background: "#DBEAFE", display: "inline-flex", alignItems: "center", justifyContent: "center", flexShrink: 0, mt: "1px", fontSize: ".7rem", color: "#1D4ED8", fontWeight: 700 }}>✓</Box>
                      {f}
                    </Box>
                  ))}
                </Box>
                <Box sx={{ mt: "22px" }}>
                  <Box component="a" id="contact-instru" href="#contact" sx={{ ...btnSecondary, width: "100%" }}>Rejoindre le réseau</Box>
                </Box>
              </Box>
            </Box>
          </Box>
        </Box>
      </Box>

      {/* ── Comment ça marche ───────────────────────────── */}
      <Box component="section" id="comment" sx={{ py: { xs: "56px", md: "88px" }, background: C.offWhite }}>
        <Box sx={container}>
          <Box sx={sectionHead}>
            <Box sx={sectionTag}>Comment ça marche</Box>
            <Box component="h2" sx={sectionTitle}>Simple, rapide, fiable</Box>
            <Box component="p" sx={{ ...sectionSub, mx: "auto" }}>
              En quelques étapes, Surgery Hub connecte l'établissement qui a besoin de couverture avec l'instrumentiste disponible et qualifié.
            </Box>
          </Box>

          <Box sx={{ display: "grid", gridTemplateColumns: { xs: "1fr", md: "1fr 1fr" }, gap: { xs: "36px", md: "40px" } }}>
            <Box>
              <Box sx={{ display: "inline-flex", alignItems: "center", gap: "8px", background: C.greenLight, borderRadius: 999, padding: "4px 14px", fontSize: ".76rem", fontWeight: 700, color: C.greenXDark, mb: "20px", textTransform: "uppercase", letterSpacing: .5 }}>🏥 Établissement</Box>
              {ESTABLISHMENT_STEPS.map((step) => (
                <Box key={step.n} sx={{ display: "flex", gap: "16px", mb: "20px" }}>
                  <Box sx={{ width: 34, height: 34, borderRadius: "50%", background: C.green, color: "#fff", fontWeight: 800, fontSize: ".9rem", display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0, boxShadow: `0 0 0 4px ${C.greenLight}` }}>{step.n}</Box>
                  <Box>
                    <Box sx={{ fontWeight: 700, fontSize: ".92rem", color: C.gray800, mb: "3px" }}>{step.t}</Box>
                    <Box sx={{ fontSize: ".84rem", color: C.gray600, lineHeight: 1.6 }}>{step.d}</Box>
                  </Box>
                </Box>
              ))}
            </Box>

            <Box>
              <Box sx={{ display: "inline-flex", alignItems: "center", gap: "8px", background: "#DBEAFE", borderRadius: 999, padding: "4px 14px", fontSize: ".76rem", fontWeight: 700, color: "#1E40AF", mb: "20px", textTransform: "uppercase", letterSpacing: .5 }}>🩺 Instrumentiste</Box>
              {INSTRUMENTIST_STEPS.map((step) => (
                <Box key={step.n} sx={{ display: "flex", gap: "16px", mb: "20px" }}>
                  <Box sx={{ width: 34, height: 34, borderRadius: "50%", background: "#3B82F6", color: "#fff", fontWeight: 800, fontSize: ".9rem", display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0, boxShadow: "0 0 0 4px #DBEAFE" }}>{step.n}</Box>
                  <Box>
                    <Box sx={{ fontWeight: 700, fontSize: ".92rem", color: C.gray800, mb: "3px" }}>{step.t}</Box>
                    <Box sx={{ fontSize: ".84rem", color: C.gray600, lineHeight: 1.6 }}>{step.d}</Box>
                  </Box>
                </Box>
              ))}
            </Box>
          </Box>
        </Box>
      </Box>

      {/* ── Spécialités ─────────────────────────────────── */}
      <Box component="section" id="profils" sx={{ py: { xs: "56px", md: "88px" } }}>
        <Box sx={container}>
          <Box sx={sectionHead}>
            <Box sx={sectionTag}>Nos spécialités</Box>
            <Box component="h2" sx={sectionTitle}>Disciplines chirurgicales couvertes</Box>
            <Box component="p" sx={{ ...sectionSub, mx: "auto" }}>Notre réseau couvre plusieurs spécialités pour répondre à vos besoins de couverture de bloc.</Box>
          </Box>
          <Box sx={{ display: "flex", flexWrap: "wrap", gap: "12px", justifyContent: "center" }}>
            {SPECIALTIES.map((sp) => (
              <Box key={sp.label} sx={{ display: "flex", alignItems: "center", gap: "8px", padding: "10px 18px", borderRadius: 999, background: C.greenLight, border: `1px solid ${C.greenMid}`, fontSize: ".86rem", fontWeight: 600, color: C.greenXDark }}>
                <span>{sp.icon}</span>{sp.label}
              </Box>
            ))}
          </Box>
        </Box>
      </Box>

      {/* ── Ce qui nous distingue ───────────────────────── */}
      <Box component="section" sx={{ py: { xs: "56px", md: "88px" }, background: `linear-gradient(135deg, ${C.greenXDark}, ${C.greenDark} 60%, ${C.green})` }}>
        <Box sx={container}>
          <Box sx={{ ...sectionHead, mb: { xs: "32px", md: "48px" } }}>
            <Box sx={{ ...sectionTag, background: "rgba(255,255,255,.15)", color: "#fff" }}>Ce qui nous distingue</Box>
            <Box component="h2" sx={{ ...sectionTitle, color: "#fff" }}>Une plateforme pensée pour le bloc opératoire</Box>
          </Box>
          <Box sx={{ display: "grid", gridTemplateColumns: { xs: "1fr", sm: "repeat(3,1fr)" }, gap: "20px" }}>
            {VALUES.map((v) => (
              <Box key={v.title} sx={{ background: "rgba(255,255,255,.08)", borderRadius: "16px", padding: "26px 22px", textAlign: "center" }}>
                <Box sx={{ fontSize: "1.8rem", mb: "10px" }}>{v.icon}</Box>
                <Box sx={{ fontSize: ".98rem", fontWeight: 800, color: "#fff", mb: "6px" }}>{v.title}</Box>
                <Box sx={{ fontSize: ".82rem", color: "rgba(255,255,255,.75)", lineHeight: 1.6 }}>{v.desc}</Box>
              </Box>
            ))}
          </Box>
        </Box>
      </Box>

      {/* ── CTA / Contact ───────────────────────────────── */}
      <Box component="section" id="contact" sx={{ py: { xs: "64px", md: "96px" }, textAlign: "center", background: `linear-gradient(160deg, ${C.offWhite}, ${C.greenLight})` }}>
        <Box sx={container}>
          <Box sx={sectionTag}>Nous rejoindre</Box>
          <Box component="h2" sx={{ ...sectionTitle, fontSize: "clamp(1.6rem,5vw,2.8rem)" }}>
            Prêt à travailler avec Surgery Hub ?
          </Box>
          <Box component="p" sx={{ ...sectionSub, mx: "auto", mb: "36px", fontSize: "1rem" }}>
            Que vous soyez un établissement qui cherche à couvrir ses blocs ou un instrumentiste indépendant à la recherche de missions — contactez-nous.
          </Box>
          <Box sx={{ display: "flex", gap: "16px", justifyContent: "center", flexWrap: "wrap" }}>
            <Box component="a" href="mailto:etablissements@surgeryhub.be" sx={{ ...btnPrimary, ...btnLg }}>🏥 Contact Établissements</Box>
            <Box component="a" href="mailto:instrumentistes@surgeryhub.be" sx={{ ...btnSecondary, ...btnLg }}>🩺 Contact Instrumentistes</Box>
          </Box>
        </Box>
      </Box>

      {/* ── Footer ─────────────────────────────────────── */}
      <Box component="footer" sx={{ background: C.gray800, color: "rgba(255,255,255,.65)", py: "48px 0 26px", px: 0 }}>
        <Box sx={container}>
          <Box sx={{ display: "grid", gridTemplateColumns: { xs: "1fr", sm: "2fr 1fr 1fr", md: "2fr 1fr 1fr 1fr" }, gap: "32px", mb: "32px" }}>
            <Box>
              <Box component="a" href="#" sx={{ display: "flex", alignItems: "center", gap: "12px", textDecoration: "none" }}>
                <SurgeryHubLogo size={28} />
                <Box sx={{ fontSize: "1.1rem", fontWeight: 800, color: C.green, letterSpacing: -0.5, lineHeight: 1.1 }}><div>SURGERY</div><div>HUB</div></Box>
              </Box>
              <Box component="p" sx={{ fontSize: ".82rem", mt: "12px", lineHeight: 1.65, maxWidth: 260, color: "rgba(255,255,255,.45)" }}>
                Mise en relation entre instrumentistes indépendants et établissements chirurgicaux en Belgique.
              </Box>
            </Box>
            {[
              { title: "Services", links: [["#services", "Pour les établissements"], ["#services", "Pour les instrumentistes"], ["#comment", "Comment ça marche"]] },
              { title: "Spécialités", links: [["#profils", "Orthopédie"], ["#profils", "Neurochirurgie"], ["#profils", "Cardiovasculaire"], ["#profils", "Toutes les spécialités"]] },
              { title: "Contact", links: [["mailto:info@surgeryhub.be", "info@surgeryhub.be"]] },
            ].map((col) => (
              <Box key={col.title}>
                <Box component="span" sx={{ fontSize: ".76rem", fontWeight: 700, textTransform: "uppercase", letterSpacing: .8, color: "rgba(255,255,255,.9)", mb: "14px", display: "block" }}>{col.title}</Box>
                <Box component="ul" sx={{ listStyle: "none", display: "flex", flexDirection: "column", gap: "9px", m: 0, p: 0 }}>
                  {col.links.map(([href, label]) => (
                    <li key={label}><Box component="a" href={href} sx={{ color: "rgba(255,255,255,.45)", textDecoration: "none", fontSize: ".82rem" }}>{label}</Box></li>
                  ))}
                </Box>
              </Box>
            ))}
          </Box>
          <Box sx={{ borderTop: "1px solid rgba(255,255,255,.08)", pt: "22px", display: "flex", justifyContent: "space-between", fontSize: ".75rem", color: "rgba(255,255,255,.3)", flexWrap: "wrap", gap: "6px" }}>
            <span>© 2026 Surgery Hub SRL. Tous droits réservés.</span>
            <span>Belgique</span>
          </Box>
        </Box>
      </Box>

    </Box>
  );
}
