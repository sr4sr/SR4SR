# Metrics and the economic model

## Sources

All figures come from the Roblox Open Cloud **Analytics Query API**
(`analytics-query-api/v1/universes/{universeId}/metrics`), daily granularity,
UTC days. The catalog in `config/metrics.json` lists 111 metrics in 16
categories with Italian names, units, and a plain-language description each;
`config/dimensions.json` lists the metric × dimension pairs fetched for
breakdowns (platform, country, age group, new vs returning…).

Two facts about the source shape the design:

1. **Retention is short.** Most metrics are available for 28 days only, so
   `History` folds every fetch into a long-lived file and never deletes a day.
2. **The latest day is provisional.** Roblox keeps revising the most recent
   revenue day for about 24 hours, always upward (+4.5 % … +7 % observed). It
   is excluded from every mean and shown dimmed.

## Aggregates vs breakdowns

Summing a per-platform breakdown of *unique users* overstates the true total
(a player on phone and PC is counted twice; ~0.4 % on DAU here). Aggregate
series are therefore fetched **without** breakdown, and platform splits are
fetched separately as dimension pairs. Metrics whose breakdown *is* the
metric (request status, memory by component, thumbnail variants…) keep it.
Legacy history imported from the old per-platform cache is summed and
flagged `aggregatedFromBreakdown` until fresh fetches replace those days.

## Funnels: one series per funnel

`FunnelName` is filter-only: the API rejects it as a breakdown, and since
8 Oct 2026 it rejects a funnel metric without it ("Dimension FunnelName is
required as a filter"). The refresh lists the funnels once per run
(`dimension-values`), fetches each funnel metric once per funnel with a
filter, and stores one series per funnel, labelled with its name ("Partita",
or "Partita · 2" over a step breakdown). There is no total: funnels measure
different things, so the cross-funnel sum Roblox served until 7 Oct is not
shown either. A game that logs no funnel gets an empty row, not an error.

## Derived series

| Series | Formula |
|---|---|
| `revenueUsdGross` | Robux × DevEx |
| `revenueUsdNet` | Robux × DevEx × (1 − royalty) |
| `revenue7dAvgRobux` | trailing 7-day mean (null until the window is complete) |
| `revenueCumulativeUsdNet` | running sum of net USD over the whole history |
| `valuationUsd` | 7-day mean net USD × 30 × multiple, for the conservative and base multiples |
| `arpdauRobux` | ItemMonetizationRevenue ÷ DailyActiveUsers |
| `arppuRobux` | ItemMonetizationRevenue ÷ PayingUsers |
| `dauMauStickiness` | DailyActiveUsers ÷ MonthlyActiveUsers |
| `revenuePlatformShare` | DailyRevenue by platform ÷ its daily total |

KPI deltas compare the last complete 7-day window with the 7 days before it.

## In-session survival

`TotalSessionsEndedInBucket` is fetched with its `SessionTimeBucket`
breakdown, whose labels are seconds ("0", "30", ... "1800"). The value at
bucket *B* counts the sessions still alive at *B* seconds, so the series is
already monotonically decreasing and

    survival(B) = value(B) ÷ value(0)

is the share of a day's sessions still in play after *B* seconds. The
dashboard publishes it as `sessionSurvival`: the curve of the last seven
complete days, the curve of the seven before, four thresholds (1, 5, 10 and
30 minutes) followed day by day, and the interpolated bucket where the
current curve crosses one half — the median session length.

A window pools the days it covers (the sum of bucket *B* over the window
divided by the sum of its 0-second bucket) instead of averaging the daily
ratios, so a quiet Tuesday does not weigh as much as a busy Sunday. Days
without a usable 0-second bucket are dropped, and a window with fewer than
three usable days is published as `null` rather than as a curve drawn on
one day.

The first two minutes are the interesting part: that is where a bad first
impression shows up, long before it reaches D1 retention.

## Seasonality and comparisons

This audience roughly doubles at weekends. Three consequences:

- run-rates use the **7-day mean**, which is neutral to the weekday, rather
  than the median, which would drop the weekend and understate a week by
  about a third;
- trend is read on **same-weekday** deltas (`weekOverWeek`), never day-over-day;
- a **weekday index** (`seasonality`) is published: mean of each weekday over
  the last complete weeks divided by the overall mean, so 1.4 on Saturday
  means "Saturdays are 40 % above an average day".

## Anomaly detection

For each watched metric the last complete day is compared with its baseline:
the mean of the same weekday over the previous four weeks (or a 14-day median
when history is short). Residuals are scaled by a robust spread, `1.4826 ×
MAD` (median absolute deviation), so a single past spike does not hide a new
one. `|z| ≥ 3` is *medium*, `|z| ≥ 4.5` is *high*. Direction matters: a drop
in FPS or retention is bad, a drop in crashes is not flagged.

## Valuation

Two readings are shown on purpose:

- **Base (30 ×)** assumes the current level holds. It is the theoretical
  ceiling and answers "how far are we from a target".
- **Conservative (18 ×)** is the discounted reading for a game whose audience
  has settled; the plateau table applies it at 6 / 10 / 15 % of the historical
  DAU peak with today's ARPDAU.

Both are estimates for steering decisions, not appraisals.

## Glossary

The full glossary shown in the UI lives in `config/glossary.json`. The most
frequent terms:

| Term | Meaning |
|---|---|
| DAU / MAU | Daily / monthly active users (unique players). |
| Stickiness | DAU ÷ MAU: how often a monthly player shows up in a day. |
| D1 / D7 / D30 | Share of new players who return 1 / 7 / 30 days after their first session. |
| ARPDAU | Average revenue per daily active user (Robux per player per day). |
| ARPPU | Average revenue per paying user. |
| CVR | Conversion rate (impression → click, click → play, or player → payer). |
| PTR / RFY | Play-through rate of the "Recommended For You" placement. |
| PCCU | Peak concurrent users in the day. |
| R$ | Robux. DevEx: the Developer Exchange programme that converts them to USD. |
| WoW | Week over week, same weekday. |
| MAD, z-score | Robust spread estimate and the number of spreads a value sits from its baseline. |
| LRO | Long-running operation (an API response completed by polling). |
