<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Относительные /build/... — один и тот же JS на vspace.loc и 127.0.0.1 (общий кэш, без APP_URL в URL).
        Vite::createAssetPathsUsing(static fn (string $path, ?bool $secure = null): string => '/'.ltrim($path, '/'));

        ResetPassword::createUrlUsing(static function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email=".urlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
