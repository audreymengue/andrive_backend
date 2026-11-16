<?php

namespace Tests;

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use RuntimeException;

final class DatabaseSafety
{
    /** @param array<class-string, mixed> $traits */
    public static function install(Application $app, array $traits): void
    {
        $mode = null;
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app) use (&$mode, $traits): void {
            $config = $app->make('config');
            $mode = $config->get('database.default');
            if ($mode === 'sqlite') {
                if ($config->get('database.connections.sqlite.database') !== ':memory:' || filled($config->get('database.connections.sqlite.url'))) {
                    throw new RuntimeException('Tests refuses : seule SQLite :memory: est autorisee.');
                }
            } elseif ($mode === 'mysql') {
                if ($config->get('database.connections.mysql.database') !== 'aninf_test' || filled($config->get('database.connections.mysql.url'))) {
                    throw new RuntimeException('Tests refuses : MySQL exige exclusivement aninf_test, sans URL.');
                }
                foreach (['Illuminate\\Foundation\\Testing\\LazilyRefreshDatabase', 'Illuminate\\Foundation\\Testing\\RefreshDatabase', 'Illuminate\\Foundation\\Testing\\DatabaseMigrations', 'Illuminate\\Foundation\\Testing\\DatabaseTruncation'] as $trait) {
                    if (isset($traits[$trait])) {
                        throw new RuntimeException('Reinitialisation MySQL interdite : utiliser le profil general-safe SQLite en memoire.');
                    }
                }
            } else {
                throw new RuntimeException('Driver de test non autorise.');
            }
        });
        $app->afterBootstrapping(RegisterProviders::class, function (Application $app) use (&$mode): void {
            $factory = new ConnectionFactory($app);
            foreach (['mysql', 'sqlite', 'pgsql', 'sqlsrv', 'mariadb'] as $driver) {
                $app->make('db')->extend($driver, function (array $config, string $name) use ($factory, &$mode, $driver) {
                    if ($mode !== $driver || filled($config['url'] ?? null)) {
                        throw new RuntimeException('Connexion secondaire interdite pendant les tests.');
                    }
                    if ($mode === 'sqlite' && ($config['database'] ?? null) !== ':memory:') {
                        throw new RuntimeException('Fichier SQLite persistant interdit.');
                    }
                    if ($mode === 'mysql') {
                        if (($config['database'] ?? null) !== 'aninf_test') {
                            throw new RuntimeException('Base MySQL interdite.');
                        }
                        $isolatedPort = getenv('ANINF_VALIDATION_MYSQL_PORT');
                        if ($isolatedPort !== false && (int) ($config['port'] ?? 0) !== (int) $isolatedPort) {
                            throw new RuntimeException('Port MySQL isole incorrect.');
                        }
                    }

                    $connection = $factory->make($config, $name);
                    if ($mode === 'mysql' && getenv('ANINF_VALIDATION_RUN_ID') !== false) {
                        $marker = $connection->getPdo()->query('SELECT run_id FROM validation_run_marker')->fetchColumn();
                        if (! hash_equals(getenv('ANINF_VALIDATION_RUN_ID'), (string) $marker)) {
                            throw new RuntimeException('Identite de la base privee incorrecte.');
                        }
                    }

                    return $connection;
                });
            }
        });
    }
}
