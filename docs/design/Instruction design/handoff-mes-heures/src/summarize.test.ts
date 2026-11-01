import { describe, expect, it } from 'vitest';
import { OCTOBER_2026 } from './fixtures';
import { durationMinutes, filterBySite, listSites, showSiteFilter, summarize } from './summarize';
import { duration, endsNextDay, euro, rateRange, timeRange } from './format';

describe('durationMinutes — fin − début encodés', () => {
  it('même journée', () => {
    expect(duration(durationMinutes({ start: '2026-10-29T07:30:00', end: '2026-10-29T16:00:00' }))).toBe('8h30');
  });

  it('après minuit, fin datée du lendemain', () => {
    expect(duration(durationMinutes({ start: '2026-10-29T22:00:00', end: '2026-10-30T03:00:00' }))).toBe('5h00');
  });

  it('après minuit, fin encodée sur la date du début', () => {
    expect(duration(durationMinutes({ start: '2026-10-29T22:00:00', end: '2026-10-29T03:00:00' }))).toBe('5h00');
  });

  it('montant après minuit sur 5h00', () => {
    const night = { ...OCTOBER_2026[0], start: '2026-10-29T22:00:00', end: '2026-10-30T03:00:00' };
    expect(summarize([night], true).estimate!.encodedCents).toBe(21000);
  });
});

describe('summarize — mois entier', () => {
  const s = summarize(filterBySite(OCTOBER_2026, 'all'), true);

  it('encodées = en attente + validées', () => {
    expect(duration(s.encodedMinutes)).toBe('82h30');
    expect(duration(s.validatedMinutes)).toBe('58h15');
    expect(duration(s.pendingMinutes)).toBe('24h15');
    expect(s.encodedMinutes).toBe(s.pendingMinutes + s.validatedMinutes);
  });

  it('pourcentage validé arrondi', () => {
    expect(s.validatedPct).toBe(71);
  });

  it('estimation sur encodées et validées', () => {
    expect(euro(s.estimate!.encodedCents)).toBe('3 482,75 €');
    expect(euro(s.estimate!.validatedCents)).toBe('2 445,50 €');
  });

  it('tarif appliqué par prestation, détail par (site, tarif), somme = total', () => {
    const lines = s.estimate!.lines;
    expect(lines.map((l) => [l.siteName, duration(l.minutes), l.amountCents])).toEqual([
      ['CHU Brugmann', '25h45', 108150],
      ['Hôpital Erasme', '25h00', 105000],
      ['CHIREC Delta', '16h15', 73125],
      ['Clinique Saint-Jean', '15h30', 62000]
    ]);
    expect(lines.reduce((t, l) => t + l.amountCents, 0)).toBe(s.estimate!.encodedCents);
    expect(rateRange(s.estimate!.minRateCents, s.estimate!.maxRateCents)).toBe('40 à 45 €/h');
  });

  it('deux tarifs différents sur un même site : deux lignes', () => {
    const items = OCTOBER_2026.map((p) => (p.missionId === 'm-1291' ? { ...p, rateCents: 4400 } : p));
    const brug = summarize(items, true).estimate!.lines.filter((l) => l.siteName === 'CHU Brugmann');
    expect(brug.map((l) => l.rateCents).sort()).toEqual([4200, 4400]);
  });

  it('sans rémunération horaire : aucune estimation', () => {
    expect(summarize(OCTOBER_2026, false).estimate).toBeNull();
  });

  it('rémunéré mais aucun tarif sur les prestations : aucune estimation', () => {
    expect(summarize(OCTOBER_2026.map((p) => ({ ...p, rateCents: null })), true).estimate).toBeNull();
  });

  it('prestation sans tarif exclue et comptée', () => {
    const items = OCTOBER_2026.map((p, i) => (i === 0 ? { ...p, rateCents: null } : p));
    const e = summarize(items, true).estimate!;
    expect(e.unratedCount).toBe(1);
    expect(e.encodedCents).toBe(348275 - 35700);
  });

  it('mois vide', () => {
    const e = summarize([], true);
    expect(e.count).toBe(0);
    expect(duration(e.encodedMinutes)).toBe('0h00');
    expect(e.validatedPct).toBe(0);
    expect(e.estimate).toBeNull();
    expect(showSiteFilter(listSites([]))).toBe(false);
  });
});

describe('filtre par site — compteurs, montants ET liste', () => {
  const items = filterBySite(OCTOBER_2026, 'brug');
  const s = summarize(items, true);

  it('liste : 3 prestations, plus récente en premier', () => {
    expect(items.map((p) => p.missionId)).toEqual(['m-1291', 'm-1270', 'm-1228']);
  });

  it('compteurs filtrés', () => {
    expect(duration(s.encodedMinutes)).toBe('25h45');
    expect(duration(s.validatedMinutes)).toBe('17h15');
    expect(duration(s.pendingMinutes)).toBe('8h30');
    expect(s.validatedPct).toBe(67);
  });

  it('montants filtrés', () => {
    expect(euro(s.estimate!.encodedCents)).toBe('1 081,50 €');
    expect(euro(s.estimate!.validatedCents)).toBe('724,50 €');
    expect(s.estimate!.lines).toHaveLength(1);
  });

  it('les puces restent celles du mois entier', () => {
    expect(listSites(OCTOBER_2026).map((x) => [x.shortName, x.count])).toEqual([
      ['Brugmann', 3], ['Erasme', 3], ['Delta', 2], ['Saint-Jean', 2]
    ]);
  });

  it('filtre visible à partir de 2 sites', () => {
    expect(showSiteFilter(listSites(OCTOBER_2026))).toBe(true);
    expect(showSiteFilter(listSites(items))).toBe(false);
  });
});

describe('format', () => {
  it('horaire au format belge', () => {
    expect(timeRange('2026-10-29T07:30:00', '2026-10-29T16:00:00')).toBe('07h30 → 16h00');
  });

  it('marqueur +1j après minuit', () => {
    expect(endsNextDay('2026-10-29T22:00:00', '2026-10-30T03:00:00')).toBe(true);
    expect(endsNextDay('2026-10-29T22:00:00', '2026-10-29T03:00:00')).toBe(true);
    expect(endsNextDay('2026-10-29T07:30:00', '2026-10-29T16:00:00')).toBe(false);
  });
});
