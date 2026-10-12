# Design decisions

Twelve decisions that shaped this codebase, each with the alternative that was
rejected and the measurement or failure that settled it. They are recorded here
because the reasoning is not visible from the code alone: what a line does is in
the code, why it is that line is here.

Measurements quoted below were taken on this project's own data; the AI
Sentiment figures come from the field test recorded in
[PLAN-voices.md](PLAN-voices.md).

---

## 1. Aggregate series are fetched without a breakdown

**Decision** — Every aggregate metric is requested as a plain series (label
`""`); platform, country and age splits are requested separately as metric ×
dimension pairs.

**Rejected alternative** — Fetch each metric once with its user breakdown and
sum the parts to get the total, which halves the number of API calls.

**Evidence** — The sum of the per-platform series is not the aggregate for a
*unique users* metric: a player who opens the game on a phone and on a PC is
one DAU but two rows in the split. Measured against the aggregate Roblox
returns, summing overstated DAU by about 0.4 % here. The previous single-file
proxy did exactly that, and the fix was carried over as the first correctness
change of this implementation. Breakdowns that *are* the metric (request
status, memory category, session-time bucket, ad format) are kept, because
there is no double counting to do: the buckets partition events, not people.

Funnel metrics go the other way, because the API decides it: `FunnelName`
can only be a filter, and since 8 Oct 2026 it is mandatory. They are fetched
once per funnel and stored as one series per funnel, with no total.

**Where** — `src/ManorLedger/Roblox/MetricCatalog.php`
(`INTRINSIC_BREAKDOWNS`, `effectiveBreakdown()`, `SPLIT_DIMENSIONS`),
`Refresher::fetchRows()`, `config/dimensions.json`,
`docs/METRICS.md` §"Aggregates vs breakdowns" and §"Funnels".

---

## 2. History is incremental and nothing is ever deleted

**Decision** — Every fetch is folded into `data/history.json` keyed metric →
series label → day. A newer fetch overwrites a day it carries; a day it does
not carry is left alone. The most recent revenue day is marked provisional and
excluded from every window.

**Rejected alternative** — Serve the dashboard from the API's own window and
re-fetch what is needed, which keeps one source of truth.

**Evidence** — The API keeps ~28 days for most metrics, so anything older than
that exists only if this file kept it; the dashboard now shows a series longer
than the source it came from. The overwrite rule is not a tie-breaker but a
correction: comparing the archived snapshots of consecutive days shows Roblox
revising the freshest revenue day upward by +4.5 % to +7 % within about 24
hours. A mean that included it would drift downward for one day and then jump,
so the provisional day is present in per-day series and absent from windowed
ones.

**Where** — `src/ManorLedger/Storage/History.php` (merge rules),
`src/ManorLedger/Storage/Snapshots.php` (the archive the revision was measured
on), `src/ManorLedger/Analytics/DashboardBuilder.php`
(`dataThrough` / `provisionalDate`), `docs/ARCHITECTURE.md` §"Data contracts".

---

## 3. Run-rates use a 7-day mean; trend uses same-weekday comparisons

**Decision** — The monthly run-rate is the 7-day **mean** × 30, and
week-over-week change compares a day with the same weekday of the previous
week.

**Rejected alternative** — A median over the window (resistant to a single
outlier day) and a day-over-day delta (fresher).

**Evidence** — This game roughly doubles at weekends: the weekday seasonality
index puts Sunday around 1.7 and midweek around 0.7 of the weekly mean. Seven
consecutive days contain exactly one of each weekday, so their mean is already
neutral to that cycle, while the median of seven days always lands on a weekday
and understates the week by about a third. The same cycle makes day-over-day
deltas read as change when they are only the calendar: Monday against Sunday is
a fall of half regardless of how the game is doing.

**Where** — `src/ManorLedger/Analytics/Series.php` (calendar windows: a hole in
the series yields `null`, never a window that silently spans eight days),
`src/ManorLedger/Analytics/Economics.php` (the mean and the `$through`
argument), `src/ManorLedger/Analytics/Seasonality.php` (whole weeks only),
`weekOverWeek` in `DashboardBuilder`.

---

## 4. Anomalies are scored against a same-weekday baseline on a MAD scale

**Decision** — For each watched metric, the expected value of the last complete
day is the mean of the same weekday over the previous four weeks; the residual
is divided by the median absolute deviation of the previous residuals × 1.4826;
the result is reported with a direction and a separate `bad` flag.

