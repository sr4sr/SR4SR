/**
 * View "Valore": what the game is worth today. KPI row, valuation band,
 * daily revenue (R$ with the $-net conversion axis), cumulative net revenue,
 * same-weekday comparison and plateau sale scenarios.
 */
import { card, grid, el, table, sparkPair, deltaChip } from '../cards.js';
import { chart } from '../charts.js';
import { kpiRow } from '../kpi.js';
import { theme } from '../chart-option.js';
import { forPeriod, diff, seriesByLabel, firstValues, alignValues, aggregationNote } from '../series.js';
import { fmtUsd, fmtUnit, fmtDelta, fmtPct, fmtDate, fmtInt, fmtDec, fmtMultiple } from '../format.js';

export const title = 'Valore';

export function render(main, state) {
  const d = state.data;
  const k = d.kpis ?? {};
  const a = d.assumptions ?? {};
  const mult = a.multiples ?? {};
  const netFactor = (a.devexUsdPerRobux ?? 0) * (1 - (a.royaltyShare ?? 0));
  const t = theme();

  main.appendChild(kpiRow([
    { label: 'Ricavi 7 giorni', value: k.revenue7dRobux?.value, unit: 'robux', accent: true,
      sub: `${fmtUsd(k.revenue7dUsdNet?.value)} netti`, delta: k.revenue7dRobux?.delta, deltaLabel: k.revenue7dRobux?.deltaLabel,
      how: 'Somma degli ultimi 7 giorni completi; il netto toglie DevEx e royalty.' },
    { label: 'Valutazione stimata (base)', value: k.valuationBaseUsd?.value, unit: 'usd',
      sub: `conservativa ${fmtUsd(k.valuationConservativeUsd?.value)}`,
      how: `Netto mensile × ${fmtMultiple(mult.base)}; la conservativa usa ${fmtMultiple(mult.conservative)}.` },
    { label: 'Run-rate mensile', value: k.monthlyRunRateUsdNet?.value, unit: 'usd', sub: '$ netti',
      how: 'Media degli ultimi 7 giorni proiettata su 30: il mese se il ritmo tiene.' },
    { label: 'Ricavi cumulati', value: k.cumulativeUsdNet?.value, unit: 'usd', sub: '$ netti da inizio storico',
      how: 'Quanto è già entrato al netto dal primo giorno tracciato.' },
    { label: 'DAU 7 giorni', value: k.dau7?.value, unit: 'int', delta: k.dau7?.delta,
      how: 'Utenti attivi medi al giorno nell’ultima settimana.' },
    { label: 'ARPDAU', value: k.arpdau7Robux?.value, unit: 'robux', delta: k.arpdau7Robux?.delta,
      how: 'Robux per utente attivo: la qualità della monetizzazione, non la quantità.' },
  ]));
  main.appendChild(el('p', 'model-note',
    `Modello: 1 R$ = ${fmtDec(a.devexUsdPerRobux ?? 0, 4)} $ al tasso DevEx, meno il ${fmtPct(a.royaltyShare ?? 0, 0)} che va a terzi (royalty e altre quote). `
    + `La valutazione applica al netto mensile un multiplo ${fmtMultiple(mult.conservative)} (conservativa) o ${fmtMultiple(mult.base)} (base).`));

  const g = grid();
  main.appendChild(g);

  const cValue = card({ title: 'Quanto vale il gioco oggi', span: 8,
    says: 'La fascia va dalla stima conservativa a quella base: entrambe seguono il netto mensile, quindi salgono e scendono con i ricavi.',
    terms: ['Multiplo', 'Run-rate', 'DevEx'] });
  g.appendChild(cValue.root);
  chart(cValue, (ctx) => {
    const block = forPeriod(ctx.data.derived?.valuationUsd, ctx.period);
    if (!block) return null;
    const low = seriesByLabel(block, 'conservative')?.values ?? [];
    const high = seriesByLabel(block, 'base')?.values ?? [];
    return { dates: block.dates, unit: 'usd', series: [
      { name: 'Conservativa', values: low, kind: 'line', thin: true, stack: 'band', color: t.accent2 },
      { name: '_band', values: diff(high, low), kind: 'band', stack: 'band', hidden: true, color: t.accent },
      { name: 'Base', values: high, kind: 'line', color: t.accent, endLabel: true },
    ] };
  }, { title: 'Valutazione stimata nel tempo, in dollari' });

  const cScen = card({ title: 'Scenari di vendita', span: 4,
    says: 'Se il gioco si stabilizzasse a una quota del picco storico di DAU, quanto renderebbe e quanto varrebbe al multiplo conservativo.',
    terms: ['Plateau', 'DAU', 'ARPDAU', 'Multiplo'] });
  g.appendChild(cScen.root);
  const scenarios = d.platformValuation ?? [];
  if (scenarios.length) {
    cScen.body.appendChild(table([
      { key: 'share', label: 'Quota del picco', fmt: (r) => fmtPct(r.share, 0) },
      { key: 'dau', label: 'DAU', num: true, fmt: (r) => fmtInt(r.dau) },
      { key: 'monthlyUsdNet', label: 'Mensile netto', num: true, fmt: (r) => fmtUsd(r.monthlyUsdNet) },
      { key: 'valuationUsd', label: 'Valutazione', num: true, fmt: (r) => fmtUsd(r.valuationUsd) },
    ], scenarios));
  } else {
    cScen.body.appendChild(el('p', 'note', 'dato non disponibile'));
  }

  const cRev = card({ title: 'Ricavi giornalieri', span: 12,
    says: 'Barre in Robux, asse destro in dollari netti (stessa grandezza, unità diversa). L’ultimo giorno è tratteggiato perché Roblox lo rivede ancora.',
    terms: ['R$', 'DevEx', 'Netto', 'Provvisorio'] });
  g.appendChild(cRev.root);
  chart(cRev, (ctx) => {
    const rev = forPeriod(ctx.data.metrics?.ItemMonetizationRevenue, ctx.period);
    if (!rev) return null;
    const avg = ctx.data.derived?.revenue7dAvgRobux;
    const series = [{ name: 'Ricavi', values: firstValues(rev), kind: 'bar', color: t.accent,
      extra: (v) => `${fmtUsd(v * netFactor)} netti` }];
    // The 7-day mean stops at dataThrough while revenue includes the provisional day: re-index on the bar dates.
    if (avg) series.push({ name: 'Media 7 giorni', values: alignValues(rev.dates, avg), kind: 'line', color: t.palette[0] });
    return { dates: rev.dates, unit: 'robux', series, provisionalDate: ctx.data.provisionalDate, footnote: aggregationNote(rev),
      secondary: netFactor > 0 ? { unit: 'usd', factor: netFactor } : null };
  }, { title: 'Ricavi giornalieri in Robux con media a 7 giorni', tall: true });

  const cCum = card({ title: 'Ricavi cumulati in $', span: 6,
    says: 'Il netto già incassato, giorno dopo giorno: la pendenza è il ritmo, un tratto piatto è un problema.',
    terms: ['Netto', 'Lordo'] });
  g.appendChild(cCum.root);
  chart(cCum, (ctx) => {
    const block = forPeriod(ctx.data.derived?.revenueCumulativeUsdNet, ctx.period);
    if (!block) return null;
    return { dates: block.dates, unit: 'usd', series: [
      { name: 'Cumulato netto', values: firstValues(block), kind: 'area', color: t.accent, endLabel: true }] };
  }, { title: 'Ricavi cumulati netti in dollari' });

  const cWow = card({ title: 'Confronto a parità di giorno', span: 6,
    says: 'Ogni giorno è confrontato con lo stesso giorno della settimana precedente, così il weekend non inganna.',
    terms: ['WoW', 'DAU', 'ARPDAU'] });
  g.appendChild(cWow.root);
  const wow = d.weekOverWeek ?? [];
  if (wow.length) {
    const max = Math.max(...wow.flatMap((r) => [r.revenue ?? 0, r.revenuePrev ?? 0]));
    cWow.body.appendChild(table([
      { key: 'date', label: 'Giorno', fmt: (r) => fmtDate(r.date) + (r.provisional ? ' · provv.' : '') },
      { key: 'revenue', label: 'Ricavi', num: true, fmt: (r) => fmtUnit(r.revenue, 'robux') },
      { key: 'spark', label: 'vs sett. prec.', fmt: (r) => sparkPair(r.revenue, r.revenuePrev, max) },
      { key: 'delta', label: 'Δ', num: true, fmt: (r) => deltaChip(r.delta, fmtDelta(r.delta)) },
      { key: 'dau', label: 'DAU', num: true, fmt: (r) => fmtInt(r.dau) },
      { key: 'arpdau', label: 'ARPDAU', num: true, fmt: (r) => fmtUnit(r.arpdau, 'robux') },
    ], wow, { caption: 'Confronto settimana su settimana' }));
  } else {
    cWow.body.appendChild(el('p', 'note', 'dato non disponibile'));
  }
}
