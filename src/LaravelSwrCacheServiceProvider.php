<?php

namespace Iksaku\LaravelSwrCache;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Support\ServiceProvider;
use UnexpectedValueException;

class LaravelSwrCacheServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Repository::macro('swr', function (string $key, mixed $ttl, mixed $tts, Closure $callback, bool|Closure $queue = false) {
            /** @var Repository $this */
            if ($this->getSeconds($tts) >= $this->getSeconds($ttl)) {
                throw new UnexpectedValueException('The time-to-stale value must be less than the time-to-live value.');
            }

            $ttsKey = "{$key}:tts";
            $revalidatingKey = "{$key}:revalidating";

            // Re-implement the logic of the `remember()` method to avoid the overhead of
            // calling `has()` twice on the same key, as well as the need to `forget()`
            // the key before the value is ready to be set in cache.
            $evaluateAndStore = function () use ($callback, $key, $ttl, $ttsKey, $tts, $revalidatingKey) {
                /** @var Repository $this */
                try {
                    $value = $callback();

                    $this->put($key, $value, value($ttl, $value));
                    $this->put($ttsKey, true, value($tts, true));

                    return $value;
                } finally {
                    $this->forget($revalidatingKey);
                }
            };

            // Set the value in cache if key doesn't exist.
            if ($this->missing($key)) {
                return $evaluateAndStore();
            }

            // After the application has finished handling the request, verify that the
            // value in cache is still fresh. If not, re-evaluate the callback and
            // store the new value in cache for the next request.
            app()->terminating(function () use ($queue, $ttsKey, $revalidatingKey, $evaluateAndStore) {
                /** @var Repository $this */
                if ($this->has($ttsKey) || $this->has($revalidatingKey)) {
                    return;
                }

                $this->put($revalidatingKey, true);

                if (! $queue) {
                    $evaluateAndStore();
                } else {
                    $queued = dispatch($evaluateAndStore);

                    if ($queue instanceof Closure) {
                        $queue($queued);
                    }
                }
            });

            // Return the (possibly stale) value from cache.
            return $this->get($key);
        });
    }
}
