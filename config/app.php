<?php
/**
 * Application settings. Every value can be overridden with an environment
 * variable (MANOR_*), which is how the VPS deployment points to /var/lib.
 * Nothing secret lives here: the Roblox key is a file outside the web root.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$env  = static fn (string $key, string|int|float $default) => getenv($key) !== false ? getenv($key) : $default;
$dataDir = rtrim((string)$env('MANOR_DATA_DIR', $root . '/data'), '/');
// State the web process owns and writes: sessions, login throttling, user
// records, audit log. It is a separate directory because in production the
// refresh job and the web pool run as different users; the job owns the data
// and the pool may only read it, so the pool needs somewhere of its own.
$stateDir = rtrim((string)$env('MANOR_STATE_DIR', $dataDir), '/');

// The games this installation follows, keyed by slug (the slug is what the
// web selector and `bin/refresh --game=` take). The first is the original one
// and keeps the original layout, so production data never had to move; every
// game added later lives under <data>/games/<slug>/. Per-game paths are listed
// in full: a game never inherits another game's file (Support\Games).
$gameDir = static fn (string $slug): string => $dataDir . '/games/' . $slug;
// How much of each game's revenue goes to others before the developer's net
// (licence royalty, partners) is the owner's business, not the repository's:
// <data>/revenue-shares.json, {"<slug>": 0.25, ...}, overrides the defaults
// below. It lives in the data directory so that every build reads it, the
// timer's and a manual one alike. A value outside [0, 1) is ignored.
$shares = json_decode((string)@file_get_contents($dataDir . '/revenue-shares.json'), true);
$share = static fn (string $slug, float $default): float =>
    is_numeric($shares[$slug] ?? null) && $shares[$slug] >= 0 && $shares[$slug] < 1 ? (float)$shares[$slug] : $default;
$games = [
    'locust' => [
        'name'         => 'The Locust\'s Manor',
        'universeId'   => (int)$env('MANOR_UNIVERSE_ID', 10674300622),
        'royaltyShare' => $share('locust', 0.17),    // withheld before the developer's net (see above)
        'voices'       => true,    // AI Sentiment view (YouTube)
        'ads'          => true,    // Ads view (campaign spend from bin/ads-import)
        'paths' => [
            'data'        => $dataDir,
            'cache'       => $dataDir . '/cache',
            'snapshots'   => $dataDir . '/snapshots',
            'history'     => $dataDir . '/history.json',
            'dashboard'   => $dataDir . '/dashboard.json',
            'ads'         => $dataDir . '/ads.json',
            'apiKey'      => (string)$env('MANOR_API_KEY_FILE', $dataDir . '/api-key'),
            'voices'      => $dataDir . '/voices.json',
            'voicesMedia' => $dataDir . '/media/yt',
        ],
    ],
    // Renamed on Roblox from COLORBLIND to THE SEWER (same universe): the slug
    // keeps the old name so the data directory and the key file did not move.
    'colorblind' => [
        'name'         => 'THE SEWER',
        'universeId'   => (int)$env('MANOR_UNIVERSE_ID_COLORBLIND', 10766214469),
        'royaltyShare' => $share('colorblind', 0.0),  // not a licensed game: no royalty withheld
        'voices'       => false,
        'ads'          => false,
        'paths' => [
            'data'      => $gameDir('colorblind'),
            'cache'     => $gameDir('colorblind') . '/cache',
            'snapshots' => $gameDir('colorblind') . '/snapshots',
            'history'   => $gameDir('colorblind') . '/history.json',
            'dashboard' => $gameDir('colorblind') . '/dashboard.json',
            'apiKey'    => (string)$env('MANOR_API_KEY_FILE_COLORBLIND', $gameDir('colorblind') . '/api-key'),
        ],
    ],
];
$defaultGame = 'locust';

return [
    'app' => [
        'name'      => 'Manor Ledger',
        // The default game; `game` and `universeId` are aliases of its entry
        // below, kept for the code that predates the games map.
        'defaultGame' => $defaultGame,
        'game'      => $games[$defaultGame]['name'],
        'universeId'=> $games[$defaultGame]['universeId'],
        'host'      => (string)$env('MANOR_HOST', 'localhost'),   // expected Host header (CSRF origin check)
        'timezone'  => 'Europe/Rome',
        'debug'     => (bool)$env('MANOR_DEBUG', 0),
    ],
    'paths' => [
        'data'       => $dataDir,
        'state'      => $stateDir,
        'cache'      => $dataDir . '/cache',
        'snapshots'  => $dataDir . '/snapshots',
        'history'    => $dataDir . '/history.json',
        'dashboard'  => $dataDir . '/dashboard.json',
        // Campaign spend from the Ads Manager export: no API, so bin/ads-import
        // writes it by hand and bin/build joins it with the analytics.
        'ads'        => $dataDir . '/ads.json',
        'users'      => $stateDir . '/users.json',
        'throttle'   => $stateDir . '/throttle',
        'sessions'   => $stateDir . '/sessions',
        'authLog'    => $stateDir . '/auth.log',
        'apiKey'     => $games[$defaultGame]['paths']['apiKey'],
        // Voci (YouTube): computed on the workstation, published to the VPS.
        'voices'          => $dataDir . '/voices.json',
        'voicesCache'     => $dataDir . '/voices-cache',
        'voicesMedia'     => $dataDir . '/media/yt',
        'transcripts'     => $dataDir . '/voices-transcripts',
        'youtubeKey'      => (string)$env('MANOR_YOUTUBE_KEY_FILE', $dataDir . '/youtube-api-key'),
        'prompts'         => $root . '/config/prompts',
        'python'          => (string)$env('MANOR_PYTHON', $dataDir . '/venv/bin/python'),
        'transcriptScript'=> $root . '/tools/transcript.py',
        'metrics'    => $root . '/config/metrics.json',
        'dimensions' => $root . '/config/dimensions.json',
        'glossary'   => $root . '/config/glossary.json',
    ],
    'games' => $games,
    'roblox' => [
        'baseUrl'     => 'https://apis.roblox.com/analytics-query-api/',
        // One window file for every game: the quota belongs to the owner, and
        // a game run with its own counter would spend a window already used.
        'budgetFile'  => $dataDir . '/cache/.budget.json',
        // Measured: 30 requests per calendar minute, shared by every key of the owner.
        'windowLimit' => 18,   // fallback until an x-ratelimit header is seen
        'windowSecs'  => 60,
        'reserve'     => 6,
        'concurrency' => 2,
        'maxTries'    => 3,
        'maxPolls'    => 8,
        'pollWait'    => 1.5,
        'deadline'    => 600,
    ],
    'economics' => [
        'devexUsdPerRobux' => 0.0038,  // Roblox DevEx rate
        'royaltyShare'     => $games[$defaultGame]['royaltyShare'],  // per game: see `games`
        'multiples'        => ['conservative' => 18, 'base' => 30],
        'plateauShares'    => [0.06, 0.10, 0.15],
    ],
    // AI Sentiment: the most watched YouTube videos about the game (topN below).
    // The job runs on the workstation (datacenter IPs cannot read captions) and
    // the VPS only serves.
    'voices' => [
        'queries' => ["The Locust's Manor", "The Locust's Manor Roblox"],
        // Measured cut: #15 still has 4 288 views, #16 has 4 182 and no comments.
        // The tail is mostly shorts and memes, thin to summarise, and must not
        // outvote the substantial videos (see config/prompts/voices-synthesis.md).
        'topN'    => 15,
        // Shorts filled half of the list with a few seconds of jump scare and
        // nothing to summarise: the most watched are videos of 4 minutes and
        // up, the same floor as the archive behind the recent list.
        'topMinSeconds' => 240,
        // The game's Roblox place id (public, it is in the game's URL). A video
        // whose description links another game as well is shown but kept out
        // of the synthesis.
        'placeId' => (int)$env('MANOR_PLACE_ID', 97090732168175),
        // A second list on the page: the newest videos of creators with at
        // least `recentMinSubscribers` (needs `archive`). Analysed together
        // with the top, a video in both counted once.
        'recentN'              => 15,
        'recentMinSubscribers' => 1000,
        'host'    => (string)$env('MANOR_VOICES_HOST', 'workstation'),
        // At most one caption request every two minutes, measured start to
        // start. YouTube throttles caption requests from one address, and the
        // job has no deadline worth trading that for: most videos come from
        // the cache, and a new one costs two minutes, not a refusal.
        'transcriptInterval' => 120.0,
        // After a refusal, how many hours before this address asks again, by
        // refusals in a row: 6, then 12, then 24 (the last step repeats). The
        // breaker in TranscriptFetcher stops the rest of the run at once.
        'blockCooldownHours' => [6.0, 12.0, 24.0],
        // One refused video is not a refused address: YouTube walls single
        // videos too. Three refusals in a row on different videos make a
        // block; between them, ten minutes instead of two.
        'blockConfirmations' => 3,
        'afterBlockInterval' => 600.0,
        // What the workstation does about a refusal: a desktop alert (also
        // raised, and nothing more, for caption errors of any other kind), and a
        // one-shot systemd timer that starts this unit again when the pause
        // ends. Empty unit = no retry scheduled (the daily timer still runs).
        // Optional archive of videos kept by a date-ordered crawl (ArchiveSource):
        // its most watched join the relevance search before the top is picked.
        // Unset, missing or older than the limit: search only, as before.
        'archive'            => (string)$env('MANOR_VOICES_ARCHIVE', ''),
        'archiveMaxAgeHours' => 72,
        'archiveTake'        => 150,
        'blockAlert'     => true,
        'blockRetryUnit' => 'manor-voices.service',
    ],
    // One adapter, one profile per task, the model chosen by configuration:
    // ids drift (gemini-2.5-flash already 404s) and the free tier answers 503
    // under load, so both the model and its fallback must be editable here.
    'llm' => [
        'temperature' => 0.2,
        'maxRetries'  => 3,
        // Nominal spacing only: the paid tier takes a burst of calls without
        // complaint. Raise it if the key ever goes back to a throttled tier —
        // the retry-with-backoff path below covers the hiccups either way.
        'minInterval' => 1.0,
        'profiles' => [
            'summary' => [
                'driver'   => 'gemini',
                'baseUrl'  => 'https://generativelanguage.googleapis.com/v1beta/',
                'model'    => (string)$env('MANOR_LLM_MODEL', 'gemini-3.6-flash'),
                'keyFile'  => (string)$env('MANOR_GEMINI_KEY_FILE', $dataDir . '/gemini-api-key'),
                'timeout'  => 120,
                // gemini-3.6-flash thinks before it answers and the thoughts are
                // billed against this budget: too low and the answer comes back empty.
                'maxOutputTokens' => 8192,
                'fallback' => 'local',
            ],
            'synthesis' => [
                'driver'   => 'gemini',
                'baseUrl'  => 'https://generativelanguage.googleapis.com/v1beta/',
                'model'    => (string)$env('MANOR_LLM_MODEL', 'gemini-3.6-flash'),
                'keyFile'  => (string)$env('MANOR_GEMINI_KEY_FILE', $dataDir . '/gemini-api-key'),
                'timeout'  => 180,
                'maxOutputTokens' => 8192,
                'fallback' => 'local',
            ],
            // No key, no network: keeps the feature working when the remote is down.
            'local' => [
                'driver'   => 'ollama',
                'baseUrl'  => (string)$env('MANOR_OLLAMA_URL', 'http://localhost:11434/'),
                'model'    => (string)$env('MANOR_OLLAMA_MODEL', 'qwen2.5:14b-instruct'),
                'keyFile'  => null,
                'timeout'  => 300,
                'fallback' => null,
            ],
        ],
    ],
    'auth' => [
        'idleTimeout'     => 1800,
        'absoluteTimeout' => 43200,
        'maxFailures'     => 5,
        'lockoutSeconds'  => 900,
        'cookieName'      => '__Host-manor_session',
    ],
];
