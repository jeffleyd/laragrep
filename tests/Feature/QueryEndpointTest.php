<?php

namespace LaraGrep\Tests\Feature;

use Illuminate\Support\Facades\Http;
use LaraGrep\Metadata\SchemaMetadataLoader;
use LaraGrep\Tests\Support\Models\User;
use LaraGrep\Tests\TestCase;

class QueryEndpointTest extends TestCase
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

        User::query()->create(['name' => 'Alice', 'status' => 'active']);
    }

    public function test_it_returns_json_response_from_service()
    {
        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'steps' => [[
                                'query' => 'select name from users where status = ?',
                                'bindings' => ['active'],
                            ]],
                        ]),
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => 'Usuários ativos: Alice.',
                    ],
                ]],
            ]);

        $this->app->instance(SchemaMetadataLoader::class, new class extends SchemaMetadataLoader {
            public function __construct()
            {
            }

            public function load(): array
            {
                return [[
                    'name' => 'users',
                    'description' => '',
                    'columns' => [],
                ]];
            }
        });

        $response = $this->postJson('/laragrep', ['question' => 'List users']);

        $response->assertStatus(200);
        $response->assertExactJson([
            'summary' => 'Usuários ativos: Alice.',
        ]);
    }

    public function test_it_returns_query_log_when_debug_flag_is_true()
    {
        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'steps' => [[
                                'query' => 'select name from users where status = ?',
                                'bindings' => ['active'],
                            ]],
                        ]),
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => 'Usuários ativos: Alice.',
                    ],
                ]],
            ]);

        $this->app->instance(SchemaMetadataLoader::class, new class extends SchemaMetadataLoader {
            public function __construct()
            {
            }

            public function load(): array
            {
                return [[
                    'name' => 'users',
                    'description' => '',
                    'columns' => [],
                ]];
            }
        });

        $response = $this->postJson('/laragrep', ['question' => 'List users', 'debug' => true]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['summary', 'steps', 'results', 'debug' => ['queries']]);
        $this->assertArrayNotHasKey('query', $response->json());
        $this->assertSame('select name from users where status = ?', $response->json('debug.queries.0.query'));
    }
}
