<?php

namespace Iksaku\LaravelSwrCache;

use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Cache\NoLock;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use UnexpectedValueException;

class LaravelSwrCacheServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Repository::macro(
            'swr',
            /**
             * Retrieve an item from cache by key.
             * After the time-to-stale has passed, the value in cache is considered
             * "stale" but will still be served while a fresh value is obtained
             * in the background.
             */
            function (string $key, mixed $ttl, mixed $tts, Closure $callback, bool|Closure $queue = false): mixed {
                /** @var Repository $this */
                $store = $this->getStore();

                if (! ($store instanceof LockProvider) || $store instanceof NullStore) {
                    throw new RuntimeException('This cache driver does not support Atomic Locks.');
                }

                // @phpstan-ignore-next-line
                if ($this->getSeconds($tts) >= $this->getSeconds($ttl)) {
                    throw new UnexpectedValueException('The time-to-stale value must be less than the time-to-live value.');
                }

                $ttsKey = SwrKeyGenerator::timeToStale($key);

                /** @var Lock $lock */
                $lock = $store->lock($lockName = SwrKeyGenerator::atomicLock($key));
                $lockOwner = $lock->owner();

                if ($lock instanceof NoLock) {
                    throw new RuntimeException('Unexpected [NoLock] instance received from cache driver.');
                }

                $evaluateAndStore = static function () use ($callback, $key, $ttl, $ttsKey, $tts, $lockName, $lockOwner) {
                    /** @var Lock $lock */
                    $lock = cache()->restoreLock($lockName, $lockOwner);
                    $weOwnTheLock = $lock->isOwnedByCurrentProcess();

                    try {
                        $value = $callback();

                        // Minimize trips to our cache store
                        if ($weOwnTheLock) {
                            cache()->put($key, $value, value($ttl, $value));
                            cache()->put($ttsKey, true, value($tts, true));
                        }

                        return $value;
                    } finally {
                        // Again, trying to minimize trips to cache store
                        if ($weOwnTheLock) {
                            $lock->forceRelease();
                        }
                    }
                };

                // Ensure that only one process owns the lock.
                // This will help in any of these situations:
                //   1. Value is missing, so we force callback evaluation but only
                //      send once to the cache store, minimizing load on the
                //      cache store in highly concurrent environments.
                //   2. Ensure freshness check is only executed once, independently
                //      of how many times this function was called in the current
                //      application lifecycle, and even prevents execution
                //      overlap between multiple requests and multiple servers.
                $weOwnTheLock = $lock->get();

                // Force a value for the current function lifecycle.
                if ($this->missing($key)) {
                    return $evaluateAndStore();
                }

                if ($weOwnTheLock) {
                    if ($this->has($ttsKey)) {
                        $lock->release();
                    } else {
                        // Value is now "stale". We will re-evaluate the callback after
                        // the application has finished handling the request and
                        // store the new value in cache for the next request.
                        app()->terminating(function () use ($evaluateAndStore, $queue) {
                            if (! $queue) {
                                $evaluateAndStore();

                                return;
                            }

                            $job = dispatch($evaluateAndStore);

                            // TODO: Quick queue selection if param is string

                            if ($queue instanceof Closure) {
                                $queue($job);
                            }
                        });
                    }
                }

                // Return the (possibly stale) value from cache.
                return $this->get($key);
            });
    }
}
