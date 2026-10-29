import { useEffect, useRef, useState, type ReactNode, type RefObject, type KeyboardEvent as ReactKeyboardEvent } from 'react';
import type { DrawerStep, Finance, MissionDrawerView } from './types';
import { compareHours, dayLabel, hh, initials, eur, signOf, signed, signFg, groupTotal, balanceOf } from './format';
import { useFollowScroll } from './useFollowScroll';
import { Icon } from './Icon';
import { OperativeActivityBody } from '../components/OperativeActivityCard';
import { resolveApiAssetUrl } from '../../../api/apiAssetUrl';
import './mission-drawer.css';

export type DrawerActionTone = 'amber' | 'ghost' | 'primary' | 'danger';

export interface DrawerFooterAction {
  key: string;
  label: string;
  tone: DrawerActionTone;
  icon?: 'reopen' | 'retry' | 'check';
  disabled?: boolean;
  testId?: string;
  onClick: () => void;
}

/** Correction posée à côté de ce qu'elle modifie (instrumentiste, heures, encodage). */
export interface DrawerInlineAction {
  label: string;
  disabled?: boolean;
  testId?: string;
  onClick: () => void;
}
export interface DrawerCorrections {
  instrumentist?: DrawerInlineAction;
  hours?: DrawerInlineAction;
  encoding?: DrawerInlineAction;
}

function InlineAction({ a }: { a?: DrawerInlineAction }) {
  if (!a) return null;
  return (
    <button type="button" className="mdw-inline-btn" onClick={a.onClick} disabled={a.disabled} data-testid={a.testId}>{a.label}</button>
  );
}

interface Props {
  open: boolean;
  /** Mission affichée — gardée par le conteneur pendant l'animation de sortie. */
  missionId: number | null;
  /** null = en chargement / erreur (voir `placeholder`). */
  view: MissionDrawerView | null;
  placeholder?: string;
  /** StatusPill du design system de l'app (MissionStatusCell). */
  statusPill?: ReactNode;
  /** Avis servis par le backend, posés en tête du corps. */
  notices?: ReactNode;
  /** Sections propres à l'app, sous l'historique (signalements d'anomalie…). */
  extra?: ReactNode;
  footer: { title: string; sub?: ReactNode; actions: DrawerFooterAction[] };
  /** Corrections servies par le backend (reassign, edit_hours, edit_encoding). */
  corrections?: DrawerCorrections;
  onClose: () => void;
}

/* ---------- Étapes (encodage / calcul) ---------- */
function Steps({ steps }: { steps: DrawerStep[] }) {
  return (
    <div className="mdw-steps">
      {steps.map((s) => (
        <div className="mdw-step" key={s.label}>
          <span className="mdw-step__bar" style={{ background: s.done ? 'var(--green-500)' : 'var(--gray-200)' }} />
          <span className="mdw-step__label" style={{ color: s.done ? 'var(--green-800)' : 'var(--gray-400)' }}>{s.label}</span>
          <span className="mdw-step__when tabular">{s.when}</span>
        </div>
      ))}
    </div>
  );
}

