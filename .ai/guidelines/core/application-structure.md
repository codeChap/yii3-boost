# Yii3 Application Structure

## Runners

Yii3 uses dedicated runner classes as the application entry point. There is no single `Application` class that does everything.

**HTTP entry point** (`public/index.php`):

```php
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$runner = new HttpApplicationRunner(
    rootPath: dirname(__DIR__),
    debug: (bool)($_ENV['APP_DEBUG'] ?? false),
    environment: $_ENV['APP_ENV'] ?? 'prod',
    configDirectory: '',
);
$runner->run();
```

**Console entry point** (`yii`):

```php
use Yiisoft\Yii\Runner\Console\ConsoleApplicationRunner;

$runner = new ConsoleApplicationRunner(
    rootPath: __DIR__,
    debug: (bool)($_ENV['APP_DEBUG'] ?? false),
    environment: $_ENV['APP_ENV'] ?? 'prod',
    configDirectory: '',
);
$runner->run();
```

## Config-Plugin System

The `configuration.php` file in the project root defines how config files are merged. This is the central configuration map consumed by `yiisoft/config`.

```php
return [
    'config-plugin' => [
        'params'            => 'config/common/params.php',
        'params-web'        => ['$params', 'config/web/params.php'],
        'params-console'    => ['$params', 'config/console/params.php'],
        'di'                => 'config/common/di/*.php',
        'di-web'            => ['$di', 'config/web/di/*.php'],
        'di-console'        => '$di',
        'routes'            => 'config/common/routes.php',
        'bootstrap'         => [],
        'bootstrap-web'     => '$bootstrap',
        'bootstrap-console' => '$bootstrap',
        'events'            => [],
        'events-web'        => '$events',
        'events-console'    => '$events',
    ],
    'config-plugin-environments' => [
        'dev'  => ['params' => ['config/environments/dev/params.php']],
        'prod' => ['params' => ['config/environments/prod/params.php']],
    ],
    'config-plugin-options' => [
        'source-directory' => '',
    ],
];
```

The `$params` / `$di` references mean "inherit from the base key". Glob patterns (`di/*.php`) merge all matching files.

## No Global Singleton

Yii3 has no `Yii::$app` singleton. All dependencies are obtained via constructor injection from the DI container. There is no service locator pattern.

## PSR Compliance

Yii3 follows PSR standards throughout:
- **PSR-7** for HTTP messages (ServerRequestInterface, ResponseInterface)
- **PSR-11** for dependency injection container
- **PSR-15** for middleware (MiddlewareInterface, RequestHandlerInterface)
- **PSR-3** for logging (LoggerInterface)
- **PSR-17** for HTTP factories (ResponseFactoryInterface, StreamFactoryInterface)

## Typical Directory Layout

```
project/
    configuration.php       # Config-plugin merge plan
    public/index.php        # HTTP entry point
    yii                     # Console entry point
    config/
        common/
            params.php      # Shared parameters
            routes.php      # Route definitions
            di/             # DI definitions (one file per concern)
        web/
            params.php      # Web-only parameters
            di/             # Web-only DI definitions
        console/
            params.php      # Console-only parameters
        environments/
            dev/params.php
            prod/params.php
    src/                    # Application source code
    runtime/                # Logs, cache, temp files
    vendor/
```
