<?php

namespace LaraGrep\Tests;

use LaraGrep\LaraGrepServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [LaraGrepServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('laragrep.api_key', 'test-key');
        $app['config']->set('laragrep.base_url', 'https://api.openai.com/v1/chat/completions');
        $app['config']->set('laragrep.model', 'gpt-3.5-turbo');
        $app['config']->set('laragrep.route.middleware', []);
        $app['config']->set('laragrep.debug', false);
    }
}