/* ---------- Heures : DÉBUT | DURÉE TOTALE | FIN ---------- */
function HoursCard({ m, edit }: { m: MissionDrawerView; edit?: DrawerInlineAction }) {
  if (m.activity) {
    // D-172 — activité sans instrumentiste : état réel de l'encodage, auteur, validation, à la
    // place d'un suivi d'heures d'instrumentiste qui n'existe pas.
    return (
      <section className="mdw-card mdw-card--pad">
        <div className="mdw-card__head"><span className="mdw-eyebrow">ACTIVITÉ OPÉRATOIRE</span></div>
        <OperativeActivityBody
          activity={m.activity}
          financeText={m.financeApplicable ? financeLabel(m) : "disponible après validation de l'encodage"}
        />
      </section>
    );
  }
  if (!m.hours) return null;
  const h = compareHours(m.hours.planStart, m.hours.planEnd, m.hours.real);
  const chip = (v: string, bg: string, fg: string, total?: boolean) => (
    <span className={`mdw-tl__val tabular${total ? ' mdw-tl__val--total' : ''}`} style={{ background: bg, color: fg }}>
      {total && <Icon name="clock" size={16} stroke={2.6} />}{v}
    </span>
  );
  return (
    <section className="mdw-card mdw-card--pad" data-testid="drawer-hours">
      <div className="mdw-card__head">
        <span className="mdw-eyebrow">HEURES</span>
        <span className="mdw-gap" style={{ color: h.labelFg }}>{h.label}</span>
        <span className="mdw-summary">
          {m.hours.openDisputes > 0 ? `${m.hours.openDisputes} contestation${m.hours.openDisputes > 1 ? 's' : ''} ouverte${m.hours.openDisputes > 1 ? 's' : ''} · ` : ''}
          {m.hours.precision}
        </span>
        <InlineAction a={edit} />
      </div>
      <div className="mdw-tl">
        <div className="mdw-tl__row mdw-tl__row--heads" aria-hidden="true">
          <span className="mdw-tl__label" />
          <div className="mdw-tl__heads"><span>DÉBUT</span><span className="mdw-tl__heads-total">DURÉE TOTALE</span><span>FIN</span></div>
        </div>
        <div className="mdw-tl__row">
          <span className="mdw-tl__label">Planifié</span>
          <div className="mdw-tl__track mdw-tl__track--plan">
            {chip(h.pStart, 'var(--gray-150)', 'var(--gray-700)')}
            {chip(h.pTotal, 'var(--gray-150)', 'var(--gray-700)', true)}
            {chip(h.pEnd, 'var(--gray-150)', 'var(--gray-700)')}
          </div>
        </div>
        <div className="mdw-tl__row">
          <span className="mdw-tl__label">Réel</span>
          {h.started ? (
            <div className="mdw-tl__track">
              {chip(h.rStart.v, h.rStart.bg, h.rStart.fg)}
              {chip(h.rTotal.v, h.rTotal.bg, h.rTotal.fg, true)}
              {chip(h.rEnd.v, h.rEnd.bg, h.rEnd.fg)}
            </div>
          ) : <div className="mdw-tl__empty" data-testid="drawer-hours-none">Heures réelles non déclarées</div>}
        </div>
        {h.note && (
          <p className="mdw-tl__note" data-testid="drawer-hours-note">
            {h.note}
            {m.hours.correctionHint && ` ${m.hours.correctionHint}`}
          </p>
        )}
        <div className="mdw-tl__legend">
          <span><i style={{ background: 'var(--green-500)' }} />conforme au planifié</span>
          <span><i style={{ background: 'var(--amber-500)' }} />début plus tôt · fin plus tard · total plus long</span>
          <span><i style={{ background: 'var(--gray-200)' }} />non renseigné ou non comparable</span>
        </div>
      </div>
      {m.hours.disputes.length > 0 && (
        <div className="mdw-disputes" data-testid="drawer-disputes">
          <span className="mdw-sublabel">CONTESTATIONS</span>
          {m.hours.disputes.map((d) => (
            <div className="mdw-dispute" key={d.id}>
              <span className="mdw-dispute__head">
                <span className="mdw-dispute__reason">{d.reason}</span>
                <span className={`mdw-tag${d.open ? ' mdw-tag--warn' : ''}`}>{d.status}</span>
                <span className="mdw-dispute__by tabular">{d.by} · {d.when}</span>
              </span>
              {d.comment && <span className="mdw-dispute__text">{d.comment}</span>}
              {d.resolution && <span className="mdw-dispute__text mdw-dispute__text--muted">Réponse : {d.resolution}</span>}
            </div>
          ))}
        </div>
      )}
    </section>
  );
}

