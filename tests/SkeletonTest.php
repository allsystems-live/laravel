<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Package discovery is a claim in composer.json that Laravel reads at install
 * time. Nothing else in the suite exercises it — testbench registers the
 * provider explicitly — so without this a typo in the class name would ship and
 * only show up as "the package does nothing after composer require".
 */
final class SkeletonTest extends TestCase
{
    public function testTheServiceProviderIsRegisteredForPackageDiscovery(): void
    {
        /** @var array{extra: array{laravel: array{providers: list<string>}}, autoload: array{'psr-4': array<string, string>}} $composer */
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['AllSystems\\Laravel\\AllSystemsServiceProvider'],
            $composer['extra']['laravel']['providers'],
        );
        self::assertSame('src/', $composer['autoload']['psr-4']['AllSystems\\Laravel\\']);
    }
}
