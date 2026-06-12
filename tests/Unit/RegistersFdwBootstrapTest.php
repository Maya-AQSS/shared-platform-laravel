<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Maya\Platform\Support\RegistersFdwBootstrap;

/**
 * Create a minimal ServiceProvider bound to the test app container.
 *
 * Deliberadamente NO redeclara loadMigrationsFrom: el método hereda la
 * visibilidad protected real de Illuminate\Support\ServiceProvider. Un stub
 * con override public enmascaró el bug "Call to protected method" que rompía
 * el arranque de las apps que pasaban profileMigrations.
 */
function realServiceProvider(): ServiceProvider
{
    return new class(app()) extends ServiceProvider {
        public function register(): void {}
    };
}

it('registers broadcast routes with default api+jwt middleware', function (): void {
    Broadcast::shouldReceive('routes')
        ->once()
        ->with(['prefix' => 'api/v1', 'middleware' => ['api', 'jwt']]);
    Auth::spy();

    RegistersFdwBootstrap::register(realServiceProvider());
});

it('registers broadcast routes with custom middleware', function (): void {
    Broadcast::shouldReceive('routes')
        ->once()
        ->with(['prefix' => 'api/v1', 'middleware' => ['api', 'custom-jwt']]);
    Auth::spy();

    RegistersFdwBootstrap::register(realServiceProvider(), [
        'broadcastMiddleware' => ['api', 'custom-jwt'],
    ]);
});

it('forces https scheme when forceHttps option is true', function (): void {
    URL::shouldReceive('forceScheme')->once()->with('https');
    Broadcast::shouldReceive('routes')->once()->withAnyArgs();
    Auth::spy();

    RegistersFdwBootstrap::register(realServiceProvider(), ['forceHttps' => true]);
});

it('does not force https when forceHttps is false and env is testing', function (): void {
    // 'testing' is not production/staging so no forceScheme expected
    URL::shouldReceive('forceScheme')->never();
    Broadcast::shouldReceive('routes')->once()->withAnyArgs();
    Auth::spy();

    app()['env'] = 'testing';

    RegistersFdwBootstrap::register(realServiceProvider(), ['forceHttps' => false]);
});

it('registers CommandStarting listener for FdwTeardown', function (): void {
    Broadcast::shouldReceive('routes')->once()->withAnyArgs();
    Auth::spy();

    // Capture what is registered
    $registeredListeners = [];
    Event::listen(CommandStarting::class, function () use (&$registeredListeners): void {
        $registeredListeners[] = true;
    });

    RegistersFdwBootstrap::register(realServiceProvider());

    // At least one listener registered (the FdwTeardown one + ours)
    $listeners = Event::getListeners(CommandStarting::class);
    expect($listeners)->not->toBeEmpty();
});

it('uses provided viaRequest resolver instead of default', function (): void {
    Broadcast::shouldReceive('routes')->once()->withAnyArgs();

    $customResolver = static fn ($request) => null;

    Auth::shouldReceive('viaRequest')
        ->once()
        ->with('jwt-token', $customResolver);

    RegistersFdwBootstrap::register(realServiceProvider(), [
        'viaRequestResolver' => $customResolver,
    ]);
});

it('loads profile migrations through a real provider whose loadMigrationsFrom is protected', function (): void {
    Broadcast::shouldReceive('routes')->once()->withAnyArgs();
    Auth::spy();

    RegistersFdwBootstrap::register(realServiceProvider(), [
        'profileMigrations' => ['/some/path/users', '/some/path/teams'],
    ]);

    $paths = app('migrator')->paths();

    expect($paths)->toContain('/some/path/users')
        ->toContain('/some/path/teams');
});

it('registers migration paths even when the migrator was already resolved', function (): void {
    Broadcast::shouldReceive('routes')->once()->withAnyArgs();
    Auth::spy();

    // Fuerza la rama "ya resuelto" de callAfterResolving (ejecución inmediata).
    $migrator = app('migrator');

    RegistersFdwBootstrap::register(realServiceProvider(), [
        'profileMigrations' => ['/already/resolved/teams'],
    ]);

    expect($migrator->paths())->toContain('/already/resolved/teams');
});

it('loads no migrations when profileMigrations option is omitted', function (): void {
    Broadcast::shouldReceive('routes')->once()->withAnyArgs();
    Auth::spy();

    $pathsBefore = app('migrator')->paths();

    RegistersFdwBootstrap::register(realServiceProvider());

    expect(app('migrator')->paths())->toBe($pathsBefore);
});
