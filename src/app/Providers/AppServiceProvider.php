<?php

namespace App\Providers;

use App\Database\PgsqlConnection;
use App\Models\PersonalAccessToken;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Свой класс соединения нужен для мягкого отказа реплик чтения:
        // при недоступности слейва SELECT уходит на мастер.
        Connection::resolverFor('pgsql', static function ($pdo, string $database, string $prefix, array $config): PgsqlConnection {
            return new PgsqlConnection($pdo, $database, $prefix, $config);
        });
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

        // Кеш токенов (выключен по умолчанию): при удалении/изменении токена
        // сбрасываем его кеш по хешу, который лежит в колонке token.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $forgetToken = static function (PersonalAccessToken $token): void {
            PersonalAccessToken::forgetCachedToken((string) $token->token);
        };

        PersonalAccessToken::deleted($forgetToken);
        PersonalAccessToken::updated(static function (PersonalAccessToken $token): void {
            // last_used_at обновляется на каждый запрос — кеш из-за него не сбрасываем.
            if ($token->wasChanged(['token', 'tokenable_id', 'abilities', 'expires_at'])) {
                PersonalAccessToken::forgetCachedToken((string) $token->token);
            }
        });
    }
}
