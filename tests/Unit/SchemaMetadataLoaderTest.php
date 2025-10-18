<?php

namespace LaraGrep\Tests\Unit;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use LaraGrep\Metadata\SchemaMetadataLoader;
use Mockery;
use Orchestra\Testbench\TestCase;

class SchemaMetadataLoaderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_parses_table_and_column_descriptions(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getDatabaseName')->andReturn('testing');
        $connection->shouldReceive('select')
            ->with(
                'SELECT TABLE_NAME, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
                ['testing']
            )
            ->andReturn([
                (object) ['TABLE_NAME' => 'users', 'TABLE_COMMENT' => 'Registered application users'],
            ]);

        $connection->shouldReceive('select')
            ->with(
                'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
                ['testing']
            )
            ->andReturn([
                (object) ['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'int', 'COLUMN_COMMENT' => 'Primary key'],
                (object) ['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'email', 'COLUMN_TYPE' => 'varchar', 'COLUMN_COMMENT' => 'Unique email'],
            ]);

        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);

        $loader = new SchemaMetadataLoader($resolver);

        $metadata = $loader->load();

        $this->assertCount(1, $metadata);
        $this->assertSame('users', $metadata[0]['name']);
        $this->assertSame('Registered application users', $metadata[0]['description']);
        $this->assertCount(2, $metadata[0]['columns']);
        $this->assertSame('Primary key', $metadata[0]['columns'][0]['description']);
    }

    public function test_it_excludes_configured_tables(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getDatabaseName')->andReturn('testing');
        $connection->shouldReceive('select')
            ->with(
                'SELECT TABLE_NAME, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
                ['testing']
            )
            ->andReturn([
                (object) ['TABLE_NAME' => 'users', 'TABLE_COMMENT' => 'Registered application users'],
                (object) ['TABLE_NAME' => 'migrations', 'TABLE_COMMENT' => 'System table'],
            ]);

        $connection->shouldReceive('select')
            ->with(
                'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
                ['testing']
            )
            ->andReturn([
                (object) ['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'int', 'COLUMN_COMMENT' => 'Primary key'],
                (object) ['TABLE_NAME' => 'migrations', 'COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'int', 'COLUMN_COMMENT' => 'Primary key'],
            ]);

        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);

        $loader = new SchemaMetadataLoader($resolver, excludeTables: ['migrations']);

        $metadata = $loader->load();

        $this->assertCount(1, $metadata);
        $this->assertSame('users', $metadata[0]['name']);
    }
}
