<p align="center">
  <a href="https://perfbase.com">
    <img src="https://cdn.perfbase.com/img/logo-full.svg" alt="Perfbase" width="300">
  </a>
</p>

<h3 align="center">Perfbase for Symfony</h3>
<p align="center">
  Symfony integration for <a href="https://perfbase.com">Perfbase</a>.
</p>

<p align="center">
  <a href="https://packagist.org/packages/perfbase/symfony"><img src="https://img.shields.io/packagist/v/perfbase/symfony" alt="Packagist Version"></a>
  <a href="https://github.com/perfbaseorg/symfony/blob/main/LICENSE.txt"><img src="https://img.shields.io/packagist/l/perfbase/symfony" alt="License"></a>
  <a href="https://github.com/perfbaseorg/symfony/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/perfbaseorg/symfony/ci.yml?branch=main" alt="CI"></a>
  <img src="https://img.shields.io/badge/php-7.4--8.5-blue" alt="PHP Version">
  <img src="https://img.shields.io/badge/symfony-5.4--7.x-blue" alt="Symfony Version">
</p>

`perfbase/symfony` is the official Symfony bundle for Perfbase.

It integrates Symfony request and console lifecycles with the Perfbase PHP SDK
and native extension so traces are captured and submitted with minimal
application code.

Current production scope:

- HTTP request profiling
- Console command profiling
- Configurable sampling
- Include and exclude filters for HTTP and console contexts
- Fail-open runtime behavior when profiling cannot start safely

Out of scope in the current release:

- Messenger worker profiling
- Scheduler-specific instrumentation beyond normal console commands
- Symfony Web Profiler toolbar integration
- an auto-published Symfony Flex recipe

## Requirements

