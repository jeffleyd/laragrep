<?php

namespace LaraGrep\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LaraGrep\Metadata\SchemaMetadataLoader;
use LaraGrep\Services\LaraGrepQueryService;
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

        DB::table('users')->insert([
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
        $this->assertStringContainsString("Use the available schema to produce one or more safe SQL SELECT queries that answer the user's question.", $prompt);
        $this->assertStringContainsString('Respond strictly in JSON with the format {"steps": [{"query": "...", "bindings": []}, ...]}', $prompt);
        $this->assertStringContainsString('Database:', $prompt);
        $this->assertStringContainsString('never produce CREATE, INSERT, UPDATE, DELETE, DROP, ALTER, or any other mutating commands', $prompt);
        $this->assertStringContainsString('User language: pt-BR', $prompt);
    }

    public function test_it_executes_raw_query_and_interprets_results()
    {
        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'steps' => [
                                [
                                    'query' => 'select name from users where status = ?',
                                    'bindings' => ['active'],
                                ],
                            ],
                        ]),
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => 'Encontramos 1 usuário ativo: Alice.',
                    ],
                ]],
            ]);

        $service = $this->makeService();
        $response = $service->answerQuestion('Listar usuários ativos');

        $this->assertCount(1, $response['steps']);
        $this->assertSame('select name from users where status = ?', $response['steps'][0]['query']);
        $this->assertSame(['active'], $response['steps'][0]['bindings']);
        $this->assertSame('Alice', $response['steps'][0]['results'][0]['name']);
        $this->assertSame('select name from users where status = ?', $response['query']);
        $this->assertSame(['active'], $response['bindings']);
        $this->assertSame('Alice', $response['results'][0]['name']);
        $this->assertSame('Encontramos 1 usuário ativo: Alice.', $response['summary']);
    }

    public function test_it_includes_executed_queries_when_debug_is_enabled()
    {
        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'steps' => [
                                [
                                    'query' => 'select name from users where status = ?',
                                    'bindings' => ['active'],
                                ],
                            ],
                        ]),
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => 'Encontramos 1 usuário ativo: Alice.',
                    ],
                ]],
            ]);

        $service = $this->makeService();
        $response = $service->answerQuestion('Listar usuários ativos', true);

        $this->assertArrayHasKey('debug', $response);
        $this->assertNotEmpty($response['debug']['queries']);
        $this->assertSame('select name from users where status = ?', $response['debug']['queries'][0]['query']);
        $this->assertSame(['active'], $response['debug']['queries'][0]['bindings']);
    }

    public function test_it_refuses_modification_requests()
    {
        Http::fake();

        $service = $this->makeService();
        $response = $service->answerQuestion('Por favor, atualize o status do usuário para ativo');

        $this->assertSame('Desculpe, não posso criar, atualizar ou deletar dados; só posso ajudar com consultas de leitura.', $response['summary']);
        $this->assertSame([], $response['steps']);

        Http::assertNothingSent();
    }

    public function test_interpretation_messages_request_business_friendly_summary()
    {
        $service = $this->makeService();

        $method = new \ReflectionMethod($service, 'buildInterpretationMessages');
        $method->setAccessible(true);

        $messages = $method->invoke($service, 'Listar usuários ativos', [[
            'query' => 'select name from users where status = ?',
            'bindings' => ['active'],
            'results' => [['name' => 'Alice']],
        ]]);

        $this->assertNotEmpty($messages);

        $lastMessage = $messages[array_key_last($messages)];

        $this->assertSame('user', $lastMessage['role']);
        $this->assertStringContainsString('business-oriented summary', $lastMessage['content']);
        $this->assertStringContainsString('only reports the requested result', $lastMessage['content']);
        $this->assertStringContainsString('Do not mention SQL, queries, bindings, code, or technical terms.', $lastMessage['content']);
        $this->assertStringContainsString('Executed queries (JSON): ', $lastMessage['content']);
        $this->assertStringContainsString('in pt-BR', $lastMessage['content']);
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
