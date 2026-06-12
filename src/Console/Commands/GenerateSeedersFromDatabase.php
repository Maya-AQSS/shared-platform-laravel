<?php

declare(strict_types=1);

namespace Maya\Platform\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Genera seeders funcionales a partir de los datos reales de la BD (snapshot).
 *
 * Produce database/seeders/<Path>/ con un seeder por tabla (inserts en chunks)
 * más un seeder maestro que trunca, siembra en orden de dependencias FK y
 * resetea las secuencias. Solo tablas base de Postgres: las foreign tables
 * (FDW) y las vistas quedan fuera automáticamente.
 *
 * Restaurar: php artisan db:seed --class="Database\Seeders\Snapshot\DatabaseSnapshotSeeder"
 */
class GenerateSeedersFromDatabase extends Command
{
    protected $signature = 'db:generate-seeders
        {--connection= : Conexión de BD a volcar (por defecto la default)}
        {--path=Snapshot : Subcarpeta dentro de database/seeders donde escribir}
        {--exclude=* : Tablas adicionales a excluir}
        {--chunk=100 : Filas por sentencia insert}';

    protected $description = 'Genera seeders a partir de los datos actuales de la base de datos (solo no-producción)';

    /** Tablas de infraestructura que nunca forman parte del snapshot. */
    private const DEFAULT_EXCLUDES = [
        'migrations',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'cache',
        'cache_locks',
        'password_reset_tokens',
    ];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('No disponible en producción.');

