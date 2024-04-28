<?php

test('swr macro is registered', function () {
    expect(cache()->hasMacro('swr'))->toBeTrue();
});

it('throws an exception if using the Null cache driver', function () {
    cache()->driver('null')->swr('key', 20, 10, fn () => 'value');
})
    ->throws(RuntimeException::class, 'This cache driver does not support Atomic Locks');

it('throws an exception if time-to-stale is greater or equal than time-to-live', function (mixed $ttl, mixed $tts) {
    cache()->swr($key = 'key', $ttl, $tts, fn () => 'value');
})
    ->throws(UnexpectedValueException::class, 'The time-to-stale value must be less than the time-to-live value.')
    ->with([
        ['ttl' => 5, 'tts' => 10],
        ['ttl' => 10, 'tts' => 10],
    ]);
