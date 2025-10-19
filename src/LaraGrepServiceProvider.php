<?php

namespace LaraGrep;

use Illuminate\Support\ServiceProvider;
use LaraGrep\Metadata\SchemaMetadataLoader;
use LaraGrep\Services\ConversationStore;
use LaraGrep\Services\LaraGrepQueryService;

class LaraGrepServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laragrep.php', 'laragrep');

        $this->app->singleton(SchemaMetadataLoader::class, function ($app) {
            $config = $app['config']->get('laragrep', []);
            $defaultContext = [];

            $contexts = $config['contexts'] ?? [];

            if (is_array($contexts)) {
                if (isset($contexts['default']) && is_array($contexts['default'])) {
                    $defaultContext = $contexts['default'];
                } else {
                    foreach ($contexts as $candidate) {
                        if (is_array($candidate)) {
                            $defaultContext = $candidate;
                            break;
                        }
                    }
                }
            }

            $connection = $defaultContext['connection'] ?? ($config['connection'] ?? null);
            $excludeTables = $defaultContext['exclude_tables'] ?? ($config['exclude_tables'] ?? []);

            if (is_string($excludeTables)) {
                $excludeTables = array_map('trim', explode(',', $excludeTables));
            }

            if (!is_array($excludeTables)) {
                $excludeTables = [];
            }

            $excludeTables = array_values(array_filter(
                $excludeTables,
                fn ($value) => $value !== null && $value !== ''
            ));

            return new SchemaMetadataLoader(
                $app['db'],
                $connection,
                $excludeTables
            );
        });

        $this->app->singleton(LaraGrepQueryService::class, function ($app) {
            $config = $app['config']->get('laragrep', []);

            $conversationConfig = $config['conversation'] ?? [];
            $conversationStore = null;

            if (is_array($conversationConfig) && ($conversationConfig['enabled'] ?? true)) {
                $connectionName = $conversationConfig['connection'] ?? null;
                $table = (string) ($conversationConfig['table'] ?? 'laragrep_conversations');
                $maxMessages = (int) ($conversationConfig['max_messages'] ?? 10);
                $ttlDays = (int) ($conversationConfig['ttl_days'] ?? 10);

                $connectionConfig = $this->getConnectionConfig($app, $connectionName);

                if ($connectionConfig !== null && $this->connectionSupportsConversations($app, $connectionConfig)) {
                    $connection = $connectionName
                        ? $app['db']->connection($connectionName)
                        : $app['db']->connection();

                    $conversationStore = new ConversationStore(
                        $connection,
                        $table,
                        $maxMessages,
                        $ttlDays
                    );
                }
            }

            return new LaraGrepQueryService(
                $app->make(SchemaMetadataLoader::class),
                $config,
                $conversationStore
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/laragrep.php' => config_path('laragrep.php'),
        ], 'laragrep-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }


    private function getConnectionConfig($app, ?string $connectionName): ?array
    {
        $configRepository = $app['config'];

        if (is_string($connectionName) && $connectionName !== '') {
            $config = $configRepository->get("database.connections.$connectionName");

            return is_array($config) ? $config : null;
        }

        $defaultConnection = $configRepository->get('database.default');

        if (!is_string($defaultConnection) || $defaultConnection === '') {
            return null;
        }

        $config = $configRepository->get("database.connections.$defaultConnection");

        return is_array($config) ? $config : null;
    }

    private function connectionSupportsConversations($app, array $connectionConfig): bool
    {
        $driver = $connectionConfig['driver'] ?? null;

        if ($driver !== 'sqlite') {
            return true;
        }

        $databasePath = (string) ($connectionConfig['database'] ?? '');

        if ($databasePath === '') {
            return false;
        }

        if ($databasePath === ':memory:') {
            return true;
        }

        $resolvedPath = $this->isAbsolutePath($databasePath)
            ? $databasePath
            : $app->databasePath($databasePath);

        return is_file($resolvedPath);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === DIRECTORY_SEPARATOR) {
            return true;
        }

        return preg_match('#^[A-Za-z]:\\\\#', $path) === 1;
    }
}