**Rejected alternative** — A z-score against the mean and standard deviation of
the trailing 14 days, which needs no weekday bookkeeping.

**Evidence** — With a weekend that doubles, a plain trailing window flags every
Saturday. And the standard deviation is set by the very spikes the detector is
meant to find: one incident inflates the threshold enough to hide the next one,
so the scale is taken from the median absolute deviation instead. Direction is
kept apart from judgement because a fall in crash rate and a fall in revenue
are the same arithmetic and opposite news; the frontend colours by `bad`, so a
good surprise does not render as an incident. When there is not enough history
for four same-weekday points the detector says so (`method: "median14"`) rather
than pretending to a baseline it does not have.

**Where** — `src/ManorLedger/Analytics/Anomalies.php` (`WEEKS_BASELINE = 4`,
`ROBUST_SIGMA = 1.4826`, `MIN_RESIDUALS = 5`, `CANDIDATES`), contract in
`docs/ARCHITECTURE.md` (`anomalies[]`).

---

## 5. Two service users on the server, and a state directory for the web one

**Decision** — `manor-fetch` runs the daily job, owns `/var/lib/manor-ledger`
and is the only account that can read the Roblox API key; `manor` runs the
php-fpm pool, reads the built dashboard through the shared group and writes
only under `/var/lib/manor-ledger/web`.

**Rejected alternative** — One service account for both, which is what the
first deployment did.

**Evidence** — With one account, any code execution in the web process read the
Open Cloud key, and the web process is the only part of the system exposed to
the internet. Splitting the accounts surfaced a second fault the same week: the
data directory is read-only for the pool, but the pool writes sessions,
throttle counters, user records and the audit log, so every login failed until
those moved to a directory the pool owns. That separation is worth having on
its own — a compromised web process can now corrupt its own state but not the
history. A third fault followed from the same split: `JsonStore` hardcoded
0600, so each rebuild made `dashboard.json` unreadable to the pool and the site
answered 520 until a manual `chmod`; the file mode is now a constructor
argument, 0640 for the dashboard and 0600 for everything else.

**Where** — `deploy/php-fpm/manor-ledger.conf` (`user = manor`,
`MANOR_STATE_DIR`), `deploy/systemd/manor-ledger-refresh.service`
(`User=manor-fetch`, `ReadWritePaths`), `src/ManorLedger/Storage/JsonStore.php`
(the mode argument), `docs/DEPLOY.md` §"Topology".

---

## 6. nginx listens on loopback behind a Cloudflare Tunnel

**Decision** — nginx binds `127.0.0.1:8090`, php-fpm listens on a unix socket,
and the public entry point is `cloudflared` on the same host. No inbound port
is opened, and the server's address is not in the repository.

**Rejected alternative** — A public listener on 443 with a certificate on the
host.

**Evidence** — The VM is shared with other services and already runs a tunnel
on 443; adding a second public listener would have meant changing the host's
exposure for a dashboard used by one person. With the tunnel there is nothing
to scan: the only path in is authenticated at the edge and terminates on
loopback, and a wrong `Host` on that port is dropped with 444. Because the
address is what an attacker would need to bypass the edge, it lives in a
git-ignored `deploy/deploy.local.env`, and both deployment scripts refuse to
run without it rather than falling back to a default — which is how it had
previously ended up committed. The client IP is then read from
`CF-Connecting-IP`, but only when the TCP peer is loopback; anywhere else the
header is a client-supplied string and is ignored.

**Where** — `deploy/nginx/manor-ledger.conf`, `deploy/cloudflared-ingress.yml`,
`deploy/deploy.local.env.example`, `deploy/install.sh`,
`src/ManorLedger/Http/Request.php` (`clientIp()`).

---

## 7. Authentication: what is checked, and one thing that had to be relaxed

**Decision** — argon2id (64 MiB, time 4) for hashes; verification against a
fixed dummy hash when the username is unknown; per-IP *and* per-username
lockout doubling from 15 minutes up to 24 hours; `__Host-` cookie with
`HttpOnly`, `Secure`, `SameSite=Strict` and id regeneration on login; CSRF
token plus an `Origin`/`Referer` check on every POST; a CSP that allows no
inline script or style; and `Referrer-Policy: same-origin` rather than
`no-referrer`.

