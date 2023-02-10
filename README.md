# This is my package laravel-swr-cache

[![Latest Version on Packagist](https://img.shields.io/packagist/v/iksaku/laravel-swr-cache.svg?style=flat-square)](https://packagist.org/packages/iksaku/laravel-swr-cache)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/iksaku/laravel-swr-cache/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/iksaku/laravel-swr-cache/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/iksaku/laravel-swr-cache/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/iksaku/laravel-swr-cache/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/iksaku/laravel-swr-cache.svg?style=flat-square)](https://packagist.org/packages/iksaku/laravel-swr-cache)

There are applications out there that rely heavily on cache to improve performance,
and thanks to Laravel's `cache()->remember()` method, we can easily cache the result
of a callback for a given time to live (TTL).

However, there are cases where the callback may take a long time to execute, and
we don't want to wait for it to finish before returning the result to the user.

This is where the [Stale-While-Revalidate](https://web.dev/stale-while-revalidate/)
pattern comes in handy. It allows us to return a cached result immediately, and
then execute the callback in the background to update the cache for the next
request.

<details>
<summary>How SWR works under the hood?</summary>

```mermaid
flowchart TD
    Request[Request cache key] --> CacheHit{Is key available in cache?}

    CacheHit -->|No| FirstTimeProcess[Execute long process]
    FirstTimeProcess --> FirstTimeCache[Cache result]
    FirstTimeCache --> Response
    
    CacheHit -->|Yes| CacheStale{Is cache stale?}
        CacheStale -->|No| ObtainCache[Obtain value from cache]
        ObtainCache --> Response

        CacheStale --> |Yes| QueueUpdate[Queue cache update]
            QueueUpdate --> ObtainStaleCache[Obtain stale value from cache]
            ObtainStaleCache --> Response

            QueueUpdate --> AfterResponse[/Wait until application response/]
            AfterResponse --> LongProcess[Execute long process]
            LongProcess --> CacheResult[Cache result]

    Response[Return value] --> Continue[/.../]
```
</details>

## Installation

You can install the package via composer:

```bash
composer require iksaku/laravel-swr-cache
```

## Usage

The `swr()` method is a wrapper around `cache()->remember()` that adds support for
the Stale-While-Revalidate pattern.
You can access it using the `cache()` helper:

```php
$stats = cache()->swr(
    key: 'stats',
    ttl: now()->addHour(),
    tts: now()->addMinutes(15),
    callback: function () {
        // This may take a couple of seconds...
    }
);

// ...
```

Or using the `Cache` facade:

```php
$stats = \Illuminate\Support\Facades\Cache::swr(
    key: 'stats',
    ttl: now()->addHour(),
    tts: now()->addMinutes(15),
    callback: function () {
        // This may take a couple of seconds...
    }
);

// ...
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Jorge González](https://github.com/iksaku)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
