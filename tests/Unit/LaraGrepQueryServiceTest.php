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

    protected function makeService(): LaraGrepQueryService
    {
        $loader = $this->createMock(SchemaMetadataLoader::class);
        $loader->method('load')->willReturn([
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

        return new LaraGrepQueryService($loader, $config);
    }
}
