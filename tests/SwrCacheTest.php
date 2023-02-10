<?php

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Event;
use function Pest\Laravel\travelTo;

test('swr macro is registered', function () {
    expect(cache()->hasMacro('swr'))->toBeTrue();
});

it('throws an exception if time-to-stale is greater or equal than time-to-live', function (mixed $ttl, mixed $tts) {
    cache()->swr('key', $ttl, $tts, fn () => 'value');
})
    ->throws(UnexpectedValueException::class, 'The time-to-stale value must be less than the time-to-live value.')
    ->with([
        ['ttl' => 5, 'tts' => 10],
        ['ttl' => 10, 'tts' => 10],
    ]);

it('sets the value in cache if it does not exist', function () {
    Event::fake();

    $value = 'value';

    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $value);

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === "{$key}:tts"
            && $event->value === true
            && $event->seconds === $tts
    );

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $key
            && $event->value === $value
            && $event->seconds === $ttl
    );

    expect(cache()->get("{$key}:tts"))->toBeTrue();
    expect(cache()->get($key))->toBe($value);
});

it('overwrites tts key if value is not in cache', function () {
    $value = 'value';

    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $value);
    cache()->forget($key);

    expect(cache()->has("{$key}:tts"))->toBeTrue()
        ->and(cache()->has($key))->toBeFalse();

    Event::fake();

    cache()->swr($key, $ttl, $tts, fn () => $value);

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === "{$key}:tts"
            && $event->value === true
            && $event->seconds === $tts
    );

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $key
            && $event->value === $value
            && $event->seconds === $ttl
    );

    expect(cache()->get("{$key}:tts"))->toBeTrue();
    expect(cache()->get($key))->toBe($value);
});

it('returns the value in cache if it is fresh', function () {
    $value = 'value';

    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $value);

    Event::fake();

    $valueFromCache = cache()->swr($key, $ttl, $tts, fn () => $value);

    Event::assertNotDispatched(CacheMissed::class);

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === "{$key}:tts");
    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);

    expect(cache()->get("{$key}:tts"))->toBeTrue();
    expect($valueFromCache)->toBe($value);
});

it('returns stale value from cache and queues update', function () {
    $originalValue = 'original value';

    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $originalValue);

    travelTo(now()->addSeconds($tts)->addSecond());

    Event::fake();

    $newValue = 'new value';

    $staleValue = cache()->swr($key, $ttl, $tts, fn () => $newValue);

    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === "{$key}:tts");
    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);

    expect($staleValue)->toBe($originalValue);

    app()->terminate();

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === "{$key}:tts"
            && $event->value === true
            && $event->seconds === $tts
    );
    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $key
            && $event->value === $newValue
            && $event->seconds === $ttl
    );

    expect(cache()->get("{$key}:tts"))->toBeTrue();
    expect(cache()->get($key))->toBe($newValue);
});
