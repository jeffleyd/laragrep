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
        $this->assertStringContainsString('Responda estritamente em JSON', $prompt);
        $this->assertStringContainsString('Banco de dados:', $prompt);
        $this->assertStringContainsString('jamais execute comandos CREATE, INSERT, UPDATE, DELETE, DROP ou ALTER', $prompt);
    }

    public function test_it_executes_raw_query_and_interprets_results()
    {
        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'query' => 'select name from users where status = ?',
                            'bindings' => ['active'],
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
                            'query' => 'select name from users where status = ?',
                            'bindings' => ['active'],
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

    public function test_interpretation_messages_request_business_friendly_summary()
    {
        $service = $this->makeService();

        $method = new \ReflectionMethod($service, 'buildInterpretationMessages');
        $method->setAccessible(true);

        $messages = $method->invoke($service, 'Listar usuários ativos', 'select name from users where status = ?', ['active'], [['name' => 'Alice']]);

        $this->assertNotEmpty($messages);

        $lastMessage = $messages[array_key_last($messages)];

        $this->assertSame('user', $lastMessage['role']);
        $this->assertStringContainsString('voltada para o negócio', $lastMessage['content']);
        $this->assertStringContainsString('apenas informando o resultado solicitado, sem explicar o que ele significa', $lastMessage['content']);
        $this->assertStringContainsString('Não mencione SQL, consultas, queries, bindings, código ou termos técnicos.', $lastMessage['content']);
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
