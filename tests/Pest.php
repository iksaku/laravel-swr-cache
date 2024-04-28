<?php

use Iksaku\LaravelSwrCache\SwrKeyGenerator;
use Iksaku\LaravelSwrCache\Tests\TestCase;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

uses(TestCase::class)->in(__DIR__);

function simulateRequestTermination(): void
{
    app()->terminate();

    // Flush callbacks
    invade(app())->terminatingCallbacks = [];
}

function assertTerminatingCallbacksToBe(int $count): void
{
    $callbacks = array_filter(
        invade(app())->terminatingCallbacks,
        function (mixed $callback) {
            if (! ($callback instanceof \Closure)) return false;

            $reflection = new ReflectionFunction($callback);

            return $reflection->getClosureScopeClass()->getName() === \Illuminate\Cache\Repository::class;
        }
    );

    expect($callbacks)
        ->toBeArray()
        ->toHaveCount($count);
}

function assertGotSwrAtomicLock(string $key): void
{
    $lock = cache()->lock(SwrKeyGenerator::atomicLock($key));

    assertFalse(
        $lock->get(),
        "Atomic lock for [{$key}] is free"
    );
}

function assertFreeSwrAtomicLock(string $key): void
{
    $lock = cache()->lock(SwrKeyGenerator::atomicLock($key));

    assertTrue(
        $lock->get(),
        "Atomic lock for [{$key}] is locked"
    );

    $lock->release();
}
