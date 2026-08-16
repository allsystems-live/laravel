<?php

declare(strict_types=1);

use AllSystems\Laravel\Http\Controllers\WebhookController;
use AllSystems\Laravel\Http\Middleware\VerifySignature;
use Illuminate\Support\Facades\Route;

/*
 * One route, and deliberately naked: no CSRF (there is no session and no form),
 * no session middleware (the sender is a server, not a browser), no auth guard
 * (the HMAC signature IS the authentication). VerifySignature is the only thing
 * standing in front of the controller, and it must stay that way.
 */
Route::post(config('allsystems.path'), WebhookController::class)
    ->middleware(VerifySignature::class)
    ->name('allsystems.webhook')
;
