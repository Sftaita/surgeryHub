import React from 'react';
import { MissionRow } from './types';
import { STATE_TONE, GAP_FG, GAP_BAR, GAP_INK, initials } from './tokens';
import { Icon } from './Icon';

interface Props { mission: MissionRow; onClose: () => void; onRelance: () => void; onValidate: () => void; }

const CTA = {
  on:   { label: 'Valider l’encodage', bg: 'var(--green-600)', fg: '#fff' },
  off:  { label: 'Valider l’encodage', bg: 'var(--gray-150)', fg: 'var(--gray-400)' },
  done: { label: 'Encodage validé',    bg: 'var(--green-100)', fg: 'var(--green-800)' },
};

/**
 * Tiroir de détail (560px). Desktop : la colonne de contenu se rétrécit, la liste reste visible.
 * Mobile : le même composant est rendu en feuille plein écran (classe .sec-drawer--sheet).
 */
export function MissionDrawer({ mission: m, onClose, onRelance, onValidate }: Props) {
  const tone = STATE_TONE[m.state];
  const cta = CTA[m.cta];
  const refs = m.interventions.reduce((a, iv) => a + iv.materials.length, 0);
  const units = m.interventions.reduce((a, iv) => a + iv.materials.reduce((b, x) => b + x.qty, 0), 0);

  return (
    <aside className="sec-drawer" aria-label={`Mission ${m.id}`}>
      <header className="sec-drawer__head">
        <div className="sec-drawer__titles">
          <div className="sec-drawer__titleline">
            <h2 className="sec-drawer__title">Mission #{m.id}</h2>
            <span className="sec-pill" style={{ background: tone.bg, color: tone.fg }}>{m.state}</span>
            {m.late && (
              <span className="sec-pill sec-pill--late">
                <Icon name="clock" size={12} stroke={2.6} />{m.gapTone === 'bad' ? m.gap : 'en retard'}
              </span>
            )}
          </div>
          <p className="sec-drawer__sub">{m.site} · {m.date} {m.planRange}</p>
        </div>
        <button type="button" className="sec-iconbtn" onClick={onClose} aria-label="Fermer"><Icon name="close" size={16} stroke={2.6} /></button>
      </header>

      <div className="sec-drawer__people">
        {[{ n: m.nurse, r: 'Instrumentiste', green: true }, { n: m.surgeon, r: 'Chirurgien', green: false }].map((p) => (
          <div className="sec-person" key={p.r}>
            <span className={`sec-avatar${p.green ? ' sec-avatar--green' : ''}`}>{initials(p.n)}</span>
            <span className="sec-person__text">
              <span className="sec-person__name">{p.n}</span>
              <span className="sec-person__role">{p.r}</span>
            </span>
          </div>
        ))}
      </div>

      <div className="sec-drawer__body">
        <section className="sec-card">
          <div className="sec-card__head">
            <span className="sec-eyebrow">HEURES</span>
            <span className="sec-gap tabular" style={{ color: GAP_FG[m.gapTone] }}>{m.gap}</span>
          </div>
          <div className="sec-bars">
            <div className="sec-bar">
              <span className="sec-bar__label">Planifié</span>
              <div className="sec-bar__track sec-bar__track--plan">
                <span className="sec-bar__text tabular">{m.planRange}</span>
              </div>
              <span className="sec-bar__total tabular">{m.planTotal}</span>
            </div>
            <div className="sec-bar">
              <span className="sec-bar__label">Réel</span>
              <div className="sec-bar__track">
                <div className="sec-bar__fill" style={{ width: m.realPct, background: GAP_BAR[m.gapTone] }} />
                <span className="sec-bar__text tabular" style={{ color: GAP_INK[m.gapTone] }}>{m.realRange}</span>
              </div>
              <span className="sec-bar__total tabular">{m.realTotal}</span>
            </div>
          </div>
          <p className="sec-note"><Icon name="info" size={14} stroke={2.2} />{m.hoursNote}</p>
        </section>

        <section className="sec-card sec-steps">
          {m.steps.map((s) => (
            <div className="sec-step" key={s.label}>
              <span className="sec-step__bar" style={{ background: s.done ? 'var(--green-500)' : 'var(--gray-200)' }} />
              <span className="sec-step__label" style={{ color: s.done ? 'var(--green-800)' : 'var(--gray-400)' }}>{s.label}</span>
              <span className="sec-step__when tabular">{s.when}</span>
            </div>
          ))}
        </section>

        <section className="sec-card sec-ivs">
          <div className="sec-card__head sec-card__head--bordered">
            <span className="sec-eyebrow">INTERVENTIONS & MATÉRIEL</span>
            <span className="sec-summary tabular">
              {m.interventions.length === 0 ? 'rien d’encodé' : `${m.interventions.length} interventions · ${refs} références · ${units} unités`}
            </span>
          </div>
          <div className="sec-ivs__list">
            {m.interventions.map((iv, i) => (
              <article className="sec-iv" key={iv.id}>
                <header className="sec-iv__head">
                  <span className="sec-iv__num tabular">{i + 1}</span>
                  <span className="sec-iv__titles">
                    <span className="sec-iv__name">{iv.name}</span>
                    <span className="sec-iv__firm">{iv.firm}</span>
                  </span>
                  {iv.rep && (
                    <span className="sec-pill sec-pill--rep" title="Délégué présent">
                      <Icon name="rep" size={13} stroke={2.4} />Délégué présent
                    </span>
                  )}
                </header>
                {iv.materials.map((mat) => (
                  <div className="sec-mat" key={mat.id}>
                    <span className="sec-mat__text">
                      <span className="sec-mat__name">{mat.name}</span>
                      <span className="sec-mat__ref">{mat.ref}</span>
                    </span>
                    <span className="sec-mat__qty tabular">×{mat.qty}</span>
                  </div>
                ))}
              </article>
            ))}
            {m.interventions.length === 0 && (
              <p className="sec-empty"><Icon name="alert" size={17} stroke={2.2} />Aucun matériel encodé — l’instrumentiste n’a pas encore ouvert son encodage.</p>
            )}
          </div>
        </section>
      </div>

      <footer className="sec-drawer__foot">
        <div className="sec-drawer__foottext">
          <span className="sec-foot__title">{m.footTitle}</span>
          <span className="sec-foot__sub">{m.footSub}</span>
        </div>
        <button type="button" className="sec-btn sec-btn--ghost" onClick={onRelance}><Icon name="retry" size={15} stroke={2.2} />Relancer</button>
        <button type="button" className="sec-btn sec-btn--cta" onClick={onValidate} disabled={m.cta !== 'on'}
          style={{ background: cta.bg, color: cta.fg }}>
          <Icon name="check" size={16} stroke={2.8} />{cta.label}
        </button>
      </footer>
    </aside>
  );
}
