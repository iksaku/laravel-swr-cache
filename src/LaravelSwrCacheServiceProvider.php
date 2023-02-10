<?php

namespace Iksaku\LaravelSwrCache;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Support\ServiceProvider;
use UnexpectedValueException;

class LaravelSwrCacheServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Repository::macro('swr', function (string $key, mixed $tts, mixed $ttl, Closure $callback) {
            /** @var Repository $this */

            if ($this->getSeconds($tts) >= $this->getSeconds($ttl)) {
                throw new UnexpectedValueException('The time-to-stale value must be less than the time-to-live value.');
            }

            // To ease the maintenance of the code, we assign a new 'time-to-stale' key
            // which will be used to check if the value in cache is fresh or stale.
            $ttsKey = "{$key}:tts";

            // Set the value in cache if it doesn't exist.
            if (! $this->has($key)) {
                $this->put($ttsKey, true, $tts);

                return $this->remember($key, $ttl, $callback);
            }

            // If value in cache is stale, let's queue update after application lifecycle ends.
            if (! $this->has($ttsKey)) {
                app()->terminating(function () use ($key, $ttsKey, $tts, $ttl, $callback) {
                    /** @var Repository $this */
                    $this->forget($key);

                    $this->put($ttsKey, true, $tts);

                    $this->remember($key, $ttl, $callback);
                });
            }

            return $this->get($key);
        });
    }
}