            return self::FAILURE;
        }

        $connection = DB::connection($this->option('connection') ?: null);

        if ($connection->getDriverName() !== 'pgsql') {
            $this->error('Este comando solo soporta conexiones pgsql.');

            return self::FAILURE;
        }

        $excludes = array_merge(self::DEFAULT_EXCLUDES, (array) $this->option('exclude'));
        $tables = $this->sortByForeignKeys($connection, $this->baseTables($connection, $excludes));

        if ($tables === []) {
            $this->warn('No hay tablas base que volcar.');

            return self::SUCCESS;
        }

        $path = trim((string) $this->option('path'), '/');
        $namespace = 'Database\\Seeders\\'.str_replace('/', '\\', $path);
        $dir = database_path('seeders/'.$path);

        File::deleteDirectory($dir);
        File::makeDirectory($dir, 0755, true);

        $chunkSize = max(1, (int) $this->option('chunk'));
        $seeded = [];

        foreach ($tables as $table) {
            $rows = $this->dumpTableSeeder($connection, $table, $dir, $namespace, $chunkSize);
            if ($rows > 0) {
                $seeded[$table] = Str::studly($table).'TableSeeder';
            }
            $this->line(sprintf('  %s %s (%d filas)', $rows > 0 ? '✔' : '·', $table, $rows));
        }

        $this->writeMasterSeeder($dir, $namespace, $tables, $seeded);
        $this->writeReadme($dir, $namespace, $connection->getDatabaseName());

        $this->info(sprintf(
            'Generados %d seeders de %d tablas en database/seeders/%s',
            count($seeded),
            count($tables),
            $path,
        ));
        $this->line(sprintf('Restaurar: php artisan db:seed --class="%s\\DatabaseSnapshotSeeder"', $namespace));

        return self::SUCCESS;
    }

    /**
     * Tablas base del esquema actual (pg_tables excluye vistas y foreign tables FDW).
     *
     * @param  list<string>  $excludes
     * @return list<string>
     */
    private function baseTables(Connection $connection, array $excludes): array
    {
        $names = array_column($connection->select(
            'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename',
        ), 'tablename');

        return array_values(array_diff($names, $excludes));
    }

    /**
     * Orden topológico por dependencias FK (padres primero, ciclos al final).
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function sortByForeignKeys(Connection $connection, array $tables): array
    {
        $edges = $connection->select(<<<'SQL'
            SELECT DISTINCT tc.table_name AS child, ccu.table_name AS parent
            FROM information_schema.table_constraints tc
            JOIN information_schema.constraint_column_usage ccu
              ON ccu.constraint_name = tc.constraint_name
             AND ccu.constraint_schema = tc.constraint_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema = current_schema()
            SQL);

        $inSet = array_flip($tables);
        $parents = array_fill_keys($tables, []);
        foreach ($edges as $edge) {
            if ($edge->child !== $edge->parent && isset($inSet[$edge->child], $inSet[$edge->parent])) {
                $parents[$edge->child][$edge->parent] = true;
            }
        }

        $sorted = [];
        $pending = $tables;
        while ($pending !== []) {
            $ready = array_values(array_filter(
                $pending,
                fn (string $t): bool => array_diff_key($parents[$t], array_flip($sorted)) === [],
            ));
            if ($ready === []) {
                $this->warn('Ciclo de FKs detectado en: '.implode(', ', $pending));
                $ready = $pending; // Romper el ciclo: se insertan tal cual, en transacción.
            }
            $sorted = array_merge($sorted, $ready);
            $pending = array_values(array_diff($pending, $ready));
        }

        return $sorted;
    }

    /** Vuelca una tabla a un seeder con inserts en chunks. Devuelve filas volcadas. */
    private function dumpTableSeeder(
        Connection $connection,
        string $table,
        string $dir,
        string $namespace,
        int $chunkSize,
    ): int {
        $class = Str::studly($table).'TableSeeder';
        $query = $connection->table($table);
        foreach ($this->primaryKeyColumns($connection, $table) as $pkColumn) {
            $query->orderBy($pkColumn);
        }

        $total = 0;
        $chunk = [];
        $handle = null;

        foreach ($query->cursor() as $row) {
            $chunk[] = array_map($this->exportValue(...), (array) $row);
            if (count($chunk) >= $chunkSize) {
                $handle ??= $this->openSeederFile($dir, $namespace, $class);
                $this->writeChunk($handle, $table, $chunk);
                $total += count($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $handle ??= $this->openSeederFile($dir, $namespace, $class);
            $this->writeChunk($handle, $table, $chunk);
            $total += count($chunk);
        }

        if ($handle !== null) {
            fwrite($handle, "    }\n}\n");
            fclose($handle);
        }

        return $total;
    }

    /** @return list<string> */
    private function primaryKeyColumns(Connection $connection, string $table): array
    {
        return array_column($connection->select(<<<'SQL'
            SELECT kcu.column_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON kcu.constraint_name = tc.constraint_name
             AND kcu.constraint_schema = tc.constraint_schema
            WHERE tc.constraint_type = 'PRIMARY KEY'
              AND tc.table_schema = current_schema()
              AND tc.table_name = ?
            ORDER BY kcu.ordinal_position
            SQL, [$table]), 'column_name');
    }

    /** Normaliza valores no portables a PHP (bytea llega como resource). */
    private function exportValue(mixed $value): mixed
    {
        if (is_resource($value)) {
            return '\x'.bin2hex((string) stream_get_contents($value));
        }

        return $value;
    }

    /** @return resource */
    private function openSeederFile(string $dir, string $namespace, string $class)
    {
        $handle = fopen($dir.'/'.$class.'.php', 'wb');
        if ($handle === false) {
            throw new \RuntimeException("No se pudo crear {$class}.php");
        }

        fwrite($handle, <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Illuminate\Database\Seeder;
            use Illuminate\Support\Facades\DB;

            /** Generado por db:generate-seeders — no editar a mano. */
            class {$class} extends Seeder
            {
                public function run(): void
                {

            PHP);

        return $handle;
    }

    /**
     * @param  resource  $handle
     * @param  list<array<string, mixed>>  $chunk
     */
    private function writeChunk($handle, string $table, array $chunk): void
    {
        $rows = var_export($chunk, true);
        // var_export indenta con 2 espacios desde columna 0; reanclar al cuerpo del método.
        $rows = preg_replace('/^/m', '        ', $rows);

        fwrite($handle, "        DB::table('{$table}')->insert(\n{$rows}\n        );\n");
    }

    /**
     * @param  list<string>  $tables  Todas las tablas del snapshot, en orden FK.
     * @param  array<string, string>  $seeded  tabla => clase, solo tablas con filas.
     */
    private function writeMasterSeeder(string $dir, string $namespace, array $tables, array $seeded): void
    {
        $tableList = implode("\n", array_map(
            fn (string $t): string => "        '{$t}',",
            $tables,
        ));
        $seederMap = implode("\n", array_map(
            fn (string $table): string => "        '{$table}' => {$seeded[$table]}::class,",
            array_keys($seeded),
        ));

        File::put($dir.'/DatabaseSnapshotSeeder.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Illuminate\Database\Seeder;
            use Illuminate\Support\Facades\DB;

            /**
             * Restaura el snapshot completo: trunca las tablas, siembra en orden de
             * dependencias FK y resetea las secuencias. Las tablas del snapshot que no
             * existan en la BD destino (p. ej. tablas creadas fuera de migraciones) se
             * omiten con aviso. Generado por db:generate-seeders.
             */
            class DatabaseSnapshotSeeder extends Seeder
            {
                /** Todas las tablas del snapshot, en orden de dependencias FK. */
                private const TABLES = [
            {$tableList}
                ];

                /** Solo las tablas con filas, en el mismo orden. */
                private const SEEDERS = [
            {$seederMap}
                ];

                public function run(): void
                {
                    if (app()->isProduction()) {
                        throw new \RuntimeException('Snapshot seeder deshabilitado en producción.');
                    }

                    \$existing = \$this->existingTables();
                    foreach (array_diff(self::TABLES, \$existing) as \$missing) {
                        \$this->command?->warn("Tabla '{\$missing}' no existe en la BD destino; se omite.");
                    }

                    DB::transaction(function () use (\$existing): void {
                        \$this->truncateAll(\$existing);
                        \$this->call(array_values(array_intersect_key(
                            self::SEEDERS,
                            array_flip(\$existing),
                        )));
                        \$this->resetSequences();
                    });
                }

                /** @return list<string> */
                private function existingTables(): array
                {
                    \$names = array_column(DB::select(
                        'SELECT tablename FROM pg_tables WHERE schemaname = current_schema()',
                    ), 'tablename');

                    return array_values(array_intersect(self::TABLES, \$names));
                }

                /** @param list<string> \$tables */
                private function truncateAll(array \$tables): void
                {
                    \$quoted = implode(', ', array_map(
                        static fn (string \$table): string => '"'.\$table.'"',
                        \$tables,
                    ));

                    DB::statement('TRUNCATE TABLE '.\$quoted.' RESTART IDENTITY CASCADE');
                }

                /** Deja cada secuencia serial/identity apuntando a MAX(col)+1. */
                private function resetSequences(): void
                {
                    \$columns = DB::select(<<<'SQL'
                        SELECT table_name, column_name
                        FROM information_schema.columns
                        WHERE table_schema = current_schema()
                          AND (column_default LIKE 'nextval(%' OR is_identity = 'YES')
                        SQL);

                    foreach (\$columns as \$column) {
                        if (! in_array(\$column->table_name, self::TABLES, true)) {
                            continue;
                        }

                        DB::statement(sprintf(
                            'SELECT setval(pg_get_serial_sequence(\'%1\$s\', \'%2\$s\'), (SELECT COALESCE(MAX("%2\$s"), 0) FROM "%1\$s") + 1, false)',
                            \$column->table_name,
                            \$column->column_name,
                        ));
                    }
                }
            }

            PHP);
    }

    private function writeReadme(string $dir, string $namespace, string $database): void
    {
        $date = now()->toDateTimeString();

        File::put($dir.'/README.md', <<<MD
            # Snapshot de datos — {$database}

            Generado el {$date} con `php artisan db:generate-seeders`.
            Un seeder por tabla con datos + `DatabaseSnapshotSeeder` que trunca,
            siembra en orden FK y resetea secuencias. No editar a mano.

            ## Restaurar

            ```bash
            php artisan db:seed --class="{$namespace}\\DatabaseSnapshotSeeder"
            ```

            ## Regenerar

            ```bash
            php artisan db:generate-seeders
            ```
            MD);
    }
}