**Rejected alternative** — bcrypt (kept only as a fallback for runtimes built
without argon2); a per-IP lockout alone; `no-referrer`, which is the stricter
value and was the original choice.

**Evidence** — Two counters are needed because they defend against different
attacks: per-IP stops one address spraying usernames, per-username stops a
distributed attack on one account. The dummy-hash verification exists so the
response time does not answer "does this user exist"; without it the unknown
branch returns before any hashing. The last item is a measured failure rather
than a preference: logging in from Firefox — not from curl — returned 403 on
every attempt, because the CSRF check requires an `Origin` or a `Referer`,
Firefox omits `Origin` on same-origin form posts, and `no-referrer` suppressed
the other one. `same-origin` keeps the referrer off cross-origin requests and
restores the check. The strict CSP is the reason templates carry no inline
attribute handlers and no `<style>` block: it is enforced, so the constraint
had to be designed for rather than discovered.

**Where** — `src/ManorLedger/Auth/PasswordHasher.php`,
`src/ManorLedger/Auth/LoginThrottle.php`, `src/ManorLedger/Auth/Csrf.php`,
`src/ManorLedger/Auth/Session.php`, `src/ManorLedger/Http/SecurityHeaders.php`,
`SECURITY.md`.

---

## 8. The build version goes in the asset path, not in a query string

**Decision** — Assets are served from `/assets/v<build>/…`, which nginx maps
back to `public/assets/…`. Unversioned paths still resolve but with
`Cache-Control: no-cache`.

**Rejected alternative** — `?v=<build>` appended by the application, the usual
cache-busting trick and what this project did first.

**Evidence** — An ES module imports its siblings with relative paths, which
inherit the directory but drop the query string. With the version in a query,
only the entry point got a fresh URL; every other module kept being served from
the browser and CDN caches, which had been told the files were immutable for 30
days. The symptom was a deploy whose new charts were invisible while the entry
point was demonstrably new. In the path, the whole module graph inherits the
version, so a deploy produces a new set of URLs and no stale module can survive
anywhere.

**Where** — `deploy/nginx/manor-ledger.conf` (the `^/assets/v[a-z0-9]+/` alias
and the `no-cache` fallback), `src/ManorLedger/Http/View.php`,
`public/assets/js/`.

---

## 9. AI Sentiment: transcripts first, comments filtered, and where the job runs

**Decision** — The per-video summary is built primarily from the caption track
and secondarily from comments that survive a deterministic filter. The job runs
on the owner's workstation and publishes the result to the server, which only
serves it. The video list is kept on topic by exclusion, not by requiring the
word "roblox".

**Rejected alternative** — Comments as the primary source (no Python
dependency, no caption problem); running the job on the server like every other
job; and, once the server turned out not to be able to fetch captions,
routing those requests through a residential proxy.

**Evidence** — Comments were measured before being trusted: on the two videos
with most comments, 60 sampled each, mean length 73 and 34 characters, with 61 %
and 33 % above 40 characters, and the most-liked substantial ones were nearly
all addressed to the creator ("love your videos") rather than to the game. The
creator's spoken reaction while playing is where opinions about the game are,
so transcripts lead and comments are pre-filtered by rule before any model sees
them. On where the job runs: the same ten ids returned 5 transcripts from the
workstation's residential connection and 0 from the VPS, every one of them
`RequestBlocked`, because the caption endpoints refuse datacenter ranges.
Residential proxies would obtain the captions by defeating that control and
breaching the terms of service; declining to do so is why the job lives on the
workstation, which also means no new secret reaches the server. On the filter:
only a third of the creators write "roblox" anywhere, and requiring it was
measured to drop 14 of 69 candidate videos including the single most watched
one, cutting available comments from 2 198 to 851. The rule is therefore to
drop a video only when it names a *different* platform without also naming
Roblox — on the sample, exactly one video, a Fortnite remake.

**Where** — `src/ManorLedger/Voices/TranscriptFetcher.php`,
`src/ManorLedger/Voices/CommentFilter.php`,
`src/ManorLedger/Voices/YouTubeClient.php` (`topVideos`, the exclusion
pattern), `tools/transcript.py`, `deploy/systemd/user/manor-voices.service`,
`bin/voices-publish`, `docs/PLAN-voices.md` §2, §5, §9.

---

## 10. The model was chosen by measurement, and the local one stayed

