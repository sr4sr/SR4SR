# Manor Ledger

[![CI](https://github.com/sr4sr/SR4SR/actions/workflows/ci.yml/badge.svg)](https://github.com/sr4sr/SR4SR/actions/workflows/ci.yml)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4)
![License: MIT](https://img.shields.io/badge/license-MIT-green)

Analytics pipeline and dashboard for the Roblox experience **The Locust's Manor**.
It ingests the Roblox Open Cloud Analytics API once a day, keeps an
incremental history that outlives Roblox's 28-day retention, and serves a
curated, dark-theme dashboard focused on one question first: *how much
economic value is the game producing, and what is that stream worth?*

The second half of the dashboard is diagnostic: growth, monetisation and
technical health, with anomaly detection to surface what needs attention.

> Zero runtime dependencies (PHP 8.2+ with curl/json/mbstring/zlib), no
> framework, no build step. Apache ECharts is vendored for the charts.
> Everything else in this repository is original code.

## Contents

- [Where to look first](#where-to-look-first)
- [What it shows](#what-it-shows)
- [How it works](#how-it-works)
- [Economic model](#economic-model)
- [Repository layout](#repository-layout)
- [Run it locally](#run-it-locally)
- [Deploy](#deploy)
- [Security](#security)
- [Data policy](#data-policy)
- [Development](#development)

## Where to look first

Seven files that carry most of the reasoning, in the order they are probably
worth reading:

- [`docs/DESIGN-DECISIONS.md`](docs/DESIGN-DECISIONS.md) — twelve decisions,
  each with the alternative that was rejected and the measurement or failure
  that settled it.
- [`src/ManorLedger/Auth/Authenticator.php`](src/ManorLedger/Auth/Authenticator.php)
  — the whole login sequence in one file: throttle, lookup, verification that
  hashes even for an unknown user, optional TOTP as a second request, session,
  audit line.
- [`src/ManorLedger/Roblox/RateBudget.php`](src/ManorLedger/Roblox/RateBudget.php)
  — the rate limit is per Roblox account, not per process, so the window lives
  in a file under an exclusive lock and is corrected from the API's own
  `x-ratelimit-*` headers.
- [`src/ManorLedger/Storage/History.php`](src/ManorLedger/Storage/History.php)
  — the merge rules that let the dashboard outlive the API's 28-day retention:
  a newer day wins, a missing day is kept, nothing is deleted.
- [`src/ManorLedger/Analytics/Anomalies.php`](src/ManorLedger/Analytics/Anomalies.php)
  — anomaly scoring against a same-weekday baseline on a median-absolute-deviation
  scale, with the direction of a change kept apart from whether it is bad news.
- [`src/ManorLedger/Voices/Synthesizer.php`](src/ManorLedger/Voices/Synthesizer.php)
  — the one model call a day, and the fields computed from dates here instead
  of being asked of the model.
- [`tests/`](tests) — 288 tests that touch no network: every HTTP transport,
  clock and subprocess is injected, including the Roblox API's 429, 202-polling
  and range-too-wide branches.

## What it shows

| View | Question it answers | Key content |
|---|---|---|
| **Valore** (default) | How much is the game earning and what is it worth? | Revenue in Robux and in net USD, 7-day run-rate, estimated valuation over time (conservative / base band), cumulative USD, same-weekday week-over-week table, plateau scenarios. |
| **Crescita** | Is the audience growing and coming back? | DAU / MAU, stickiness, D1 / D7 retention, weekday seasonality index, DAU by platform, new vs returning, visits, session length, peak concurrent users. |
| **Monetizzazione** | Who pays, how much, and where do players come from? | ARPDAU / ARPPU, paying users and conversion, revenue share by platform, acquisition funnel (impressions → clicks → plays) and its conversion rates, recommendation play-through rate, ads. |
| **Salute** | Is anything broken or degrading? | Anomaly signals first (robust z-score vs same-weekday baseline), then FPS, crash rate and counts, out-of-memory exits, server frame rate, memory, DataStore / MemoryStore request status, abuse reports. |
| **Analisi Ads** | Where do the players come from, what did the advertising cost and what came back? | Acquisition sources day by day and as a mix, bought vs organic audience, daily spend per campaign joined from the Ads Manager export, cost per player acquired, spend against attributed revenue, return on spend with the break-even line, the cumulative account, D1 retention of paid vs organic traffic, and one row per campaign. |
| **AI Sentiment** | What are players saying about the game, and how has that changed? | The fifteen most-watched YouTube videos about the game, each summarised from its transcript and its game-related comments; a two-column table of praise and requested fixes with each fix labelled *recent*, *persistent* or *old*; a verdict that reasons over time rather than averaging; verbatim player quotes. |

Every chart card carries a one-sentence *"Cosa dice"* explanation and chips
for the acronyms it uses; a global glossary defines all of them. Hovering (or
touching) a chart highlights the date on the axis and shows every series value
in a floating tooltip; charts in the same view share the hovered date. The
layout is a 12-column grid that collapses to one column on phones.

## How it works

```
             07:00 Europe/Rome (systemd timer, noon retry)
Roblox API ──► bin/refresh ──► data/cache/*.json     raw, 28-day window
                                  │
                                  ▼  merge: newer day wins, nothing is deleted
                            data/history.json      long-lived daily series
                                  │
                              bin/build             Analytics\*
                                  ▼
                          data/dashboard.json  ──►  GET /api/dashboard (after login)
```

- **Ingestion** (`ManorLedger\Roblox`): one endpoint, `POST
  v1/universes/{id}/metrics`, called with bounded concurrency through a
  shared, file-locked **rate budget** that reads Roblox's `x-ratelimit-*`
  headers and falls back to a conservative estimate. It handles long-running
  operations (HTTP 202 + polling), "range too wide" errors (shrink and retry),
  rejected breakdowns (fall back to the aggregate) and HTTP 429 (shared
  back-off so parallel processes slow down together).
- **Incremental history** (`ManorLedger\Storage\History`): every fetch is
  folded into `history.json` keyed by metric → series label → day. A newer
  fetch overwrites a day (Roblox revises the latest day upward, about +5 % for
  revenue); days that are no longer in the API window are kept. A gzip
  snapshot of each raw fetch is archived as well.
- **Analytics** (`ManorLedger\Analytics`): pure functions on date → value maps
  (rolling means, same-weekday deltas, seasonality index, robust anomaly
  scores) and the economic model below. `DashboardBuilder` assembles one JSON
  document that the browser renders; the contract is documented in
  [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
- **AI Sentiment** (`ManorLedger\Voices`): YouTube Data API for the video list
  and comments, `youtube-transcript-api` for captions, a deterministic comment
  filter that drops chatter aimed at the creator, then one model call per
  video (cached by id) and one synthesis call per day. This job runs on the
  owner's workstation and publishes to the server, because YouTube refuses
  caption requests from datacenter addresses; the server only serves the
  result. Design record: [docs/PLAN-voices.md](docs/PLAN-voices.md).
- **Web** (`ManorLedger\Http`, `ManorLedger\Auth`): a front controller with an
  exact-match router, a login form, and a single read-only API route. Pages
  never call Roblox; they only read the pre-built JSON.

## Economic model

The numbers on the *Valore* page are estimates built from explicit,
configurable assumptions (`config/app.php`, `economics`), all shown in the UI:

| Assumption | Default | Why |
|---|---|---|
| DevEx rate | 0.0038 USD per Robux | Roblox Developer Exchange rate. |
| Royalty share | 17 % | Revenue withheld before the developer's net (licence royalty and any other share). An installation sets its own per game in `<data>/revenue-shares.json`, outside the repository. |
| Valuation multiple, base | 30 × monthly net | "The current level holds" scenario: the theoretical ceiling. |
| Valuation multiple, conservative | 18 × monthly net | The discounted reading for a game past its growth phase. |
| Plateau shares | 6 %, 10 %, 15 % of peak DAU | Scenarios for an audience that has settled. |

- Net USD per day = Robux × DevEx × (1 − royalty).
- Monthly net run-rate = **7-day mean** of net USD × 30. The mean, not the
  median: this game roughly doubles at weekends, and a median over seven days
  would systematically understate a week by about a third.
- Valuation(t) = monthly net run-rate(t) × multiple, plotted over time as a
  band between the two multiples.
- The most recent revenue day is **provisional**: Roblox still revises it, so
  it is excluded from means and drawn dimmed with a "provvisorio" marker.
- Trend is read on **same-weekday** comparisons (Monday vs last Monday), never
  day-over-day, because weekly seasonality dwarfs day-to-day change.

Details and the full glossary: [docs/METRICS.md](docs/METRICS.md).

## Repository layout

```
bin/            CLI entry points: refresh, build, ads-import, import-legacy, user,
                serve, voices, voices-publish
config/         metrics catalog, dimension pairs, glossary, app settings
src/ManorLedger/
  Roblox/       API client, rate budget, metric catalog, refresher
  Ads/          Ads Manager export reader (campaign spend, no API for it)
  Voices/       YouTube client, comment filter, transcripts, model adapter, synthesis
  Storage/      atomic JSON store, incremental history, gzip snapshots
  Analytics/    series maths, economics, seasonality, anomalies, builder
  Auth/         argon2id hashing, users, sessions, CSRF, throttle, TOTP, audit
  Http/         request/response, router, security headers, controllers
  Support/      config, clock, helpers
public/         the only web root: index.php + static assets (ECharts vendored)
templates/      login, layout, dashboard shell, error
tests/          PHPUnit (no network, synthetic fixtures)
deploy/         nginx site, php-fpm pool, systemd timer, cloudflared rule, install.sh
docs/           architecture & data contracts, metrics, deploy, security notes
data/           runtime state (git-ignored): cache, history, dashboard, users, key
```

## Run it locally

Requirements: PHP 8.2+ with `curl`, `json`, `mbstring`, `zlib`. Docker is
used only for tests when the host PHP is older.

```bash
git clone https://github.com/sr4sr/SR4SR.git manor-ledger && cd manor-ledger

# 1. a user (password is prompted, never passed on the command line)
php bin/user add me --role=owner

# 2. data: either fetch for real…
echo -n "<your Open Cloud API key>" > data/api-key && chmod 600 data/api-key
php bin/refresh && php bin/build
#    …or build from the synthetic fixture to look around without a key
php bin/import-legacy tests/fixtures/legacy-cache --catalog=config/metrics.json && php bin/build

# 3. serve
bin/serve            # http://127.0.0.1:8099
```

The Open Cloud key needs only the **Analytics: read** scope for the universe.

### More than one game

The games are the `games` map in `config/app.php`, keyed by slug: `locust`
(the default, data at the root of `data/`) and `colorblind` (THE SEWER,
formerly COLORBLIND: the slug kept the old name; data under
`data/games/colorblind/`, key in `data/games/colorblind/api-key` or
`MANOR_API_KEY_FILE_COLORBLIND`). Each entry carries its universe id, its
royalty share and whether it has the Ads and AI Sentiment views. The scripts
take `--game=<slug>` (default: the default game) or `--all`:

```bash
php bin/refresh --game=colorblind && php bin/build --game=colorblind
php bin/refresh --all && php bin/build --all     # in turn; one failure does not stop the rest
```

With two or more games the header shows a selector (`/?game=<slug>`, kept in
the session; anything that is not a configured slug means the default game).
To add one, add its entry and see [docs/DEPLOY.md](docs/DEPLOY.md#games).

## Deploy

Production runs on a 1 GB Oracle Cloud Always Free VM shared with other
services, behind a Cloudflare Tunnel: nginx and php-fpm listen on loopback
only, no inbound port is opened, TLS terminates at the Cloudflare edge.

```bash
deploy/install.sh --api-key /path/to/key --with-data   # first time
deploy/install.sh --api-key /path/to/key --game colorblind   # another game's key
deploy/install.sh                                      # updates
```

The script is idempotent and documented in [docs/DEPLOY.md](docs/DEPLOY.md).
The server address is not in the repository: copy `deploy/deploy.local.env.example`
to `deploy/deploy.local.env` (git-ignored) and set `MANOR_VPS`.

The YouTube analysis is the one job that does **not** run on the server: a
`systemd --user` timer on the workstation (`deploy/systemd/user/`) runs
`bin/voices` at 08:00 Europe/Rome and `bin/voices-publish` pushes the result.
The daily job is a hardened systemd unit (`manor-ledger-refresh.timer`,
07:00 Europe/Rome with a noon retry). It runs as `manor-fetch`, the only
account able to read the API key; the web pool runs as a separate user that
can read the built dashboard but not the key.

## Security

The dashboard is private. Threat model and controls are in
[SECURITY.md](SECURITY.md); in short:

- argon2id password hashes, constant-time verification including for unknown
  users, per-IP and per-user lockout with exponential back-off, optional TOTP;
- strict cookie (`__Host-`, `HttpOnly`, `Secure`, `SameSite=Strict`), id
  regeneration on login, idle and absolute timeouts, CSRF token + origin check
  on every POST;
- a strict Content Security Policy with no inline scripts or styles, HSTS,
  `nosniff`, `Referrer-Policy: same-origin`, `frame-ancestors 'none'`;
- the only endpoints are `/login`, `/logout`, `/api/dashboard`, `/api/voices`,
  `/media/yt/{id}.jpg` and `/healthz`;
  nothing mutates state from the web, and the API key is unreachable from the
  web process (separate user, `open_basedir`, `disable_functions`).

## Data policy

This repository contains **code and configuration only**. Real metrics,
revenue figures, the API key, user records and sessions live in `data/`,
which is git-ignored, and on the server under `/var/lib/manor-ledger`.
Test fixtures are synthetic.

## Development

```bash
make deps      # PHPUnit via Composer (Docker)
make check     # lint + tests
make build     # rebuild data/dashboard.json
```

Conventions and the data contracts: [docs/DEV.md](docs/DEV.md),
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). The decisions behind the design,
with the alternatives rejected and the measurements behind them:
[docs/DESIGN-DECISIONS.md](docs/DESIGN-DECISIONS.md). CI runs lint and tests on PHP
8.2, 8.3 and 8.4 and builds the dashboard from the synthetic fixture.

## Licence

MIT — see [LICENSE](LICENSE).
