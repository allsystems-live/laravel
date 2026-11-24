<?php

declare(strict_types=1);

return [
    /*
     * The shared secret AllSystems signs with. Copy it from the application's
     * page in AllSystems admin, where it is shown once at creation and once
     * per regeneration.
     */
    'secret' => env('ALLSYSTEMS_WEBHOOK_SECRET'),

    /*
     * The path the webhook is served at, relative to your app root and with no
     * leading slash. Change it if it collides with something; AllSystems only
     * needs the full URL you register there.
     */
    'path' => env('ALLSYSTEMS_WEBHOOK_PATH', 'webhooks/allsystems'),

    /*
     * Restricts the webhook route to this host. Null serves it on every host.
     */
    'domain' => env('ALLSYSTEMS_WEBHOOK_DOMAIN'),

    /*
     * How far the signature timestamp may be from now, in seconds, before the
     * request is rejected as a replay. AllSystems retries for ~19 minutes, so
     * a value below the default trades replay protection for lost deliveries
     * on a clock-skewed host.
     */
    'tolerance' => (int) env('ALLSYSTEMS_WEBHOOK_TOLERANCE', 300),

    // Where the maintenance window is kept, in the default cache store.
    'cache_key' => 'allsystems:platform-maintenance',

    // Safety expiry in minutes, so a lost `ended` webhook cannot keep the app down.
    'max_minutes' => (int) env('ALLSYSTEMS_MAINTENANCE_MAX_MINUTES', 30),

    // Seconds sent in the Retry-After header of the 503.
    'retry_after' => 60,

    // Paths the gate lets through during a window, besides the webhook; `up` keeps deploy smoke checks green.
    'except' => ['up'],

    // The view served as the 503 page for HTML requests.
    'view' => 'allsystems::maintenance',

    // The `detail` of the JSON:API 503 body.
    'json_detail' => "We're doing some scheduled maintenance. Please try again in a minute or two.",
];
