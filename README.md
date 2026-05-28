# ceedcv-maya/shared-platform-laravel

Cross-cutting platform utilities for Laravel microservices: PostgreSQL FDW migrations helpers, locale providers, shared contracts.

Part of the [ceedcv-maya/maya_platform](https://github.com/Maya-AQSS/maya_platform) mono-repo. Distributed independently for reuse outside the Maya ecosystem.

## Installation

```bash
composer require ceedcv-maya/shared-platform-laravel
```

Provides PostgreSQL FDW migration helpers and locale resolution traits used across Laravel microservices.


## AbstractFdwRepository

Base class for repositories that provide read-only access to PostgreSQL Foreign Data Wrapper tables. Extend it and implement `modelClass()` to get `findById`, `findByIdOrFail`, `exists`, `pluckForFilter`, and `all` for free.

```php
class StudyTypeRepository extends AbstractFdwRepository
{
    protected function modelClass(): string
    {
        return StudyType::class;
    }
}
```

### Excepciones documentadas

The following code sites are **exempt** from the mandatory repository-layer rule and may access Eloquent/DB directly:

- `AppServiceProvider::boot()` — guard/driver registration (`Auth::viaRequest`, `Auth::extend`) must run in boot, before the repository layer is available.
- Keycloak / JWT user resolver closures — resolved during the authentication bootstrap, before the HTTP kernel dispatches to a controller.
- Database connection bootstrap — `database.php` config and initial connection establishment happen before the service container is fully wired.

These exemptions are architectural and intentional; do not expand them without an explicit ADR.

## TypeScript / build notes
PSR-4 autoload from `src/`. Service providers are registered via Laravel package discovery (no manual provider registration needed).

## License

MIT — see [LICENSE](LICENSE).

## Reporting issues

The canonical source lives in [Maya-AQSS/maya_platform](https://github.com/Maya-AQSS/maya_platform). File issues there; this read-only split repo is only the published artifact.
