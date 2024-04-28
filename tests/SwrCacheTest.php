<?php

use Iksaku\LaravelSwrCache\SwrKeyGenerator;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Bus\PendingClosureDispatch;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\travelTo;

it('sets the value in cache if it does not exist', function () {
    Event::fake();

    $value = 'value';

    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $value);

    assertFreeSwrAtomicLock($key);

    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === $key);
    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $key
            && $event->value === $value
            && $event->seconds === $ttl
    );
    expect(cache()->get($key))->toBe($value);

    $ttsKey = SwrKeyGenerator::timeToStale($key);

    // It should completely ignore Time-To-Stale checks and directly write it
    Event::assertNotDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $ttsKey);
    Event::assertNotDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === $ttsKey);
    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $ttsKey
            && $event->value === true
            && $event->seconds === $tts
    );
    expect(cache()->get($ttsKey))->toBeTrue();
});

it('ignores time-to-stale key if value is not in cache', function () {
    $value = 'value';

    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $value);

    assertFreeSwrAtomicLock($key);

    $ttsKey = SwrKeyGenerator::timeToStale($key);

    cache()->forget($key);

    expect(cache())
        ->has($ttsKey)->toBeTrue()
        ->has($key)->toBeFalse();

    Event::fake();

    cache()->swr($key, $ttl, $tts, fn () => $value);

    assertFreeSwrAtomicLock($key);

    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === $key);
    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $key
            && $event->value === $value
            && $event->seconds === $ttl
    );
    expect(cache()->get($key))->toBe($value);

    // It should completely ignore Time-To-Stale checks and directly overwrite it
    Event::assertNotDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $ttsKey);
    Event::assertNotDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === $ttsKey);
    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $ttsKey
            && $event->value === true
            && $event->seconds === $tts
    );
    expect(cache()->get($ttsKey))->toBeTrue();
});

it('returns the value in cache if it is fresh', function () {
    $value = 'value';

    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $value);

    assertFreeSwrAtomicLock($key);

    travelTo(now()->addSeconds($tts - 1));

    Event::fake();

    $valueFromCache = cache()->swr($key, $ttl, $tts, fn () => 'new value');

    assertFreeSwrAtomicLock($key);

    Event::assertNotDispatched(CacheMissed::class);
    Event::assertNotDispatched(KeyWritten::class);

    $ttsKey = SwrKeyGenerator::timeToStale($key);

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);
    expect($valueFromCache)->toBe($value);

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $ttsKey);
    expect(cache()->get($ttsKey))->toBeTrue();
});

it('returns stale value from cache and updates after request', function () {
    $originalValue = 'original value';

    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $originalValue);

    assertFreeSwrAtomicLock($key);

    travelTo(now()->addSeconds($tts + 1));

    Event::fake();

    $newValue = 'new value';

    $staleValue = cache()->swr($key, $ttl, $tts, fn () => $newValue);

    assertGotSwrAtomicLock($key);

    $ttsKey = SwrKeyGenerator::timeToStale($key);

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);
    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === $ttsKey);

    Event::assertNotDispatched(KeyWritten::class);

    expect($staleValue)->toBe($originalValue);

    Event::fake(); // Flush

    simulateRequestTermination();

    assertFreeSwrAtomicLock($key);

    Event::assertNotDispatched(CacheHit::class);
    Event::assertNotDispatched(CacheMissed::class);

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $key
            && $event->value === $newValue
            && $event->seconds === $ttl
    );
    expect(cache()->get($key))->toBe($newValue);

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $ttsKey
            && $event->value === true
            && $event->seconds === $tts
    );
    expect(cache()->get($ttsKey))->toBeTrue();
});

it('returns stale value from cache and queues update', function () {
    $originalValue = 'original value';

    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $originalValue);

    assertFreeSwrAtomicLock($key);

    travelTo(now()->addSeconds($tts)->addSecond());

    Event::fake();
    Queue::fake();

    $newValue = 'new value';

    $staleValue = cache()->swr($key, $ttl, $tts, fn () => $newValue, queue: true);

    expect($staleValue)->toBe($originalValue);

    assertGotSwrAtomicLock($key);

    $ttsKey = SwrKeyGenerator::timeToStale($key);

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);
    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === $ttsKey);

    Event::assertNotDispatched(KeyWritten::class);

    Queue::assertNothingPushed();

    // Flush
    Event::fake();
    Queue::fake();

    simulateRequestTermination();

    Event::assertNothingDispatched();

    $job = null;

    Queue::assertPushed(CallQueuedClosure::class, function ($j) use (&$job) {
        $job = $j;
        return true;
    });

    Event::fake(); // Flush

    app()->call([$job, 'handle']);

    Event::assertNotDispatched(CacheHit::class);
    Event::assertNotDispatched(CacheMissed::class);

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $key
            && $event->value === $newValue
            && $event->seconds === $ttl
    );
    expect(cache()->get($key))->toBe($newValue);

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $ttsKey
            && $event->value === true
            && $event->seconds === $tts
    );
    expect(cache()->get($ttsKey))->toBeTrue();
});

