<?php

declare(strict_types=1);

namespace Maya\Platform\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Base para repositorios que acceden a modelos FDW (PostgreSQL Foreign Data Wrappers).
 *
 * Los FDW son de solo lectura desde la aplicación: únicamente expone operaciones
 * de consulta. Las subclases declaran el modelo Eloquent concreto implementando
 * `modelClass()`.
 *
 * Uso típico:
 *
 *     class StudyTypeRepository extends AbstractFdwRepository
 *     {
 *         protected function modelClass(): string
 *         {
 *             return StudyType::class;
 *         }
 *     }
 *
 * @template TModel of Model
 */
abstract class AbstractFdwRepository
{
    /**
     * Devuelve el FQCN del modelo Eloquent que mapea la foreign table.
     *
     * @return class-string<TModel>
     */
    abstract protected function modelClass(): string;

    /**
     * Busca un registro por su clave primaria. Devuelve null si no existe.
     *
     * @return TModel|null
     */
    public function findById(int|string $id): ?Model
    {
        return $this->modelClass()::find($id);
    }

    /**
     * Busca un registro por su clave primaria o lanza ModelNotFoundException.
     *
     * @return TModel
     */
    public function findByIdOrFail(int|string $id): Model
    {
        return $this->modelClass()::findOrFail($id);
    }

    /**
     * Comprueba si existe un registro con la clave primaria indicada.
     */
    public function exists(int|string $id): bool
    {
        return $this->modelClass()::where('id', $id)->exists();
    }

    /**
     * Devuelve una colección indexada por `$valueColumn` con valor `$labelColumn`,
     * útil para poblar selects y filtros en el frontend.
     *
     * @return Collection<int|string, string>
     */
    public function pluckForFilter(string $labelColumn = 'name', string $valueColumn = 'id'): Collection
    {
        return $this->modelClass()::orderBy($labelColumn)->pluck($labelColumn, $valueColumn);
    }

    /**
     * Devuelve todos los registros de la foreign table.
     * Usar con precaución en tablas grandes — considerar paginar en el repositorio concreto.
     *
     * @return Collection<int, TModel>
     */
    public function all(): Collection
    {
        return $this->modelClass()::all();
    }
}
