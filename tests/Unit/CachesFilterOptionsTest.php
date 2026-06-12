<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Maya\Platform\Support\CachesFilterOptions;

/**
 * Concrete class that uses the trait under test.
 */
class FilterOptionService
{
    use CachesFilterOptions;

    public function getOptions(string $key, int $ttl, callable $resolver): mixed
    {
        return $this->rememberFilterOptions($key, $ttl, $resolver);
    }
}

it('calls Cache::remember with the given key, ttl and resolver', function (): void {
    Cache::shouldReceive('remember')
        ->once()
        ->with('filter:options:v1', 300, \Mockery::type('callable'))
        ->andReturn(['option_a', 'option_b']);

    $service = new FilterOptionService;
    $result = $service->getOptions('filter:options:v1', 300, fn () => ['option_a', 'option_b']);

    expect($result)->toBe(['option_a', 'option_b']);
});

it('returns value from cache on cache hit without calling resolver again', function (): void {
    Cache::shouldReceive('remember')
        ->twice()
        ->with('filter:hit', 60, \Mockery::type('callable'))
        ->andReturn([1, 2, 3]);

    $resolver = fn () => [1, 2, 3];
    $service = new FilterOptionService;

    $first = $service->getOptions('filter:hit', 60, $resolver);
    $second = $service->getOptions('filter:hit', 60, $resolver);

    expect($first)->toBe([1, 2, 3]);
    expect($second)->toBe([1, 2, 3]);
});

it('passes the resolver callable through to Cache::remember unchanged', function (): void {
    $capturedResolver = null;

    Cache::shouldReceive('remember')
        ->once()
        ->withArgs(function (string $key, int $ttl, callable $resolver) use (&$capturedResolver): bool {
            $capturedResolver = $resolver;

            return true;
        })
        ->andReturn(null);

    $myResolver = fn () => 'payload';
    $service = new FilterOptionService;
    $service->getOptions('any:key', 120, $myResolver);

    // The resolver passed to Cache::remember should behave identically to the original.
    expect($capturedResolver())->toBe('payload');
});

it('works with different cache key namespaces', function (): void {
    Cache::shouldReceive('remember')
        ->once()
        ->with('applications:pluck_for_filter:v3:active', 300, \Mockery::type('callable'))
        ->andReturn(['app_a']);

    $service = new FilterOptionService;
    $result = $service->getOptions('applications:pluck_for_filter:v3:active', 300, fn () => ['app_a']);

    expect($result)->toBe(['app_a']);
});

it('supports zero ttl (no-cache semantics delegated to Cache driver)', function (): void {
    Cache::shouldReceive('remember')
        ->once()
        ->with('some:key', 0, \Mockery::type('callable'))
        ->andReturn([]);

    $service = new FilterOptionService;
    $result = $service->getOptions('some:key', 0, fn () => []);

    expect($result)->toBe([]);
});
