<?php

namespace Up2Dev\UserTotp;

use Illuminate\Support\ServiceProvider;

class UserTotpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/user-totp.php', 'user-totp');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/totp.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'user-totp');

        $this->publishes([
            __DIR__ . '/../config/user-totp.php' => config_path('user-totp.php'),
        ], 'user-totp-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/user-totp'),
        ], 'user-totp-views');
    }
}
