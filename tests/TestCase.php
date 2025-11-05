<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Contracts\Console\Kernel;

abstract class TestCase extends BaseTestCase
{
    use DatabaseMigrationsForPhp84;

    protected function setUpTraits()
    {
        $uses = array_flip(class_uses_recursive(static::class));

        if (version_compare(PHP_VERSION, '8.4.0', '>=') &&
            config('database.default') === 'sqlite') {

            if (isset($uses[RefreshDatabase::class])) {
                unset($uses[RefreshDatabase::class]);
                $uses[DatabaseMigrationsForPhp84::class] = DatabaseMigrationsForPhp84::class;
            }
        }

        $uses = array_flip($uses);

        foreach ($uses as $trait) {
            if (method_exists($this, $method = 'setUp'.class_basename($trait))) {
                $this->{$method}();
            }

            if (method_exists($this, $method = 'tearDown'.class_basename($trait))) {
                $this->beforeApplicationDestroyed(fn () => $this->{$method}());
            }
        }

        return $uses;
    }

    public function beginDatabaseTransaction()
    {
        if (version_compare(PHP_VERSION, '8.4.0', '>=') &&
            config('database.default') === 'sqlite') {
            return;
        }

        $database = $this->app->make('db');

        foreach ($this->connectionsToTransact() as $name) {
            $connection = $database->connection($name);
            $dispatcher = $connection->getEventDispatcher();

            $connection->unsetEventDispatcher();
            $connection->beginTransaction();
            $connection->setEventDispatcher($dispatcher);
        }

        $this->beforeApplicationDestroyed(function () use ($database) {
            foreach ($this->connectionsToTransact() as $name) {
                $connection = $database->connection($name);
                $dispatcher = $connection->getEventDispatcher();

                $connection->unsetEventDispatcher();
                $connection->rollBack();
                $connection->setEventDispatcher($dispatcher);
                $connection->disconnect();
            }
        });
    }
}

trait DatabaseMigrationsForPhp84
{
    public function setUpDatabaseMigrationsForPhp84()
    {
        $this->artisan('migrate:fresh', [
            '--drop-views' => false,
            '--drop-types' => false,
        ]);

        $this->app[Kernel::class]->setArtisan(null);
    }
}
