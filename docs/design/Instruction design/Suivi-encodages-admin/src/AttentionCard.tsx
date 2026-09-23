import React from 'react';

interface Props { total: number; late: number; toRemind: number; toValidate: number; }

/** Bloc vert foncé "À traiter maintenant" — la première chose que l'admin lit. */
export function AttentionCard({ total, late, toRemind, toValidate }: Props) {
  return (
    <section className="sec-attention">
      <span className="sec-attention__eyebrow">À TRAITER MAINTENANT</span>
      <div className="sec-attention__count">
        <span className="sec-attention__num tabular">{total}</span>
        <span className="sec-attention__text">missions demandent une action de votre part</span>
      </div>
      <div className="sec-attention__tiles">
        {[
          { n: late, l: 'en retard', c: 'var(--amber-100)' },
          { n: toRemind, l: 'à relancer', c: 'var(--green-200)' },
          { n: toValidate, l: 'à valider', c: 'var(--blue-100)' },
        ].map((t) => (
          <div className="sec-attention__tile" key={t.l}>
            <span className="sec-attention__tilenum tabular">{t.n}</span>
            <span className="sec-attention__tilelabel" style={{ color: t.c }}>{t.l}</span>
          </div>
        ))}
      </div>
    </section>
  );
}
