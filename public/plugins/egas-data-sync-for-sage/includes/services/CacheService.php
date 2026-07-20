<?php

declare(strict_types=1);

namespace Egas\services;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class CacheService
{
    public final const CACHE_LIFETIME = 3600;

    private static ?CacheService $cacheService = null;
    private readonly FilesystemAdapter $filesystemAdapter;

    public static function getInstance(): self
    {
        if (self::$cacheService === null) {
            self::$cacheService = new self();
            self::$cacheService->filesystemAdapter = new FilesystemAdapter(defaultLifetime: self::CACHE_LIFETIME);
        }
//        self::$instance->cache->clear();
        return self::$cacheService;
    }

    public function clear(): void
    {
        $this->filesystemAdapter->clear();
    }

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        return $this->filesystemAdapter->get($key, $callback, $beta, $metadata);
    }

    public function delete(string $key): bool
    {
        return $this->filesystemAdapter->delete($key);
    }
}
