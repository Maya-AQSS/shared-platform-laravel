<?php

namespace Maya\Platform\Database;

use Illuminate\Support\Facades\DB;

/**
 * Utilidades compartidas entre migraciones que montan catálogo vía postgres_fdw.
 *
 * Los nombres de relación pasados como identificadores deben ser valores controlados
 * por la migración (constantes), no entrada de usuario.
 */
final class PostgresFdwMigration
{
    /**
     * Activa postgres_fdw. Retorna false si no hay permisos (misma semántica que las migraciones de catálogo).
     */
    public static function ensurePostgresFdwExtension(?string $catalogLabel = null): bool
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgres_fdw');

            return true;
        } catch (\Throwable $e) {
            $suffix = $catalogLabel !== null && $catalogLabel !== '' ? " ({$catalogLabel})" : '';
            logger()->error('No permission for postgres_fdw'.$suffix);

            return false;
        }
    }

    /**
     * Crea servidor FDW y user mapping para CURRENT_USER (patrón users / teams).
     *
     * No usa `password_required 'false'`: en PostgreSQL solo un superusuario puede crear o modificar
     * mappings con esa opción; las migraciones suelen ejecutarse con el usuario de aplicación (p. ej. Docker).
     */
    public static function createFdwServerWithUserMapping(
        string $serverName,
        string $host,
        string $port,
        string $database,
        string $username,
        string $password,
    ): void {
        $safeHost = self::escapeSqlLiteral($host);
        $safePort = self::escapeSqlLiteral($port);
        $safeDatabase = self::escapeSqlLiteral($database);
        $safeUsername = self::escapeSqlLiteral($username);
        $safePassword = self::escapeSqlLiteral($password);

        // CREATE SERVER IF NOT EXISTS no actualiza opciones cuando el server
        // ya existe. Si el slot fue creado con un host distinto (p.ej.
        // 'maya_infra_postgres' antes del fix que apunta a 'maya-<slot>-postgres'),
        // un re-migrate dejaría el FDW apuntando al host viejo y todos los
        // endpoints que lo consulten devolverían 500 hasta correr el hotfix.
        // Forzamos ALTER SERVER después del CREATE para que las opciones siempre
        // queden alineadas con el .env actual, incluso en re-runs idempotentes.
        DB::statement("
            CREATE SERVER IF NOT EXISTS {$serverName}
            FOREIGN DATA WRAPPER postgres_fdw
            OPTIONS (host '{$safeHost}', port '{$safePort}', dbname '{$safeDatabase}')
        ");

        // ALTER SERVER OPTIONS (SET ...) requiere que la opción exista. Como
        // CREATE acaba de garantizar host/port/dbname, SET es seguro.
        DB::statement("
            ALTER SERVER {$serverName}
            OPTIONS (SET host '{$safeHost}', SET port '{$safePort}', SET dbname '{$safeDatabase}')
        ");

        // USER MAPPING: CREATE IF NOT EXISTS solo crea si falta; el password
        // normalmente no cambia entre runs. Si necesitas rotar credenciales
        // sin re-crear el slot, usa hotfix-fdw-host.sh o dropFdwServerAndUserMapping
        // seguido de createFdwServerWithUserMapping.
        DB::statement("
            CREATE USER MAPPING IF NOT EXISTS FOR CURRENT_USER
            SERVER {$serverName}
            OPTIONS (user '{$safeUsername}', password '{$safePassword}')
        ");
    }

    /**
     * Elimina user mapping y servidor FDW (no borra la extensión postgres_fdw).
     */
    public static function dropFdwServerAndUserMapping(string $serverName): void
    {
        DB::statement('DROP USER MAPPING IF EXISTS FOR CURRENT_USER SERVER '.$serverName);
        DB::statement('DROP SERVER IF EXISTS '.$serverName.' CASCADE');
    }

    /**
     * Escapa literales interpolados en OPTIONS (schema_name, table_name, etc.).
     */
    public static function escapeSqlLiteral(string $value): string
    {
        return addcslashes($value, "'\\");
    }

    /**
     * Elimina una vista o tabla base en `public` sin abortar si el tipo no coincide.
     */
    public static function dropViewOrTableInPublic(string $relationName): void
    {
        $safeName = self::escapeSqlLiteral($relationName);

        DB::statement("
            DO \$\$ BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM information_schema.views
                    WHERE table_schema = 'public'
                      AND table_name = '{$safeName}'
                ) THEN
                    EXECUTE 'DROP VIEW ' || quote_ident('{$safeName}');
                ELSIF EXISTS (
                    SELECT 1
                    FROM information_schema.tables
                    WHERE table_schema = 'public'
                      AND table_name = '{$safeName}'
                      AND table_type = 'BASE TABLE'
                ) THEN
                    EXECUTE 'DROP TABLE ' || quote_ident('{$safeName}');
                END IF;
            END \$\$
        ");
    }

    public static function dropForeignTableIfExists(string $fdwTableName): void
    {
        DB::statement('DROP FOREIGN TABLE IF EXISTS '.$fdwTableName.' CASCADE');
    }

    /**
     * Crea `{base}_fdw` apuntando a `remoteSchema.remoteRelation` y una vista `{base}` con SELECT directo.
     */
    public static function createForeignTableWithPassThroughView(
        string $catalogBaseName,
        string $foreignColumnsSql,
        string $viewSelectSql,
        string $fdwServer,
        string $remoteSchema,
        string $remoteRelationName,
    ): void {
        $fdwTable = $catalogBaseName.'_fdw';
        $safeSchema = self::escapeSqlLiteral($remoteSchema);
        $safeRelation = self::escapeSqlLiteral($remoteRelationName);

        DB::statement('
            CREATE FOREIGN TABLE IF NOT EXISTS '.$fdwTable.' (
                '.$foreignColumnsSql.'
            )
            SERVER '.$fdwServer.'
            OPTIONS (schema_name \''.$safeSchema.'\', table_name \''.$safeRelation.'\')
        ');

        DB::statement('
            CREATE OR REPLACE VIEW '.$catalogBaseName.' AS
            SELECT '.$viewSelectSql.'
            FROM '.$fdwTable.'
        ');

        self::revokeAppUserWriteOnFdwRelation($fdwTable);
    }

    /**
     * Fuerza solo lectura sobre la foreign table para el usuario de aplicación de PostgreSQL.
     */
    public static function revokeAppUserWriteOnFdwRelation(string $fdwTableName): void
    {
        $appUser = config('database.connections.pgsql.username');

        if ($appUser === null || $appUser === '') {
            return;
        }

        try {
            DB::statement('REVOKE INSERT, UPDATE, DELETE ON '.$fdwTableName.' FROM "'.$appUser.'"');
            DB::statement('GRANT SELECT ON '.$fdwTableName.' TO "'.$appUser.'"');
        } catch (\Throwable $e) {
            logger()->warning("FDW: could not set permissions for {$appUser} on {$fdwTableName}: {$e->getMessage()}");
        }
    }
}