**Decision** — `gemini-3.6-flash` for both the per-video summary and the daily
synthesis, with `qwen2.5:14b-instruct` on local Ollama wired as a fallback;
every card records which model wrote it; recency labels are computed from
publication dates and never read from the model's answer.

**Rejected alternative** — Running everything on the local model (no key, no
data leaving the machine, no cost); and, at the other end, trusting the model's
own `recency` field, which it was originally asked to produce.

**Evidence** — Three models were run over the same three real transcripts with
the same prompt: `qwen2.5:7b-instruct` returned valid JSON with worthless
content (verbatim English fragments as if they were concepts, 5 s);
`qwen2.5:14b-instruct` returned real concepts in Italian on English input
(21 s) but answered a Portuguese transcript in **Spanish** (40 s);
`gemini-3.6-flash` held Italian on the same Portuguese input and produced
points specific enough to act on, including an accessibility finding neither
local model reached (15 s). The local model stays because the failure it covers
was also measured: the provider returned 503 "experiencing high demand" during
the same test session, and one of the fifteen cards in production is on the
fallback because a safety filter refuses that video's material. Which model
spoke is therefore recorded per video rather than assumed. The recency label
went the other way: a code review found the synthesis accepting the model's own
label whenever it happened to be a valid enum value, which is the one judgement
the page makes about time, on data — publication dates — we already hold. It is
now computed from those dates and the prompt no longer asks for it, so a
hostile summary cannot argue a complaint into looking fixed.

**Where** — `src/ManorLedger/Voices/LlmClient.php` (profiles, retries,
fallback chain), `config/app.php` (`llm` block),
`src/ManorLedger/Voices/Synthesizer.php` (`cutoffs()`, `recencyOf()`),
`src/ManorLedger/Voices/VoicesBuilder.php` (`models.fellBackTo`),
`docs/PLAN-voices.md` §6, §8.

---

## 11. Model output is treated as hostile input from end to end

**Decision** — Everything a model returns is validated against the expected
JSON shape, capped in length, stripped of control characters, and inserted into
the page with `textContent`. Everything that goes *into* a prompt — titles,
channel names, comments, previous summaries — is fenced, and the fences are
stripped from the interpolated value first. Video ids are matched against
`^[A-Za-z0-9_-]{11}$` before they are used to build a URL or a path.

**Rejected alternative** — Trusting the model to respect the schema because it
is asked to, and rendering its text as HTML so a summary can carry emphasis.

**Evidence** — The inputs are written by strangers: a YouTube title is chosen
by the creator, a comment by anyone. A comment that closes the data block and
issues instructions is the ordinary case to design for, not an exotic one, so
the delimiters are declared as data in the system prompt and no value can close
them. Downstream, nothing trusts the summary beyond displaying it: a failed
summary publishes a reason code, because the exception message it used to carry
contained a local filesystem path. The id regex exists because that id is the
only variable path segment in the whole application.

**Where** — `src/ManorLedger/Voices/VideoSummarizer.php` (`fence()`, `clean()`,
`validator()`, `shape()`), `src/ManorLedger/Voices/Synthesizer.php`
(`safe()`, `safeList()`), `src/ManorLedger/Http/Controllers/VoicesController.php`
(`MEDIA_ROUTE`), `public/assets/js/views/voci.js`,
`config/prompts/voices-summary.md`.

---

## 12. No runtime dependencies, and every boundary is injected

**Decision** — The application requires PHP 8.2 with `curl`, `json`,
`mbstring` and `zlib`, and nothing else; PHPUnit is the only Composer package
and it is dev-only. HTTP transports, the clock and the process runner are
passed in as callables or interfaces.

**Rejected alternative** — A framework and an HTTP client library, which would
have supplied routing, sessions and retries for free.

**Evidence** — The application has six URLs, one storage format and one
mutating endpoint; a framework's surface would have exceeded its own. The
absence of dependencies is also what keeps the 1 GB shared VM's deployment to
an `rsync` and a pool reload, with no update path of someone else's to track
for a dashboard that is exposed to the internet. Injection is what makes the
suite useful: 274 tests run with no network access and no real figures in any
fixture, which is why the Roblox client's 429, 202-polling and "range too wide"
branches are tested at all — they are reproduced in a fake transport rather
than waited for in production.

**Where** — `composer.json`, `src/autoload.php`,
`src/ManorLedger/Roblox/CurlTransport.php` (the callable contract),
`src/ManorLedger/Support/Clock.php` and `FrozenClock.php`, `tests/`.
