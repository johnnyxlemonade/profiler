# Lemonade Profiler

[![PHPStan](https://github.com/johnnyxlemonade/profiler/actions/workflows/phpstan.yml/badge.svg)](https://github.com/johnnyxlemonade/profiler/actions/workflows/phpstan.yml)
[![Tests](https://github.com/johnnyxlemonade/profiler/actions/workflows/phpunit.yml/badge.svg)](https://github.com/johnnyxlemonade/profiler/actions/workflows/phpunit.yml)
[![Coding Standards](https://github.com/johnnyxlemonade/profiler/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/johnnyxlemonade/profiler/actions/workflows/coding-standards.yml)

`johnnyxlemonade/profiler` adds a development-only request profiler to Lemonade
Framework applications. It shows request, SQL, and rendering measurements
through a toolbar and local request history.

## Features

- Debug toolbar on eligible HTML responses.
- HTTP request duration, status, route, and PHP peak memory.
- SQL counts, execution time, and bounded, sanitized query details.
- Request timeline with framework checkpoints.
- Rendering duration for view renderer calls.
- Recent request history and individual request details.
- Light and dark appearance following the system color scheme.

## Requirements

- PHP 8.3, 8.4, or 8.5.
- A compatible Lemonade Framework version. The current package requires `johnnyxlemonade/framework:dev-main`.
- Development use only. The Profiler does not activate in production.

The package does not require Lemonade Admin Platform.

## Installation

Install the package from Packagist as a development dependency:

```bash
composer require --dev johnnyxlemonade/profiler:dev-main
```

Register `Lemonade\Profiler\ProfilerServiceProvider` last in the application's
`app/Config/Providers.yaml`, keeping the existing providers in place:

```yaml
module: providers
config:
  providers:
    # Existing providers remain above this entry.
    - Lemonade\Profiler\ProfilerServiceProvider
```

Keep `Providers` in the `shared` section of `app/Config/Config.yaml`.
Registration is explicit; the package does not use `Providers.php`,
`class_exists()`, or automatic discovery.

When deploying with `composer install --no-dev`, production configuration must
not reference the Profiler provider because the package is not installed.

## Usage

In development, the toolbar appears automatically on eligible full HTML
responses. Open `/_lemonade/profiler` for recent requests and
`/_lemonade/profiler/request/{id}` for a request's details. Captured responses
include an `X-Lemonade-Profiler` header linking to their profile.

Each profile shows request status, route, duration, PHP peak memory, SQL totals
and sanitized details, timeline checkpoints, and rendering measurements.
Profiler pages and capture are available only in the development environment.

## Configuration

`ProfilerConfig` defaults to 100 retained profiles for one hour, 200 SQL details,
200 rendering calls, a 1 MiB profile limit, a 2 MiB HTML injection limit,
4,096 characters per sanitized SQL statement, and a 100 ms slow-query
threshold. Bind a `ProfilerConfig` instance in the root container before the
first request to change these limits or set `enabled` to `false`.

The Framework's own HTTP diagnostics are configured independently of this
development-only Profiler.

## Development

From the package root:

```bash
composer install
composer test
composer stan
composer cs:check
composer qa
```

## License

[Apache License 2.0](LICENSE).
