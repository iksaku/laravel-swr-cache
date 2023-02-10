<?php

namespace Iksaku\LaravelSwrCache\Tests;

use Iksaku\LaravelSwrCache\LaravelSwrCacheServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            LaravelSwrCacheServiceProvider::class,
        ];
    }
}
