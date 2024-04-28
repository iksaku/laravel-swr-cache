<?php

namespace Iksaku\LaravelSwrCache;

class SwrKeyGenerator
{
    public static function timeToStale(string $key): string
    {
        return "laravel_swr_cache:tts:{$key}";
    }

    public static function atomicLock(string $key): string
    {
        return "laravel_swr_cache:revalidate:{$key}";
    }
}