it('returns stale value from cache and (custom) queues update', function () {
    $originalValue = 'original value';

    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $originalValue);

    assertFreeSwrAtomicLock($key);

    travelTo(now()->addSeconds($tts)->addSecond());

    Event::fake();
    Queue::fake();

    $newValue = 'new value';
    $customQueue = 'custom-queue';

    $staleValue = cache()->swr($key, $ttl, $tts, fn () => $newValue, queue: fn(PendingClosureDispatch $job) => $job->onQueue($customQueue));

    expect($staleValue)->toBe($originalValue);

    assertGotSwrAtomicLock($key);

    $ttsKey = SwrKeyGenerator::timeToStale($key);

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);
    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === $ttsKey);

    Event::assertNotDispatched(KeyWritten::class);

    Queue::assertNothingPushed();

    // Flush
    Event::fake();
    Queue::fake();

    simulateRequestTermination();

    Event::assertNothingDispatched();

    $job = null;

    Queue::assertPushedOn($customQueue, CallQueuedClosure::class, function ($j) use (&$job) {
        $job = $j;
        return true;
    });

    Event::fake(); // Flush

    app()->call([$job, 'handle']);

    Event::assertNotDispatched(CacheHit::class);
    Event::assertNotDispatched(CacheMissed::class);

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $key
            && $event->value === $newValue
            && $event->seconds === $ttl
    );
    expect(cache()->get($key))->toBe($newValue);

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $ttsKey
            && $event->value === true
            && $event->seconds === $tts
    );
    expect(cache()->get($ttsKey))->toBeTrue();
});

it('prevents request-end checks from overlapping between multiple calls', function () {
    $originalValue = 'value';
    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $originalValue);

    assertFreeSwrAtomicLock($key);

    assertTerminatingCallbacksToBe(0);

    travelTo(now()->addSeconds($tts + 1));

    Event::fake();
    $newValue = 'new value';
    $staleValue = cache()->swr($key, $ttl, $tts, fn () => $newValue);

    expect($staleValue)->toBe($originalValue);

    assertGotSwrAtomicLock($key);

    $ttsKey = SwrKeyGenerator::timeToStale($key);

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);
    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === $ttsKey);
    Event::assertNotDispatched(KeyWritten::class);

    assertTerminatingCallbacksToBe(1);

    Event::fake(); // Flush
    $secondTimeStaleValue = cache()->swr($key, $ttl, $tts, fn () => 'this should be ignored');

    expect($secondTimeStaleValue)->toBe($originalValue);

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);
    Event::assertNotDispatched(CacheMissed::class); // Should ignore Time-To-Stale check
    Event::assertNotDispatched(KeyWritten::class);

    assertTerminatingCallbacksToBe(1);

    Event::fake(); // Flush
    simulateRequestTermination();

    assertFreeSwrAtomicLock($key);

    Event::assertNotDispatched(CacheHit::class);
    Event::assertNotDispatched(CacheMissed::class);

    Event::assertDispatched(KeyWritten::class, 2);

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $key
            && $event->value === $newValue
            && $event->seconds === $ttl
    );
    expect(cache()->get($key))->toBe($newValue);

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $ttsKey
            && $event->value === true
            && $event->seconds === $tts
    );
    expect(cache()->get($ttsKey))->toBeTrue();
});

