<?php

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Bus\PendingClosureDispatch;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

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

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);

    expect(cache()->get("{$key}:tts"))->toBeTrue();
    expect($valueFromCache)->toBe($value);
});

it('returns stale value from cache and updates after request', function () {
    $originalValue = 'original value';

    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $originalValue);

    travelTo(now()->addSeconds($tts)->addSecond());

    Event::fake();

    $newValue = 'new value';

    $staleValue = cache()->swr($key, $ttl, $tts, fn () => $newValue);

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);

    expect($staleValue)->toBe($originalValue);

    app()->terminate();

    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === "{$key}:tts");
    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === "{$key}:revalidating");
    Event::assertDispatched(KeyWritten::class, fn (KeyWritten $event) => $event->key === "{$key}:revalidating");

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
    Event::assertDispatched(
        KeyForgotten::class,
        fn (KeyForgotten $event) => $event->key === "{$key}:revalidating"
    );

    expect(cache()->get("{$key}:tts"))->toBeTrue();
    expect(cache()->get($key))->toBe($newValue);
});

it('returns stale value from cache and queues update', function () {
    $originalValue = 'original value';

    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $originalValue);

    travelTo(now()->addSeconds($tts)->addSecond());

    Event::fake();
    Queue::fake();

    $newValue = 'new value';

    $staleValue = cache()->swr($key, $ttl, $tts, fn () => $newValue, queue: true);

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);

    expect($staleValue)->toBe($originalValue);

    app()->terminate();

    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === "{$key}:tts");
    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === "{$key}:revalidating");
    Event::assertDispatched(KeyWritten::class, fn (KeyWritten $event) => $event->key === "{$key}:revalidating");

    Queue::assertPushed(CallQueuedClosure::class, function ($job) {
        app()->call([$job, 'handle']);

        return true;
    });

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
    Event::assertDispatched(
        KeyForgotten::class,
        fn (KeyForgotten $event) => $event->key === "{$key}:revalidating"
    );

    expect(cache()->get("{$key}:tts"))->toBeTrue();
    expect(cache()->get($key))->toBe($newValue);
});

it('returns stale value from cache and (custom) queues update', function () {
    $originalValue = 'original value';

    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $originalValue);

    travelTo(now()->addSeconds($tts)->addSecond());

    Event::fake();
    Queue::fake();

    $newValue = 'new value';

    $staleValue = cache()->swr($key, $ttl, $tts, fn () => $newValue, queue: function (PendingClosureDispatch $job) {
        $job->onQueue('custom-queue');
    });

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);

    expect($staleValue)->toBe($originalValue);

    app()->terminate();

    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === "{$key}:tts");
    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === "{$key}:revalidating");
    Event::assertDispatched(KeyWritten::class, fn (KeyWritten $event) => $event->key === "{$key}:revalidating");

    Queue::assertPushedOn('custom-queue', CallQueuedClosure::class, function ($job) {
        app()->call([$job, 'handle']);

        return true;
    });

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
    Event::assertDispatched(
        KeyForgotten::class,
        fn (KeyForgotten $event) => $event->key === "{$key}:revalidating"
    );

    expect(cache()->get("{$key}:tts"))->toBeTrue();
    expect(cache()->get($key))->toBe($newValue);
});
