<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Maya\Platform\Support\FdwTeardown;

it('skips all drops on non-pgsql connection', function (): void {
    // Default test environment uses sqlite — no DB statements should be executed.
    DB::shouldReceive('connection->getDriverName')->andReturn('sqlite');
    DB::shouldReceive('statement')->never();
    DB::shouldReceive('select')->never();

    FdwTeardown::dropAllInPublicSchema();
});

it('queries information_schema on pgsql and drops foreign tables and views', function (): void {
    DB::shouldReceive('connection->getDriverName')->andReturn('pgsql');

    DB::shouldReceive('select')
        ->once()
        ->with("SELECT foreign_table_name FROM information_schema.foreign_tables WHERE foreign_table_schema = 'public'")
        ->andReturn([(object) ['foreign_table_name' => 'users_fdw']]);

    DB::shouldReceive('select')
        ->once()
        ->with("SELECT table_name FROM information_schema.views WHERE table_schema = 'public'")
        ->andReturn([(object) ['table_name' => 'teams']]);

    DB::shouldReceive('statement')
        ->once()
        ->with('DROP FOREIGN TABLE IF EXISTS "users_fdw" CASCADE');

    DB::shouldReceive('statement')
        ->once()
        ->with('DROP VIEW IF EXISTS "teams" CASCADE');

    FdwTeardown::dropAllInPublicSchema();
});

it('drops nothing when no foreign tables or views exist on pgsql', function (): void {
    DB::shouldReceive('connection->getDriverName')->andReturn('pgsql');

    DB::shouldReceive('select')
        ->once()
        ->with("SELECT foreign_table_name FROM information_schema.foreign_tables WHERE foreign_table_schema = 'public'")
        ->andReturn([]);

    DB::shouldReceive('select')
        ->once()
        ->with("SELECT table_name FROM information_schema.views WHERE table_schema = 'public'")
        ->andReturn([]);

    DB::shouldReceive('statement')->never();

    FdwTeardown::dropAllInPublicSchema();
});

it('drops multiple foreign tables and views in correct order', function (): void {
    DB::shouldReceive('connection->getDriverName')->andReturn('pgsql');

    DB::shouldReceive('select')
        ->once()
        ->with("SELECT foreign_table_name FROM information_schema.foreign_tables WHERE foreign_table_schema = 'public'")
        ->andReturn([
            (object) ['foreign_table_name' => 'users_fdw'],
            (object) ['foreign_table_name' => 'teams_fdw'],
        ]);

    DB::shouldReceive('select')
        ->once()
        ->with("SELECT table_name FROM information_schema.views WHERE table_schema = 'public'")
        ->andReturn([
            (object) ['table_name' => 'teams'],
            (object) ['table_name' => 'study_types'],
        ]);

    DB::shouldReceive('statement')->with('DROP FOREIGN TABLE IF EXISTS "users_fdw" CASCADE')->once();
    DB::shouldReceive('statement')->with('DROP FOREIGN TABLE IF EXISTS "teams_fdw" CASCADE')->once();
    DB::shouldReceive('statement')->with('DROP VIEW IF EXISTS "teams" CASCADE')->once();
    DB::shouldReceive('statement')->with('DROP VIEW IF EXISTS "study_types" CASCADE')->once();

    FdwTeardown::dropAllInPublicSchema();
});
