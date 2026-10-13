<?php

namespace App\Console\Commands;

use Database\Seeders\Testing\TestFixtureSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Build the database the isolated test suite runs against, from scratch.
 *
 * The dev database cannot be rebuilt by migrations: it was set up from a SQL
 * import, and `php artisan migrate` fails on pending migrations whose tables
 * already exist. So the test database is built from three committed pieces
 * instead, in this order:
 *
 *   database/testing/schema.sql     every table, no rows
 *   database/testing/reference.sql  configuration the app cannot run without
 *                                   (languages, permissions, tax, shipping,
 *                                   site settings, pages, migrations)
 *   TestFixtureSeeder               known customers, admins and products at
 *                                   fixed ids, with dummy gateway keys
 *
 * Nothing here reads the dev database, so the result is identical on every
 * machine and in CI. Refresh the two dumps with database/testing/export.sh
 * after a migration changes the schema.
 *
 * The target is dropped and recreated, so the command refuses any database
 * whose name does not end in `_test`, and the one the app itself is using.
 */
class RebuildTestDatabase extends Command
{
    protected $signature = 'test:db
                            {--database= : Database to (re)build (default: DB_TEST_DATABASE, else mrmobile_test)}';

    protected $description = 'Drop and rebuild the isolated test database from the committed schema, reference data and fixtures';

    public function handle()
    {
        $name = $this->option('database') ?: env('DB_TEST_DATABASE', 'mrmobile_test');
        $appDatabase = config('database.connections.mysql.database');

        if (!preg_match('/^[A-Za-z0-9_]+_test$/', $name)) {
            $this->error("Refusing to rebuild `$name`: the name must end in _test.");
            return 1;
        }
        if ($name === $appDatabase) {
            $this->error("Refusing to rebuild `$name`: it is the database the app is using.");
            return 1;
        }

        $dir = database_path('testing');
        $started = microtime(true);

        $pdo = $this->serverConnection();
        $pdo->exec("DROP DATABASE IF EXISTS `$name`");
        $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $pdo->exec("USE `$name`");
        // Load under the dev server's (non-strict) mode, which the dumps were
        // written under. A CI MariaDB defaults to strict and could refuse a
        // reference row the dev server accepted.
        $pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        $this->info("Created `$name`.");

        $tables = $this->runFile($pdo, "$dir/schema.sql");
        $this->line("  schema.sql     $tables statements");
        $rows = $this->runFile($pdo, "$dir/reference.sql");
        $this->line("  reference.sql  $rows statements");
        $pdo = null;

        // Point the app's own connection at the new database so the seeder's
        // models and Passport write there, not to the dev database.
        config(['database.connections.mysql.database' => $name]);
        DB::purge('mysql');

        $this->call('db:seed', ['--class' => TestFixtureSeeder::class, '--force' => true]);

        DB::purge('mysql');
        config(['database.connections.mysql.database' => $appDatabase]);

        $this->info(sprintf('Test database `%s` ready in %.1fs.', $name, microtime(true) - $started));
        return 0;
    }

    /** A connection to the server with no database selected, so it can drop and create one. */
    private function serverConnection(): PDO
    {
        $c = config('database.connections.mysql');
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $c['host'], $c['port']);

        return new PDO($dsn, $c['username'], $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * Execute a mysqldump file one statement at a time, so a failure names
     * the statement instead of vanishing inside a multi-statement exec.
     *
     * Splitting on a semicolon at the end of a line is safe for mysqldump
     * output: it escapes newlines inside string values as \n, so a statement
     * can only end where a real line ends.
     */
    private function runFile(PDO $pdo, string $path): int
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Missing $path - run database/testing/export.sh");
        }

        $sql = str_replace("\r\n", "\n", file_get_contents($path));
        $count = 0;

        foreach (preg_split('/;\s*\n/', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || strpos($statement, '--') === 0 && strpos($statement, "\n") === false) {
                continue;
            }
            try {
                $pdo->exec($statement);
            } catch (\PDOException $e) {
                throw new \RuntimeException(basename($path) . ': ' . $e->getMessage() . "\n" . substr($statement, 0, 300));
            }
            $count++;
        }

        return $count;
    }
}
