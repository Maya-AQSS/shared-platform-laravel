<?php

declare(strict_types=1);

namespace Maya\Platform\Support;

use Illuminate\Support\Facades\DB;

/**
 * Elimina las vistas y foreign tables (postgres_fdw) del schema `public` antes
 * de un `migrate:fresh` / `db:wipe`.
 *
 * `db:wipe` solo dropea tablas base: deja atrás las vistas pass-through y las
 * foreign tables que crea el paquete shared-profile (`users`, `teams`,
 * `study_types`, …). En la siguiente corrida de migraciones, las migraciones
 * que recrean esas vistas con `CREATE OR REPLACE VIEW` (p.ej. el rewrite de
 * `teams`) fallan con «cannot drop columns from view» porque la vista vieja
 * sigue existiendo. Limpiarlas aquí deja `migrate:fresh` reproducible.
 *
 * En entorno de testing las entidades FDW son tablas físicas (no vistas ni
 * foreign tables), así que este teardown no encuentra nada y es inofensivo.
 */
final class FdwTeardown
{
    public static function dropAllInPublicSchema(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Foreign tables primero (CASCADE arrastra las vistas que dependen de
        // ellas); luego cualquier vista restante.
        foreach (self::foreignTables() as $name) {
            DB::statement('DROP FOREIGN TABLE IF EXISTS "'.$name.'" CASCADE');
        }

        foreach (self::views() as $name) {
            DB::statement('DROP VIEW IF EXISTS "'.$name.'" CASCADE');
        }
    }

    /**
     * @return list<string>
     */
    private static function foreignTables(): array
    {
        return array_map(
            static fn (object $r): string => (string) $r->foreign_table_name,
            DB::select(
                "SELECT foreign_table_name FROM information_schema.foreign_tables WHERE foreign_table_schema = 'public'"
            )
        );
    }

    /**
     * @return list<string>
     */
    private static function views(): array
    {
        return array_map(
            static fn (object $r): string => (string) $r->table_name,
            DB::select(
                "SELECT table_name FROM information_schema.views WHERE table_schema = 'public'"
            )
        );
    }
}
