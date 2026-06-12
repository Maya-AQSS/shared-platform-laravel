<?php

declare(strict_types=1);

namespace Maya\Platform\Support;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

/**
 * Helper estático que parametriza el bloque repetido de AppServiceProvider::boot()
 * en las 5 apps Maya: listener FdwTeardown, Broadcast::routes, Auth::viaRequest,
 * loadMigrationsFrom para ProfileMigrations y URL::forceScheme.
 *
 * Uso mínimo desde AppServiceProvider::boot():
 *
 *     RegistersFdwBootstrap::register($this, [
 *         'profileMigrations' => [
 *             ProfileMigrations::users(),
 *             ProfileMigrations::teams(),
 *         ],
 *     ]);
 *
 * Todas las opciones son opt-in: si no se pasan se usan los valores por defecto
 * que replican el comportamiento actual de las 5 apps.
 */
final class RegistersFdwBootstrap
{
    /**
     * Registra el bloque de bootstrap compartido en el ServiceProvider dado.
     *
     * @param  \Illuminate\Support\ServiceProvider  $provider   El proveedor que llama (normalmente AppServiceProvider).
     * @param  array{
     *     broadcastMiddleware?: list<string>,
     *     profileMigrations?: list<string>,
     *     forceHttps?: bool,
     *     viaRequestResolver?: callable|null,
     * }  $options
     */
    public static function register(
        \Illuminate\Support\ServiceProvider $provider,
        array $options = [],
    ): void {
        $broadcastMiddleware = $options['broadcastMiddleware'] ?? ['api', 'jwt'];
        $profileMigrations = $options['profileMigrations'] ?? [];
        $forceHttps = $options['forceHttps'] ?? false;
        $viaRequestResolver = $options['viaRequestResolver'] ?? null;

        // HTTPS forzado en production/staging (opt-in explícito o auto-detect por env).
        if ($forceHttps || app()->environment(['production', 'staging'])) {
            URL::forceScheme('https');
        }

        // Broadcasting auth endpoint protegido por JWT y bajo prefijo /api/v1.
        Broadcast::routes([
            'prefix' => 'api/v1',
            'middleware' => $broadcastMiddleware,
        ]);

        // Migraciones de shared-profile (FDW stubs en testing, vistas reales en producción).
        // loadMigrationsFrom() es protected en ServiceProvider: lo invocamos con un
        // closure ligado al scope del provider (equivalente exacto a llamarlo desde
        // dentro del propio AppServiceProvider).
        if ($profileMigrations !== []) {
            \Closure::bind(function () use ($profileMigrations): void {
                foreach ($profileMigrations as $migrationPath) {
                    $this->loadMigrationsFrom($migrationPath);
                }
            }, $provider, $provider)();
        }

        // Limpieza FDW antes de migrate:fresh / db:wipe.
        Event::listen(CommandStarting::class, static function (CommandStarting $event): void {
            if (in_array($event->command, ['migrate:fresh', 'db:wipe'], true)) {
                FdwTeardown::dropAllInPublicSchema();
            }
        });

        // Guard JWT stateless: resuelve el usuario desde el atributo 'jwt_user'.
        // El modelo se descubre via binding opcional 'maya.user_model' (registrarlo
        // en AppServiceProvider::register si el modelo no es \App\Models\User) con
        // fallback a \App\Models\User si existe.
        $resolver = $viaRequestResolver ?? static function ($request) {
            $profile = $request->attributes->get('jwt_user');
            if (! is_array($profile) || ! is_string($profile['id'] ?? null) || ($profile['id'] ?? '') === '') {
                return null;
            }

            // Intenta resolver via User model si existe en el contenedor;
            // si la app no tiene modelo User, devuelve null de forma segura.
            try {
                /** @var class-string<\Illuminate\Database\Eloquent\Model>|null $userModel */
                $userModel = app()->bound('maya.user_model')
                    ? app('maya.user_model')
                    : (class_exists(\App\Models\User::class) ? \App\Models\User::class : null);

                return $userModel ? $userModel::query()->find($profile['id']) : null;
            } catch (\Throwable) {
                return null;
            }
        };

        Auth::viaRequest('jwt-token', $resolver);
    }
}
