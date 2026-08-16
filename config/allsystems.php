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
    'path' => env('ALLSYSTEMS_WEBHOOK_PATH', 'allsystems/webhook'),

    /*
     * How far the signature timestamp may be from now, in seconds, before the
     * request is rejected as a replay. AllSystems retries for ~19 minutes, so
     * a value below the default trades replay protection for lost deliveries
     * on a clock-skewed host.
     */
    'tolerance' => (int) env('ALLSYSTEMS_WEBHOOK_TOLERANCE', 300),

    'maintenance' => [
        /*
         * Whether the bundled SyncMaintenanceMode listener is registered. Set
         * false to receive the events and do your own thing with them; the
         * webhook still verifies and still dispatches.
         */
        'enabled' => (bool) env('ALLSYSTEMS_MAINTENANCE_ENABLED', true),

        /*
         * Everything below is your app's own maintenance-mode configuration,
         * passed straight through to Laravel. AllSystems never sends any of it
         * and never sees it.
         */

        // Bypass phrase: visiting /{secret} sets a cookie that lets you through.
        'secret' => env('ALLSYSTEMS_MAINTENANCE_BYPASS_SECRET'),

        // A view name, rendered when the window opens and served as the
        // maintenance page. Equivalent to `artisan down --render=`.
        'template' => env('ALLSYSTEMS_MAINTENANCE_TEMPLATE'),

        // A path to redirect all traffic to. Equivalent to `--redirect=`.
        'redirect' => env('ALLSYSTEMS_MAINTENANCE_REDIRECT'),

        // Seconds for the Refresh header. Equivalent to `--refresh=`.
        'refresh' => env('ALLSYSTEMS_MAINTENANCE_REFRESH') === null
            ? null
            : (int) env('ALLSYSTEMS_MAINTENANCE_REFRESH'),
    ],
];
