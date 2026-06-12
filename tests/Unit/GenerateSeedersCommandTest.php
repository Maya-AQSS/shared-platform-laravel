<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Maya\Platform\Console\Commands\GenerateSeedersFromDatabase;

it('command is registered in the artisan kernel', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('db:generate-seeders');
    expect($commands['db:generate-seeders'])->toBeInstanceOf(GenerateSeedersFromDatabase::class);
});

it('command fails in production environment', function (): void {
    $this->app['env'] = 'production';

    $this->artisan('db:generate-seeders')->assertExitCode(1);
});

it('command fails on non-pgsql connection', function (): void {
    // Default test env uses sqlite — command should reject it
    $this->artisan('db:generate-seeders')->assertExitCode(1);
});