/* ---------- Encodage : accordéon exclusif, vertical ---------- */
function EncodingCard({ m, bodyRef, edit }: { m: MissionDrawerView; bodyRef: RefObject<HTMLDivElement | null>; edit?: DrawerInlineAction }) {
  const [openIdx, setOpenIdx] = useState<number>(0);
  const [follow, setFollow] = useState<number | null>(null);
  const followScroll = useFollowScroll(bodyRef);

  useEffect(() => { setOpenIdx(0); setFollow(null); }, [m.id]);
  // Après le commit : suivre la carte ouverte pendant que l'autre se referme.
  useEffect(() => { if (follow != null) followScroll(`[data-iv="${follow}"]`); }, [follow, followScroll]);

  const ivs = m.interventions;
  const refs = ivs.reduce((a, iv) => a + iv.materials.length, 0);
  const units = ivs.reduce((a, iv) => a + iv.materials.reduce((b, x) => b + x.qty, 0), 0);

  const toggle = (i: number) => {
    const opening = openIdx !== i;
    setOpenIdx(opening ? i : -1);
    setFollow(opening ? i : null);
  };

  return (
    <section className="mdw-card" data-testid="drawer-encoding">
      <div className="mdw-card__head mdw-card__head--bordered">
        <span className="mdw-eyebrow">ENCODAGE</span>
        <InlineAction a={edit} />
        <span className="mdw-summary tabular">
          {ivs.length
            ? `${ivs.length} intervention${ivs.length > 1 ? 's' : ''} · ${refs} référence${refs > 1 ? 's' : ''} · ${units} unité${units > 1 ? 's' : ''}`
            : 'rien d’encodé'}
        </span>
      </div>
      <Steps steps={m.steps} />
      {(m.review.flags.length > 0 || m.review.comments.length > 0) && (
        <div className="mdw-review" data-testid="drawer-encoding-review">
          {m.review.flags.length > 0 && (
            <div>
              <span className="mdw-sublabel">SIGNAUX — n’empêchent pas la validation</span>
              <div className="mdw-tags">
                {m.review.flags.map((f) => <span className="mdw-tag mdw-tag--warn" key={f}>{f}</span>)}
              </div>
            </div>
          )}
          {m.review.comments.length > 0 && (
            <div>
              <span className="mdw-sublabel">COMMENTAIRES MANAGER</span>
              {m.review.comments.map((c) => (
                <div className="mdw-comment" key={c.id}>
                  <span className="mdw-comment__text">{c.text}</span>
                  <span className="mdw-comment__meta tabular">{c.who} · {c.when}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      )}
      <div className="mdw-ivs">
        {ivs.map((iv, i) => {
          const open = openIdx === i;
          return (
            <article className={`mdw-iv${open ? ' is-open' : ''}`} data-iv={i} key={iv.id}>
              <button type="button" className="mdw-iv__head" aria-expanded={open} onClick={() => toggle(i)}>
                <span className="mdw-iv__num tabular">{i + 1}</span>
                <span className="mdw-iv__titles">
                  <span className="mdw-iv__name">{iv.name}</span>
                  <span className="mdw-iv__firm">{iv.meta.join(' · ')}</span>
                </span>
                <span className="mdw-iv__chev"><Icon name="chevronDown" size={16} stroke={2.4} /></span>
              </button>
              <div className="mdw-iv__panel">
                <div className="mdw-iv__inner">
                  {iv.materials.map((mat) => (
                    <div className="mdw-mat" key={mat.id}>
                      <span className="mdw-mat__text">
                        <span className="mdw-mat__name">{mat.name}</span>
                        <span className="mdw-mat__ref">{mat.ref}</span>
                      </span>
                      <span className="mdw-mat__qty tabular">×{mat.qty}</span>
                    </div>
                  ))}
                  {iv.materials.length === 0 && <p className="mdw-iv__empty">Aucun matériel encodé pour cette intervention.</p>}
                </div>
              </div>
            </article>
          );
        })}
        {ivs.length === 0 && (
          <p className="mdw-none"><Icon name="clock" />
            {m.encodingError ? 'Impossible de charger le détail de l’encodage.' : m.encodingEmpty}
          </p>
        )}
      </div>
    </section>
  );
}

/* ---------- Calcul financier ---------- */
const FIN_LABEL = ['Calculé — en attente d’approbation', 'Approuvé — à verrouiller', 'Verrouillé'];

export function financeLabel(m: MissionDrawerView): string {
  if (!m.financeApplicable) return 'Non calculé';
  if (m.finance === undefined) return 'Chargement…';
  if (!m.finance) return 'Calcul à générer';
  if (m.finance.obsolete) return 'Calcul obsolète — à recalculer';
  if (m.finance.tariffStaleReason) return 'Tarif modifié — à recalculer';
  return FIN_LABEL[m.finance.stage - 1];
}

function FinanceCard({ m }: { m: MissionDrawerView }) {
  const f = m.finance;
  return (
    <section className="mdw-card" data-testid="drawer-finance">
      <div className="mdw-card__head mdw-card__head--bordered">
        <span className="mdw-eyebrow">CALCUL FINANCIER</span>
        <span className="mdw-gap" style={{ color: !f ? 'var(--gray-400)' : f.obsolete || f.tariffStaleReason ? 'var(--amber-700)' : f.stage === 3 ? 'var(--green-700)' : 'var(--blue-700)' }}>
          {f ? financeLabel(m) : 'Non calculé'}
        </span>
      </div>
      {!f ? (
        <p className="mdw-fin__none">
          {m.financeApplicable && f === undefined
            ? 'Chargement du calcul…'
            : m.financeApplicable
              ? 'Aucun calcul actif pour cette mission.'
              : 'Le calcul sera disponible après validation de l’encodage.'}
        </p>
      ) : (
        <>
          <Steps steps={[
            { label: 'Calculé', when: `${f.version} · ${f.calculatedAt}`, done: f.stage >= 1 },
            { label: 'Approuvé', when: f.stage >= 2 ? 'fait' : 'à faire', done: f.stage >= 2 },
            { label: 'Verrouillé', when: f.stage >= 3 ? 'fait' : 'à faire', done: f.stage >= 3 },
          ]} />
          {f.obsolete && (
            <p className="mdw-notice mdw-notice--warn" style={{ margin: '12px 18px 0' }} data-testid="calculation-obsolete">
              <span className="mdw-notice__title">Calcul obsolète</span>
              L’encodage a été rouvert après ce calcul : il ne peut être ni approuvé, ni verrouillé, ni facturé. Recalculez-le une fois l’encodage revalidé.
            </p>
          )}
          {f.tariffStaleReason && (
            <p className="mdw-notice mdw-notice--warn" style={{ margin: '12px 18px 0' }} data-testid="calculation-tariff-stale">
              <span className="mdw-notice__title">Calcul à actualiser</span>
              {f.tariffStaleReason}
            </p>
          )}
          <div className="mdw-fin">
            {f.groups.map((g) => {
              const s = signOf(g);
              const fg = signFg(s);
              const currency = g.lines[0]?.currency ?? 'EUR';
              return (
                <div className="mdw-fin__group" key={g.beneficiary + g.who}>
                  <div className="mdw-fin__ghead">
                    <span className="mdw-fin__gtitle">{g.beneficiary === 'firme' ? 'Firme' : 'Instrumentiste'}</span>
                    <span className="mdw-fin__who">{g.who}</span>
                    <span className="mdw-fin__gtotal tabular" style={{ color: fg }}>{signed(groupTotal(g), s, currency)}</span>
                  </div>
                  {g.lines.map((l) => (
                    <div className="mdw-fin__line" key={l.id}>
                      <span className="mdw-fin__desc">
                        <span className="mdw-fin__code">{l.code}</span> {l.description}
                        {l.adjusted && <span className="mdw-fin__adjusted">ajustée</span>}
                      </span>
                      <span className="mdw-fin__qty tabular">{l.qtyLabel} × {eur(l.unitPrice, l.currency)}</span>
                      <span className="mdw-fin__total tabular" style={{ color: fg }}>{signed(l.total, s, l.currency)}</span>
                      {l.detail && (
                        <div className="mdw-fin__detail" data-testid={`drawer-finance-detail-${l.id}`}>
                          {l.detail.adjustment !== 0 && (
                            <span className="tabular">
                              Brut {eur(l.detail.gross, l.currency)} · ajustement {signed(Math.abs(l.detail.adjustment), l.detail.adjustment < 0 ? -1 : 1, l.currency)} · final {eur(l.total, l.currency)}
                              {l.detail.representativePresent !== null && ` · délégué ${l.detail.representativePresent ? 'présent' : 'absent'}`}
                            </span>
                          )}
                          {l.detail.adjustment !== 0 && l.detail.rule && <span>Règle appliquée : {l.detail.rule}</span>}
                          {l.detail.warnings.map((w, i) => <span className="mdw-fin__warning" key={i}><Icon name="info" size={13} />{w}</span>)}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              );
            })}
            {(() => {
              const bal = balanceOf(f.groups);
              const neg = bal < 0;
              return (
                <div className="mdw-fin__balance">
                  <span className="mdw-fin__gtitle">Total</span>
                  <span className="mdw-fin__who">firme − instrumentiste</span>
                  <span className="mdw-fin__badge tabular" data-testid="drawer-finance-balance"
                    style={{ background: neg ? 'var(--red-50)' : 'var(--green-50)', color: neg ? 'var(--red-600)' : 'var(--green-800)' }}>
                    {signed(bal, neg ? -1 : 1, f.groups[0]?.lines[0]?.currency)}
                  </span>
                </div>
              );
            })()}
          </div>
          <p className="mdw-note"><Icon name="info" />Une fois verrouillé, le calcul est utilisable en facturation firme et en décompte instrumentiste.</p>
          <PreviousVersions f={f} missionId={m.id} />
        </>
      )}
    </section>
  );
}

function PreviousVersions({ f, missionId }: { f: Finance; missionId: number }) {
  const [open, setOpen] = useState(false);
  useEffect(() => setOpen(false), [missionId]);
  if (!f.previous.length) return null;
  return (
    <div className="mdw-fin__versions">
      <button type="button" className="mdw-hist__toggle" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
        <span className="mdw-sublabel" style={{ margin: 0 }}>VERSIONS PRÉCÉDENTES</span>
        <span className="mdw-hist__count">{f.previous.length}</span>
        <span className="mdw-hist__action">{open ? 'Masquer' : 'Voir'}</span>
        <span className={`mdw-hist__chev${open ? ' is-open' : ''}`}><Icon name="chevronDown" size={16} stroke={2.4} /></span>
      </button>
      {open && (
        <div className="mdw-hist__list">
          {f.previous.map((v) => (
            <div className="mdw-hist__row" key={v.id}>
              <span className="mdw-hist__when tabular">{v.when}</span>
              <span className="mdw-hist__what">{v.version} — {v.status} <span className="mdw-hist__who">· {v.lines} ligne{v.lines > 1 ? 's' : ''}</span></span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/* ---------- Historique : replié par défaut ---------- */
function HistoryCard({ m }: { m: MissionDrawerView }) {
  const [open, setOpen] = useState(false);
  useEffect(() => setOpen(false), [m.id]);
  if (!m.history.length) return null;
  return (
    <section className="mdw-card">
      <button type="button" className="mdw-hist__toggle" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
        <span className="mdw-eyebrow">HISTORIQUE</span>
        <span className="mdw-hist__count">{m.history.length} événement{m.history.length > 1 ? 's' : ''}</span>
        <span className="mdw-hist__action">{open ? 'Masquer' : 'Voir le détail'}</span>
        <span className={`mdw-hist__chev${open ? ' is-open' : ''}`}><Icon name="chevronDown" size={16} stroke={2.4} /></span>
      </button>
      {open && (
        <div className="mdw-hist__list">
          {m.history.map((ev) => (
            <div className="mdw-hist__row" key={ev.id}>
              <span className="mdw-hist__when tabular">{ev.when}</span>
              <span className="mdw-hist__what">{ev.what} <span className="mdw-hist__who">· {ev.who}</span></span>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}

function Avatar({ name, photoPath, green }: { name: string | null; photoPath?: string | null; green?: boolean }) {
  return (
    <span className={`mdw-avatar${green ? ' mdw-avatar--green' : ''}`} style={photoPath ? { overflow: 'hidden' } : undefined}>
      {photoPath
        ? <img src={resolveApiAssetUrl(photoPath)} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
        : name ? initials(name) : '—'}
    </span>
  );
}

const FOCUSABLE = 'button:not([disabled]), [href], input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

/* ---------- Tiroir ---------- */
export function MissionDrawer({ open, missionId, view: m, placeholder, statusPill, notices, extra, footer, corrections, onClose }: Props) {
  const bodyRef = useRef<HTMLDivElement>(null);
  const asideRef = useRef<HTMLElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);

  const returnFocusRef = useRef<HTMLElement | null>(null);

  useEffect(() => { if (bodyRef.current) bodyRef.current.scrollTop = 0; }, [missionId]);
  // Focus sur « Fermer » à l'ouverture, retour sur l'élément d'origine (la ligne) à la fermeture.
  // L'élément d'origine est lu ici, avant de déplacer le focus : un effet parent passerait après.
  useEffect(() => {
    if (open) {
      if (!returnFocusRef.current) returnFocusRef.current = document.activeElement as HTMLElement | null;
      closeRef.current?.focus({ preventScroll: true });
    } else if (returnFocusRef.current) {
      returnFocusRef.current.focus?.({ preventScroll: true });
      returnFocusRef.current = null;
    }
  }, [open, missionId]);

  // Piège de focus : Tab / Maj+Tab restent dans le tiroir.
  const onKeyDown = (e: ReactKeyboardEvent) => {
    if (e.key !== 'Tab' || !asideRef.current) return;
    const items = Array.from(asideRef.current.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((el) => el.offsetParent !== null);
    if (items.length === 0) return;
    const first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  };

  if (missionId == null) return null;

  return (
    <>
      <div className={`mdw-scrim${open ? ' is-open' : ''}`} onClick={onClose} aria-hidden="true" />
      <aside ref={asideRef} className={`mdw-drawer${open ? ' is-open' : ''}`} aria-label={`Mission #${missionId}`} aria-hidden={!open}
        role="dialog" aria-modal="true" onKeyDown={onKeyDown} data-testid="mission-drawer">
        <header className="mdw-head">
          <div className="mdw-head__titles">
            <div className="mdw-head__titleline">
              <h2 className="mdw-head__title">Mission #{missionId}</h2>
              {statusPill}
            </div>
            {m && <p className="mdw-head__sub">{m.type} · {m.site} · {dayLabel(m.startAt)} · {hh(m.startAt)} → {hh(m.endAt)}</p>}
          </div>
          <button ref={closeRef} type="button" className="mdw-iconbtn" onClick={onClose} aria-label="Fermer"><Icon name="close" size={16} stroke={2.6} /></button>
        </header>

        {m ? (
          <>
            <div className="mdw-people">
              <div className="mdw-person">
                <Avatar name={m.withoutInstrumentist ? 'Sans instrumentiste' : m.instrumentist?.name ?? null} photoPath={m.instrumentist?.photoPath} green />
                <span>
                  <span className="mdw-person__name" style={{ color: m.instrumentist || m.withoutInstrumentist ? undefined : 'var(--gray-400)' }}>
                    {m.withoutInstrumentist ? 'Sans instrumentiste' : m.instrumentist?.name ?? 'Non assignée'}
                  </span>
                  <span className="mdw-person__role">{m.withoutInstrumentist ?? 'Instrumentiste'}</span>
                </span>
                <InlineAction a={corrections?.instrumentist} />
              </div>
              <div className="mdw-person">
                <Avatar name={m.surgeon?.name ?? null} photoPath={m.surgeon?.photoPath} />
                <span><span className="mdw-person__name">{m.surgeon ? `Dr ${m.surgeon.name}` : '—'}</span><span className="mdw-person__role">Chirurgien</span></span>
              </div>
            </div>

            <div className="mdw-body" ref={bodyRef}>
              {notices}
              <HoursCard m={m} edit={corrections?.hours} />
              <EncodingCard m={m} bodyRef={bodyRef} edit={corrections?.encoding} />
              <FinanceCard m={m} />
              <HistoryCard m={m} />
              {extra && <div className="mdw-extra">{extra}</div>}
            </div>
          </>
        ) : (
          <div className="mdw-loading" ref={bodyRef}>{placeholder ?? 'Chargement…'}</div>
        )}

        <footer className="mdw-foot">
          <div className="mdw-foot__text">
            <span className="mdw-foot__title">{footer.title}</span>
            {footer.sub && <span className="mdw-foot__sub">{footer.sub}</span>}
          </div>
          {footer.actions.map((a) => (
            <button key={a.key} type="button" className={`mdw-btn mdw-btn--${a.tone}`} onClick={a.onClick}
              disabled={a.disabled} data-testid={a.testId}>
              {a.icon && <Icon name={a.icon} stroke={a.icon === 'check' ? 2.8 : 2.2} />}{a.label}
            </button>
          ))}
        </footer>
      </aside>
    </>
  );
}
