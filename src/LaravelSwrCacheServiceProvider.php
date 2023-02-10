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
        Repository::macro('swr', function (string $key, mixed $ttl, mixed $tts, Closure $callback) {
            /** @var Repository $this */
            if ($this->getSeconds($tts) >= $this->getSeconds($ttl)) {
                throw new UnexpectedValueException('The time-to-stale value must be less than the time-to-live value.');
            }

            $ttsKey = "{$key}:tts";
            $revalidatingKey = "{$key}:revalidating";

            // Re-implement the logic of the `remember()` method to avoid the overhead of
            // calling `has()` twice on the same key, as well as the need to `forget()`
            // the key before the value is ready to be set in cache.
            $remember = fn () => tap($callback(), function (mixed $value) use ($key, $ttl, $ttsKey, $tts) {
                /** @var Repository $this */
                $this->put($key, $value, value($ttl, $value));
                $this->put($ttsKey, true, value($tts, true));
            });

            // Set the value in cache if key doesn't exist.
            if ($this->missing($key)) {
                return $remember();
            }

            // If value in cache is stale, queue an update after application lifecycle ends.
            // TODO: Need a good way to test queue deduplication.
            if ($this->missing($ttsKey) && $this->missing($revalidatingKey)) {
                $this->put($revalidatingKey, true);

                app()->terminating(function () use ($revalidatingKey, $remember) {
                    /** @var Repository $this */
                    $remember();
                    $this->forget($revalidatingKey);
                });
            }

            return $this->get($key);
        });
    }
}