it('prevents queued checks from overlapping between multiple calls', function () {
    $originalValue = 'value';
    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $originalValue);

    assertFreeSwrAtomicLock($key);

    assertTerminatingCallbacksToBe(0);

    travelTo(now()->addSeconds($tts + 1));

    Event::fake();
    Queue::fake();

    $newValue = 'new value';
    $staleValue = cache()->swr($key, $ttl, $tts, fn () => $newValue, queue: true);

    expect($staleValue)->toBe($originalValue);

    assertGotSwrAtomicLock($key);

    $ttsKey = SwrKeyGenerator::timeToStale($key);

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);
    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === $ttsKey);
    Event::assertNotDispatched(KeyWritten::class);

    Queue::assertNothingPushed();

    assertTerminatingCallbacksToBe(1);

    // Flush
    Event::fake();
    Queue::fake();

    $secondTimeStaleValue = cache()->swr($key, $ttl, $tts, fn () => 'this should be ignored', queue: true);

    expect($secondTimeStaleValue)->toBe($originalValue);

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);
    Event::assertNotDispatched(CacheMissed::class); // Should ignore Time-To-Stale check
    Event::assertNotDispatched(KeyWritten::class);

    Queue::assertNothingPushed();

    assertTerminatingCallbacksToBe(1);

    // Flush
    Event::fake();
    Queue::fake();

    simulateRequestTermination();

    Event::assertNothingDispatched();

    $job = null;

    Queue::assertPushed(CallQueuedClosure::class, 1);
    Queue::assertPushed( CallQueuedClosure::class, function ($j) use (&$job) {
        $job = $j;
        return true;
    });

    Event::fake(); // Flush

    app()->call([$job, 'handle']);

    assertFreeSwrAtomicLock($key);

    Event::assertNotDispatched(CacheHit::class);
    Event::assertNotDispatched(CacheMissed::class);

    Event::assertDispatched(KeyWritten::class, 2);

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $key
            && $event->value === $newValue
            && $event->seconds === $ttl
    );
    expect(cache()->get($key))->toBe($newValue);

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $ttsKey
            && $event->value === true
            && $event->seconds === $tts
    );
    expect(cache()->get($ttsKey))->toBeTrue();
});

it('prevents queued checks in custom queue from overlapping between multiple calls', function () {
    $originalValue = 'value';
    cache()->swr($key = 'key', $ttl = 20, $tts = 10, fn () => $originalValue);

    assertFreeSwrAtomicLock($key);

    assertTerminatingCallbacksToBe(0);

    travelTo(now()->addSeconds($tts + 1));

    Event::fake();
    Queue::fake();

    $newValue = 'new value';
    $customQueue = 'custom-queue';
    $staleValue = cache()->swr($key, $ttl, $tts, fn () => $newValue, queue: $queueCallback = fn (PendingClosureDispatch $job) => $job->onQueue($customQueue));

    expect($staleValue)->toBe($originalValue);

    assertGotSwrAtomicLock($key);

    $ttsKey = SwrKeyGenerator::timeToStale($key);

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);
    Event::assertDispatched(CacheMissed::class, fn (CacheMissed $event) => $event->key === $ttsKey);
    Event::assertNotDispatched(KeyWritten::class);

    Queue::assertNothingPushed();

    assertTerminatingCallbacksToBe(1);

    // Flush
    Event::fake();
    Queue::fake();

    $secondTimeStaleValue = cache()->swr($key, $ttl, $tts, fn () => 'this should be ignored', queue: $queueCallback);

    expect($secondTimeStaleValue)->toBe($originalValue);

    Event::assertDispatched(CacheHit::class, fn (CacheHit $event) => $event->key === $key);
    Event::assertNotDispatched(CacheMissed::class); // Should ignore Time-To-Stale check
    Event::assertNotDispatched(KeyWritten::class);

    Queue::assertNothingPushed();

    assertTerminatingCallbacksToBe(1);

    // Flush
    Event::fake();
    Queue::fake();

    simulateRequestTermination();

    Event::assertNothingDispatched();

    $job = null;

    Queue::assertPushed(CallQueuedClosure::class, 1);
    Queue::assertPushedOn($customQueue, CallQueuedClosure::class, function ($j) use (&$job) {
        $job = $j;
        return true;
    });

    Event::fake(); // Flush

    app()->call([$job, 'handle']);

    assertFreeSwrAtomicLock($key);

    Event::assertNotDispatched(CacheHit::class);
    Event::assertNotDispatched(CacheMissed::class);

    Event::assertDispatched(KeyWritten::class, 2);

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $key
            && $event->value === $newValue
            && $event->seconds === $ttl
    );
    expect(cache()->get($key))->toBe($newValue);

    Event::assertDispatched(
        KeyWritten::class,
        fn (KeyWritten $event) => $event->key === $ttsKey
            && $event->value === true
            && $event->seconds === $tts
    );
    expect(cache()->get($ttsKey))->toBeTrue();
});
