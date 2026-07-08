<?php

use Illuminate\Support\Facades\Route;
use Up2Dev\UserTotp\Http\Controllers\TotpController;

Route::prefix(config('user-totp.api_prefix', 'api'))
    ->middleware(config('user-totp.api_middleware', ['api']))
    ->group(function () {

        // Self-service, utilisateur déjà authentifié.
        Route::prefix(config('user-totp.route_prefix', 'totp'))
            ->middleware(config('user-totp.middleware', ['auth:sanctum']))
            ->controller(TotpController::class)
            ->group(function () {
                Route::get('methods', 'methods');

                Route::post('{method}/setup', 'setup')
                    ->whereIn('method', config('user-totp.enabled_methods', ['totp', 'email']));
                Route::post('{method}/enable', 'enable')
                    ->whereIn('method', config('user-totp.enabled_methods', ['totp', 'email']));
                Route::post('{method}/disable', 'disable')
                    ->whereIn('method', config('user-totp.enabled_methods', ['totp', 'email']));
            });

        // Enrôlement forcé pendant le login (config('user-totp.enforced')).
        // Volontairement SANS le middleware d'auth : l'identité passe par le
        // pending_token transmis dans le body de chaque requête.
        Route::prefix(config('user-totp.route_prefix', 'totp') . '/enroll')
            ->controller(TotpController::class)
            ->group(function () {
                Route::post('{method}/setup', 'enrollSetup')
                    ->whereIn('method', config('user-totp.enabled_methods', ['totp', 'email']));
                Route::post('{method}/enable', 'enrollEnable')
                    ->whereIn('method', config('user-totp.enabled_methods', ['totp', 'email']));
            });

        // Déclenche le challenge d'une méthode déjà active pendant le login
        // (choix entre plusieurs méthodes, ou renvoi d'un email).
        Route::post(
            config('user-totp.route_prefix', 'totp') . '/challenge',
            [TotpController::class, 'challenge']
        );
    });
