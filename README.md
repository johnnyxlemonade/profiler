# Lemonade Profiler

[![PHPStan](https://github.com/johnnyxlemonade/profiler/actions/workflows/phpstan.yml/badge.svg)](https://github.com/johnnyxlemonade/profiler/actions/workflows/phpstan.yml)
[![Tests](https://github.com/johnnyxlemonade/profiler/actions/workflows/phpunit.yml/badge.svg)](https://github.com/johnnyxlemonade/profiler/actions/workflows/phpunit.yml)
[![Coding Standards](https://github.com/johnnyxlemonade/profiler/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/johnnyxlemonade/profiler/actions/workflows/coding-standards.yml)

`johnnyxlemonade/profiler` is an optional diagnostic tool for Lemonade Framework
applications. It shows request, SQL, and rendering measurements through a
toolbar and local request history. Activation requires an explicit provider entry.

## Screenshots

### Debug Toolbar

![Lemonade Profiler toolbar with the SQL panel open](docs/images/profiler-toolbar.webp)

*Live request metrics and SQL details in the Shadow DOM toolbar.*

### Request Details

![Lemonade Profiler request detail with timeline, SQL, and rendering](docs/images/profiler-detail.webp)

*Request timing, performance comparison, SQL queries, and rendered templates.*

### Request History

![Lemonade Profiler request history and filters](docs/images/profiler-history.webp)

*Recent GET and POST requests with status, duration, SQL totals, and filters.*

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
- Explicit provider registration and `ProfilerConfig::enabled()` control activation in every environment.

The package does not require Lemonade Admin Platform.

## Installation

Install the package from Packagist:

```bash
composer require johnnyxlemonade/profiler:dev-main
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

If you install it with `composer require --dev`, production configuration must
omit the provider when deploying with `composer install --no-dev`.

## Usage

When registered and enabled, the toolbar appears on eligible full HTML
responses in any environment. Open `/_lemonade/profiler` for recent requests and
`/_lemonade/profiler/request/{id}` for a request's details. Captured responses
include an `X-Lemonade-Profiler` header linking to their profile.

Each profile shows request status, route, duration, PHP peak memory, SQL totals
and sanitized details, timeline checkpoints, and rendering measurements.
Profiler pages and capture are unavailable when `ProfilerConfig::enabled()` is
`false` or the provider is absent from `Providers.yaml`.
History accepts the current snapshot format; obsolete or corrupt local records
are discarded when storage is read.

**Public production deployments:** Profiler endpoints expose diagnostic history.
Restrict `/_lemonade/profiler` and its subpaths to authorized users before requests
reach the application, for example at the reverse proxy. Route-specific access
controls may not protect these endpoints because the profiler middleware handles
them before application routing. See [activation and access control](docs/activation.md).

## Configuration

`ProfilerConfig` defaults to 100 retained profiles for one hour, 200 SQL details,
200 rendering calls, a 1 MiB profile limit, a 2 MiB HTML injection limit,
4,096 characters per sanitized SQL statement, and a 100 ms slow-query
threshold. `enabled` defaults to `true`. Bind a `ProfilerConfig` instance in the
root container before the first request to change these limits or set `enabled`
to `false`. `APP_ENV` and `APP_DEBUG` do not change this setting.

The Framework's own HTTP diagnostics are configured independently of this
Profiler.

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

## Interactive toolbar

The toolbar uses one closed Shadow DOM root with package-owned ES modules and CSS,
without a frontend dependency or build step. Dynamic content is created as text;
Shadow DOM provides style isolation, not a security sandbox. Host pages are
trusted application content. No application CSP is changed or nonce copied.
The effective `script-src` / `script-src-elem` and `style-src` / `style-src-elem`
policies must allow the same-origin package assets. A nonce-only or stricter policy
can block the toolbar; server capture, headers, history and details still work.
If the stylesheet fails to load, the toolbar stays hidden and interception is not
installed. Host top-layer dialogs/fullscreen may cover the toolbar.

Time, Memory, SQL and HTTP open on mouse hover, keyboard focus or click/tap.
Escape, Close and outside clicks dismiss the panel. Tab visits panel links.
The toolbar shows small inline SVG icons and values; accessible labels
retain each metric name. Desktop panels are centered above their trigger and
clamped to the viewport.
On narrow screens the panel becomes a scrollable bottom sheet above the 48 px
toolbar; Memory and the profile/history links are available in More.
Panels follow the system light/dark preference.

UI text comes from `resources/i18n/cs.json` and `resources/i18n/en.json`.
The toolbar selects a supported page language, then the browser language, with
English as fallback. Its CS/EN switch changes the current toolbar immediately.
Request detail and history prefer `?lang=cs` or `?lang=en`, then `Accept-Language`.
Profiler links carry the selected language; no cookie or local storage is used.

Fetch and XHR responses are observed once after initialization, only when both
the original and final URLs are same-origin and outside the profiler namespace.
The `X-Lemonade-Profiler` link and URL-encoded JSON in
`X-Lemonade-Profiler-Metrics` identify a saved profile and carry its authoritative
HTTP/SQL totals and static route metadata. There is no extra server measurement,
body inspection, polling, storage persistence or automatic profile download.
The list keeps the latest 20 profiles. Profile IDs deduplicate overlapping transport
wrappers and cached copies of the same saved response; newly profiled requests
remain separate rows.
Redirects expose only the final response, as permitted by the browser. Requests
without valid profile metadata (including network errors, aborts, opaque responses
and unprofiled endpoints) are omitted rather than assigned invented metrics.
Calls made before installation or through previously saved API references cannot
be observed. Expired profiles may return 404 when opened. Fetch returns a chained
native promise preserving the response/rejection; XHR retains its native instance,
events, arguments, timeout and abort behavior.

Manual visual verification: check 240, 280, 320, 360, 390, 430, 768, 1024 and 1440 px in Chrome,
light/dark mode, keyboard Tab/Escape, hover, touch, outside dismissal, and long
route/SQL text. Browser automation is not required by this package.

### Request diagnostics

Request detail includes Session, Server and Composer sections. Missing optional metadata is displayed as `unavailable`. Session is observed in the existing profiler middleware while the request scope is still open; a service decorator only remembers sessions resolved by the application. It never resolves or starts a session for diagnostics. Native PHP item counts use only `count($_SESSION)`; storage-neutral sessions have no public count API, so their item count is unavailable. Cookie settings for framework sessions describe the configured policy (Secure may be `auto`); native settings come from PHP. Native lifetime is the GC threshold, not a guaranteed expiration time.

Server metadata uses a fixed allowlist of PHP runtime values, four resource directives, the application environment and up to 256 extension names/versions. Server software is reduced to a recognized product name and optional numeric version; the remaining deployment text is omitted. Composer uses `InstalledVersions` with at most 500 packages and bounded strings. Runtime metadata does not expose root requirements, so direct/transitive classification is unavailable. Composer's version is available only when its own runtime class is installed; the runtime API version is not substituted for it. No config files, installation paths, session IDs, session contents, cookie names/values or environment dumps are persisted by these sections.
