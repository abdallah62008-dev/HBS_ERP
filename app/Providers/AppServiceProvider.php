<?php

namespace App\Providers;

use App\Services\AuditLogService;
use Illuminate\Auth\Events\Failed as LoginFailed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Audit auth events (per 03_RBAC_SECURITY_AUDIT.md). Storing
        // last_login_at on the user model keeps the field documented in
        // the schema accurate without requiring a custom controller.
        Event::listen(Login::class, function (Login $event) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

            AuditLogService::log(
                action: 'login',
                module: 'auth',
                recordType: $event->user::class,
                recordId: $event->user->getKey(),
            );
        });

        Event::listen(LoginFailed::class, function (LoginFailed $event) {
            AuditLogService::log(
                action: 'login_failed',
                module: 'auth',
                newValues: ['email' => $event->credentials['email'] ?? null],
            );
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user) {
                AuditLogService::log(
                    action: 'logout',
                    module: 'auth',
                    recordType: $event->user::class,
                    recordId: $event->user->getKey(),
                );
            }
        });
    }
}
