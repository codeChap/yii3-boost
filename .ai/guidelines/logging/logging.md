# Yii3 Logging

## PSR-3 LoggerInterface

Yii3 logging is built on `psr/log`. Inject `Psr\Log\LoggerInterface` wherever you need logging. The framework provides `yiisoft/log` as the implementation.

```php
use Psr\Log\LoggerInterface;

final readonly class KeepAction
{
    public function __construct(
        private ConnectionInterface $db,
        private ResponseFactoryInterface $responseFactory,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $this->logger->info('Processing keep request', ['hash' => $hash]);

        try {
            // ... business logic
        } catch (\Throwable $e) {
            $this->logger->error('Keep action failed', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
```

## Log Levels

Standard PSR-3 log levels, in order of severity:

- `emergency` -- system is unusable
- `alert` -- action must be taken immediately
- `critical` -- critical conditions
- `error` -- runtime errors
- `warning` -- exceptional but non-error conditions
- `notice` -- normal but significant events
- `info` -- informational messages
- `debug` -- detailed debug information

## DI Configuration

Configure the logger in `config/common/di/logger.php`.

**Basic FileTarget setup:**

```php
use Psr\Log\LoggerInterface;
use Yiisoft\Log\Logger;
use Yiisoft\Log\Target\File\FileTarget;

return [
    LoggerInterface::class => [
        'class' => Logger::class,
        '__construct()' => [
            'targets' => [
                new FileTarget(
                    dirname(__DIR__, 3) . '/runtime/logs/app.log',
                ),
            ],
        ],
    ],
];
```

**Multiple targets with ReferencesArray:**

```php
use Yiisoft\Definitions\ReferencesArray;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;
use Yiisoft\Log\Target\File\FileTarget;

return [
    LoggerInterface::class => [
        'class' => Logger::class,
        '__construct()' => [
            'targets' => ReferencesArray::from([
                FileTarget::class,
                StreamTarget::class,
            ]),
        ],
    ],
];
```

## FileTarget

`yiisoft/log-target-file` writes log messages to files. Configure the file path in params:

```php
// config/common/params.php
return [
    'yiisoft/log-target-file' => [
        'file' => '@runtime/logs/app.log',
    ],
];
```

## Filtering by Level

Targets can be configured to accept only specific log levels using `setLevels()`:

```php
use Psr\Log\LogLevel;

(new FileTarget($logPath))->setLevels([
    LogLevel::EMERGENCY,
    LogLevel::ERROR,
    LogLevel::WARNING,
]),
```

## Temporary Error Handler

During container building (before the logger is available), use a temporary error handler with an inline Logger:

```php
$runner = new HttpApplicationRunner(
    rootPath: $root,
    debug: true,
    temporaryErrorHandler: new ErrorHandler(
        new Logger([
            (new FileTarget($root . '/runtime/logs/app-container-building.log'))
                ->setLevels([LogLevel::EMERGENCY, LogLevel::ERROR, LogLevel::WARNING]),
        ]),
        new HtmlRenderer(),
    ),
);
```

## Console Logging

In console commands, you can inject `LoggerInterface` alongside writing to stdout. This is useful for commands that run as cron jobs where you want both interactive output and persistent log files.
