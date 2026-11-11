<?php

use Sprouthub\Verity\Engine\Drand;

/*
|--------------------------------------------------------------------------
| Verity draw engine
|--------------------------------------------------------------------------
|
| Every setting the engine reads. Publish with:
|     php artisan vendor:publish --tag=verity-config
| Each one is documented in INTEGRATION.md ("Configuration reference") too.
| Settings marked "integrity" change how draws can be verified: set them once,
| before the first competition is published, and leave them.
*/

return [

    /*
     * The prefix of every competition reference the engine makes ("VTY-7Q2K9C"). A reference is
     * part of every hash (spec §3), so it is written once and never changes. Letters, digits and
     * "-" only; at most 64 characters with room for a carry-over round suffix ("-C12").
     * VERITY_REF_PREFIX
     */
    'ref_prefix' => env('VERITY_REF_PREFIX', 'VTY-'),

    /*
     * Where the public verification page lives on the host site (used in anchor emails).
     * VERITY_VERIFY_URL, e.g. https://example.com/verify
     */
    'verify_url' => env('VERITY_VERIFY_URL', env('APP_URL', 'http://localhost').'/verify'),

    /*
     * Public randomness mixed into every drawn competition (integrity).
     *  - "drand": the League of Entropy drand "quicknet" beacon, BLS-verified (needs ext-gmp).
     *  - "local": random bytes made on this server. Demos and tests only, NOT verifiable;
     *    refused when APP_ENV=production.
     * VERITY_BEACON
     */
    'beacon' => env('VERITY_BEACON', 'drand'),

    'drand' => [
        // Shown in the verification record as the place to look a round up. VERITY_DRAND_URL
        'base_url' => env('VERITY_DRAND_URL', 'https://api.drand.sh'),
        // Relays tried in turn. Every round's BLS signature is checked against the quicknet public
        // key (src/Engine/Drand.php), so a relay can't make a round up. VERITY_DRAND_RELAYS (comma-separated)
        'relays' => array_values(array_filter(array_map('trim', explode(',', (string) env('VERITY_DRAND_RELAYS',
            'https://api.drand.sh,https://api2.drand.sh,https://api3.drand.sh,https://drand.cloudflare.com'))))),
        // The quicknet network's constants (spec §4.1). Not settings: shown here for reference.
        'chain_hash' => Drand::CHAIN_HASH,
        'genesis_time' => Drand::GENESIS_TIME,
        'period' => Drand::PERIOD,
        // The round a draw uses is fixed at publish: the first round at least this long after its
        // closing time (spec §4.2). Long enough for the scheduler (every minute) to close and hash the
        // entries first. At least 60 (a redraw committed with less fails verification). VERITY_DRAND_CLOSE_DELAY
        'close_delay_seconds' => (int) env('VERITY_DRAND_CLOSE_DELAY', 60),
        // A close (or redraw) too late for the planned round commits to a round at least this far in
        // the future instead, flagged as late in the record (spec §4.3). VERITY_DRAND_AHEAD
        'commit_ahead_seconds' => (int) env('VERITY_DRAND_AHEAD', 9),
        // At close, drand's own latest round is read and the committed round must come after it, so a
        // server clock running behind real time can't commit to a round already public (REV-1).
        // Leave on in production; tests that move the clock turn it off. VERITY_DRAND_CLOCK_CHECK
        'clock_check_at_close' => filter_var(env('VERITY_DRAND_CLOCK_CHECK', true), FILTER_VALIDATE_BOOLEAN),
        // Without ext-gmp the signature can't be checked; outside production this many relays must
        // then agree. Production never draws without a BLS check. VERITY_DRAND_RELAYS_AGREE
        'relays_must_agree' => (int) env('VERITY_DRAND_RELAYS_AGREE', 2),
    ],

    /*
     * If drand is unreachable at draw time, use local randomness instead of waiting. Demos only:
     * ignored when APP_ENV=production (a live draw always waits for drand). VERITY_BEACON_FALLBACK
     */
    'beacon_fallback_to_local' => filter_var(env('VERITY_BEACON_FALLBACK', false), FILTER_VALIDATE_BOOLEAN),

    /*
     * Key that seals server seeds at rest ("base64:..." from `php artisan verity:seal-seeds --new-key`).
     * Keep it in the environment, never in the database, and keep an offline copy: without it no
     * sealed seed can be revealed, so no draw can run. Empty = sealed with APP_KEY (verity:check FAILs
     * in production). VERITY_SEED_KEY
     */
    'seed_key' => env('VERITY_SEED_KEY'),

    /*
     * Pepper for owner_hash and label_hash in order.paid audit events (src/Support/OwnerHash.php):
     * the public chain says "these entries belong to owner X" without saying who X is, and moving
     * entries to another owner later no longer matches. Set once, keep secret, never change.
     * Empty = derived from APP_KEY (verity:check warns in production). VERITY_OWNER_PEPPER
     */
    'owner_pepper' => env('VERITY_OWNER_PEPPER'),

    /*
     * Operators emailed when a check fails (chain broken, anchor failed, a close not witnessed,
     * a redraw that can't run). Alerts are also dispatched as Sprouthub\Verity\Events\AlertRaised
     * for the host to route elsewhere. VERITY_ALERT_EMAILS (comma-separated)
     */
    'alert_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('VERITY_ALERT_EMAILS', ''))))),

    /*
     * Independent people who receive the daily audit-chain anchor and every close/redraw witness
     * email (threat model G-8, G-9). Outside the operator's control is the point. VERITY_ANCHOR_EMAILS
     */
    'anchor_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('VERITY_ANCHOR_EMAILS', ''))))),

    /*
     * RFC 3161 timestamp authority that witnesses each close and redraw schedule (threat model G-8),
     * e.g. https://freetsa.org/tsr. Empty: witnessed by email only (verity:check warns in production).
     * VERITY_TSA_URL
     */
    'tsa_url' => env('VERITY_TSA_URL', ''),

    /*
     * The hourly chain check compares the last anchor in anchor_log with the chain, so a database
     * restored from an older copy is noticed (src/Support/ChainRollback.php). Unset = on in
     * production, off elsewhere. VERITY_ANCHOR_LOG_CHECK, VERITY_ANCHOR_LOG
     */
    'anchor_log_check' => env('VERITY_ANCHOR_LOG_CHECK'),
    'anchor_log' => env('VERITY_ANCHOR_LOG'),

    /*
     * Redraws (protocol R2, spec §5.6). Past this many redraws of one competition, a forfeit
     * leaves the prize unawarded (draw.forfeited) instead. VERITY_MAX_REDRAWS
     */
    'redraw' => [
        'max_per_draw' => (int) env('VERITY_MAX_REDRAWS', 3),
    ],

    /*
     * Minimum entries with carry-over (spec §13.3): a carried competition's next round closes this
     * many days after it, unless the competition sets carry_days itself. VERITY_CARRY_DAYS
     */
    'carry_days' => (int) env('VERITY_CARRY_DAYS', 7),

    /*
     * A closed competition not drawn this many minutes after its beacon round raises an alert from
     * verity:process-due. VERITY_STUCK_DRAW_MINUTES
     */
    'stuck_draw_minutes' => (int) env('VERITY_STUCK_DRAW_MINUTES', 15),

    /*
     * The package's own public JSON routes (read-only, rate limited):
     *   GET {prefix}/competitions/{ref}/verification   the verification record (spec §7)
     *   GET {prefix}/audit?competition={ref}           that competition's audit events, withheld fields nulled
     * Off by default: most hosts return Verity::verification() from their own controller.
     * VERITY_ROUTES, VERITY_ROUTE_PREFIX
     */
    'routes' => [
        'enabled' => filter_var(env('VERITY_ROUTES', false), FILTER_VALIDATE_BOOLEAN),
        'prefix' => env('VERITY_ROUTE_PREFIX', 'verity'),
        'middleware' => ['api', 'throttle:60,1'],
    ],

];
