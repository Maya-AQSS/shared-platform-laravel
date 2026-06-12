<?php

declare(strict_types=1);

namespace Maya\Platform\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Trait para servicios que cachean opciones de filtros dropdown.
 *
 * Uso:
 *
 *     class ApplicationService
 *     {
 *         use CachesFilterOptions;
 *
 *         public function pluckForFilter(string $scope): array
 *         {
 *             return $this->rememberFilterOptions(
 *                 'applications:pluck_for_filter:v1:'.$scope,
 *                 300,
 *                 fn (): array => $this->repository->pluckForFilter($scope),
 *             );
 *         }
 *     }
 */
trait CachesFilterOptions
{
    /**
     * Recupera opciones de filtro de la caché o las computa y almacena.
     *
     * @template T
     * @param  callable(): T  $resolver
     * @return T
     */
    protected function rememberFilterOptions(string $key, int $ttl, callable $resolver): mixed
    {
        return Cache::remember($key, $ttl, $resolver);
    }
}
