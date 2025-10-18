<?php

namespace LaraGrep\Tests\Unit;

use Illuminate\Support\Facades\Http;
use LaraGrep\Metadata\SchemaMetadataLoader;
use LaraGrep\Services\LaraGrepQueryService;
use LaraGrep\Tests\Support\Models\User;
use LaraGrep\Tests\TestCase;

class LaraGrepQueryServiceTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('status');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        User::query()->insert([
            ['name' => 'Alice', 'status' => 'active'],
            ['name' => 'Bob', 'status' => 'inactive'],
        ]);
    }

    public function test_build_prompt_contains_schema_information()
    {
        $service = $this->makeService();
        $prompt = $service->buildPrompt('Listar usuários ativos');

        $this->assertStringContainsString('Table users', $prompt);
        $this->assertStringContainsString('status', $prompt);
        $this->assertStringContainsString('Listar usuários ativos', $prompt);
    }

    public function test_build_prompt_includes_model_when_configured()
    {
        $service = $this->makeService([
            'metadata' => [
                [
                    'name' => 'users',
                    'model' => User::class,
                    'description' => 'Registered users',
                    'columns' => [
                        ['name' => 'id', 'type' => 'int', 'description' => 'Primary key'],
                    ],
                ],
            ],
        ], []);

        $prompt = $service->buildPrompt('Listar usuários ativos');

        $this->assertStringContainsString('Model: ' . User::class, $prompt);
    }

    public function test_it_executes_eloquent_steps_from_model_response()
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'steps' => [[
                                'type' => 'eloquent',
                                'model' => User::class,
                                'operations' => [
                                    ['method' => 'where', 'arguments' => ['status', '=', 'active']],
                                ],
                                'columns' => ['name'],
                            ]],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $service = $this->makeService();
        $response = $service->answerQuestion('Listar usuários ativos');

        $this->assertSame('Alice', $response['results'][0][0]['name']);
    }

    public function test_it_resolves_model_from_metadata_name()
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'steps' => [[
                                'type' => 'eloquent',
                                'model' => 'users',
                                'operations' => [
                                    ['method' => 'where', 'arguments' => ['status', '=', 'active']],
                                ],
                                'columns' => ['name'],
                            ]],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $service = $this->makeService([
            'metadata' => [
                [
                    'name' => 'users',
                    'model' => User::class,
                    'description' => 'Registered users',
                    'columns' => [
                        ['name' => 'id', 'type' => 'int', 'description' => 'Primary key'],
                        ['name' => 'status', 'type' => 'varchar', 'description' => 'User status'],
                    ],
                ],
            ],
        ], []);

        $response = $service->answerQuestion('Listar usuários ativos');

        $this->assertSame('Alice', $response['results'][0][0]['name']);
    }

    public function test_it_falls_back_to_query_builder_when_model_is_not_configured()
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'steps' => [[
                                'type' => 'eloquent',
                                'model' => 'users',
                                'operations' => [
                                    ['method' => 'where', 'arguments' => ['status', '=', 'active']],
                                ],
                                'columns' => ['name'],
                            ]],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $service = $this->makeService();
        $response = $service->answerQuestion('Listar usuários ativos');

        $this->assertSame('Alice', $response['results'][0][0]['name']);
    }

    public function test_it_supports_safe_raw_queries()
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'steps' => [[
                                'type' => 'raw',
                                'query' => 'select name from users where status = ?',
                                'bindings' => ['active'],
                            ]],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $service = $this->makeService();
        $response = $service->answerQuestion('Listar usuários ativos');

        $this->assertSame('Alice', $response['results'][0][0]['name']);
    }

    public function test_it_includes_executed_queries_when_debug_is_enabled()
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'steps' => [[
                                'type' => 'raw',
                                'query' => 'select name from users where status = ?',
                                'bindings' => ['active'],
                            ]],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $service = $this->makeService();
        $response = $service->answerQuestion('Listar usuários ativos', true);

        $this->assertArrayHasKey('debug', $response);
        $this->assertNotEmpty($response['debug']['queries']);
        $this->assertSame('select name from users where status = ?', $response['debug']['queries'][0]['query']);
        $this->assertSame(['active'], $response['debug']['queries'][0]['bindings']);
    }

    protected function makeService(array $configOverrides = [], ?array $loaderMetadata = null): LaraGrepQueryService
    {
        $loader = $this->createMock(SchemaMetadataLoader::class);
        $loader->method('load')->willReturn($loaderMetadata ?? [
            [
                'name' => 'users',
                'description' => 'Registered users',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'description' => 'Primary key'],
                    ['name' => 'status', 'type' => 'varchar', 'description' => 'User status'],
                ],
            ],
        ]);

        $config = $this->app['config']->get('laragrep');

        if (!is_array($config)) {
            $config = [];
        }

        $config = array_replace($config, $configOverrides);

        return new LaraGrepQueryService($loader, $config);
    }
}