- PHP `7.4` to `8.5`
- Symfony `5.4` to `7.x`
- [`perfbase/php-sdk`](https://packagist.org/packages/perfbase/php-sdk) `^1.0`
- The Perfbase native PHP extension installed and enabled

## Installation

Install the bundle:

```bash
composer require perfbase/symfony
```

Install the Perfbase PHP extension if it is not already available:

```bash
bash -c "$(curl -fsSL https://cdn.perfbase.com/install.sh)"
```

Restart PHP-FPM, Apache, RoadRunner, Swoole, or any other long-lived PHP
runtime after installing the extension.

## Bundle Registration

If your application uses Symfony Flex and a published Perfbase recipe is
available in your environment, bundle registration can be automated.

If not, register the bundle manually in `config/bundles.php`:

```php
<?php

return [
    // ...
    Perfbase\Symfony\PerfbaseBundle::class => ['all' => true],
];
```

Then add `config/packages/perfbase.yaml`:

```yaml
perfbase:
  enabled: true
  api_key: '%env(PERFBASE_API_KEY)%'
  sample_rate: 0.1
  app_version: '%env(default::PERFBASE_APP_VERSION)%'
```

Recommended environment variables:

```dotenv
PERFBASE_API_KEY=your_api_key_here
PERFBASE_APP_VERSION=1.0.0
```

## Configuration

The bundle exposes configuration under `perfbase:`.

```yaml
perfbase:
  enabled: false
  debug: false
  log_errors: true
  api_key: ''
  api_url: 'https://ingress.perfbase.cloud'
  sample_rate: 0.1
  timeout: 10
  proxy: null
  flags: !php/const Perfbase\SDK\FeatureFlags::DefaultFlags
  app_version: ''
  include:
    http: ['*']
    console: ['*']
  exclude:
    http: []
    console: []
```

### Configuration Reference

| Key | Type | Default | Purpose |
| --- | --- | --- | --- |
| `enabled` | `bool` | `false` | Enables or disables profiling globally |
| `debug` | `bool` | `false` | Re-throws profiling errors instead of failing open |
| `log_errors` | `bool` | `true` | Logs profiling errors when `debug=false` |
| `api_key` | `string` | `''` | Perfbase project API key |
| `api_url` | `string` | `https://ingress.perfbase.cloud` | Receiver base URL |
| `sample_rate` | `float` | `0.1` | Sampling rate from `0.0` to `1.0` |
| `timeout` | `int` | `10` | SDK submission timeout in seconds |
| `proxy` | `string|null` | `null` | Optional outbound proxy URL |
| `flags` | `int` | `FeatureFlags::DefaultFlags` | Perfbase extension feature flags |
| `app_version` | `string` | `''` | Version string attached to traces |
| `include.http` | `string[]` | `['*']` | HTTP allow-list filters |
| `exclude.http` | `string[]` | `[]` | HTTP deny-list filters |
| `include.console` | `string[]` | `['*']` | Console allow-list filters |
| `exclude.console` | `string[]` | `[]` | Console deny-list filters |

### Validation

Invalid configuration fails during Symfony config processing.

The bundle validates:

- `enabled: true` requires a non-empty `api_key`
- `api_url` must be a valid URL
- `proxy` must be `null` or a valid URL
- `sample_rate` must be between `0.0` and `1.0`
- `timeout` must be at least `1`
- `flags` must be non-negative
- filter entries cannot be empty strings

### Environment and App Version

- `environment` is derived from Symfony `kernel.environment`
- `app_version` is configured explicitly under `perfbase.app_version`

Symfony does not define a single application-version convention, so the bundle
does not infer one automatically.

## Example Production Configuration

```yaml
perfbase:
  enabled: true
  debug: false
  log_errors: true
  api_key: '%env(PERFBASE_API_KEY)%'
  api_url: 'https://ingress.perfbase.cloud'
  sample_rate: 0.2
  timeout: 10
  flags: !php/const Perfbase\SDK\FeatureFlags::DefaultFlags
  app_version: '%env(default::PERFBASE_APP_VERSION)%'
  include:
    http:
      - 'GET /api/*'
      - 'POST /checkout'
    console:
      - 'app:*'
  exclude:
    http:
      - 'GET /health'
      - '/^GET \\/_profiler/'
    console:
      - 'cache:clear'
```

## What Gets Profiled

### HTTP Requests

The bundle profiles main HTTP requests through:

- `kernel.request`
- `kernel.response`
- `kernel.exception`
- `kernel.terminate`

Behavior:

- main requests only
- route templates are preferred for stable naming
- response status codes are attached when available
- exception messages are attached on exception paths
- trace submission happens during terminate

HTTP attributes include:

- `source=http`
- `action`
- `http_method`
- `http_url`
- `http_status_code`
- `user_ip`
- `user_agent`
- `user_id` when a Symfony security token exposes one
- `environment`
- `app_version`
- `hostname`
- `php_version`

### Console Commands

The bundle profiles console commands through:

- `console.command`
- `console.error`
- `console.terminate`

Console attributes include:

- `source=console`
- `action`
- `exit_code`
- `exception`
- `environment`
- `app_version`
- `hostname`
- `php_version`

## Naming and Cardinality

Span names and `action` values are intentionally low-cardinality.

HTTP naming preference:

1. Route path template such as `GET /articles/{id}`
2. Route name
3. Raw request path

Example HTTP span:

```text
http.GET./articles/{id}
```

Example console span:

```text
console.cache:clear
```

## URL and Privacy Behavior

The bundle excludes query strings from `http_url`.

Example:

```text
https://example.com/articles/42?token=secret
```

becomes:

```text
https://example.com/articles/42
```

This is intentional. It avoids leaking signed URLs, nonces, tokens, and other
high-cardinality values into trace metadata.

## Filters

The bundle supports include and exclude filters for both HTTP and console
profiling.

Supported pattern styles:

- `*` or `.*` to match everything
- glob patterns such as `admin/*` or `app:*`
- regex patterns such as `/^GET \/health$/`

HTTP matching evaluates these inputs when available:

- route path template
- route name
- request path
- `METHOD path`
- controller string

Example:

```yaml
perfbase:
  include:
    http:
      - 'GET /api/*'
      - 'app_api_*'
    console:
      - 'app:*'
  exclude:
    http:
      - 'GET /health'
      - '/^GET \\/_profiler/'
    console:
      - 'cache:clear'
```

## Failure Model

The bundle is designed to fail open.

- In normal production mode, profiling failures do not break requests or commands
- If `log_errors=true`, failures are logged
- If `debug=true`, profiling exceptions are re-thrown

This behavior is implemented by [`PerfbaseErrorHandler.php`](src/Support/PerfbaseErrorHandler.php)
and the lazy client boot path in [`PerfbaseClientProvider.php`](src/Support/PerfbaseClientProvider.php).

## Architecture Notes

This package is intentionally thin.

- It does not implement its own transport
- It does not implement local buffering or retries
- It does not build trace payloads itself
- It delegates extension access and submission to `perfbase/php-sdk`

Primary runtime pieces:

- [`src/PerfbaseBundle.php`](src/PerfbaseBundle.php)
- [`src/DependencyInjection/Configuration.php`](src/DependencyInjection/Configuration.php)
- [`src/DependencyInjection/PerfbaseExtension.php`](src/DependencyInjection/PerfbaseExtension.php)
- [`src/Profiling/AbstractProfiler.php`](src/Profiling/AbstractProfiler.php)
- [`src/Lifecycle/HttpRequestLifecycle.php`](src/Lifecycle/HttpRequestLifecycle.php)
- [`src/Lifecycle/ConsoleCommandLifecycle.php`](src/Lifecycle/ConsoleCommandLifecycle.php)
- [`src/EventSubscriber/HttpProfilerSubscriber.php`](src/EventSubscriber/HttpProfilerSubscriber.php)
- [`src/EventSubscriber/ConsoleProfilerSubscriber.php`](src/EventSubscriber/ConsoleProfilerSubscriber.php)
- [`src/Support/FilterMatcher.php`](src/Support/FilterMatcher.php)
- [`src/Support/SpanNaming.php`](src/Support/SpanNaming.php)
- [`src/Support/PerfbaseClientProvider.php`](src/Support/PerfbaseClientProvider.php)

## Limitations

This release does not currently provide:

- Messenger worker instrumentation
- profiling for Symfony subrequests
- request body or response body capture
- local buffering or offline delivery modes
- Symfony Web Profiler toolbar integration

If Messenger support is added later, it should be implemented as a separate
lifecycle and subscriber path.

## Quality and Verification

Verification commands:

```bash
composer run test
composer run phpstan
composer run lint
php fixture-app/smoke.php
```

The package includes:

- unit tests for config, helpers, lifecycles, and error handling
- integration tests for HTTP and console event flows
- an install-level fixture app under [`fixture-app/`](fixture-app)
- GitHub Actions CI for:
  - PHP `7.4` + Symfony `5.4`
  - PHP `8.0` through `8.5` + Symfony `5.4`
  - PHP `8.1` through `8.5` + Symfony `6.4`
  - PHP `8.2` through `8.5` + Symfony `7.x`
  - fixture-app smoke verification

Current coverage:

- methods: `80.00%`
- lines: `95.27%`

## Symfony Flex

The repository includes a recipe source under [`recipe/`](recipe/) and bundle
metadata in [`composer.json`](composer.json).

That recipe is not consumed automatically from this repository. Symfony Flex
will use it only after it has been published to the Symfony recipes ecosystem
or another configured recipe source.

## Development

This repository is shaped as a normal publishable library:

- `perfbase/php-sdk` is resolved from Packagist
- `composer.lock` is not committed
- there are no monorepo-only path repositories in the package manifest

Local development:

```bash
composer install
composer run test
composer run phpstan
php fixture-app/smoke.php
```

## Documentation

Full documentation is available at [perfbase.com/docs](https://perfbase.com/docs).

- **Docs**: [perfbase.com/docs](https://perfbase.com/docs)
- **Issues**: [github.com/perfbaseorg/symfony/issues](https://github.com/perfbaseorg/symfony/issues)
- **Support**: [support@perfbase.com](mailto:support@perfbase.com)

## License

Apache-2.0. See [LICENSE.txt](LICENSE.txt).
