# ceedcv-maya/shared-platform-laravel

Cross-cutting platform utilities for Laravel microservices: PostgreSQL FDW migrations helpers, locale providers, shared contracts.

Part of the [ceedcv-maya/maya_platform](https://github.com/Maya-AQSS/maya_platform) mono-repo. Distributed independently for reuse outside the Maya ecosystem.

## Installation

```bash
composer require ceedcv-maya/shared-platform-laravel
```

Provides PostgreSQL FDW migration helpers and locale resolution traits used across Laravel microservices.


## TypeScript / build notes
PSR-4 autoload from `src/`. Service providers are registered via Laravel package discovery (no manual provider registration needed).

## License

MIT — see [LICENSE](LICENSE).

## Reporting issues

The canonical source lives in [Maya-AQSS/maya_platform](https://github.com/Maya-AQSS/maya_platform). File issues there; this read-only split repo is only the published artifact.
