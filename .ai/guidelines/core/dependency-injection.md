# Yii3 Dependency Injection

## Constructor Injection

Yii3 uses constructor injection exclusively. There is no service locator or `Yii::$app->component` pattern. All dependencies are declared as constructor parameters and resolved by the DI container.

```php
final class AuthController
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ConnectionInterface $db,
    ) {}
}
```

## DI Config Files

Definitions live in `config/common/di/*.php` (or `config/web/di/*.php` for web-only services). Each file returns an array mapping interfaces/classes to their implementations.

**Simple interface-to-class mapping:**

```php
return [
    ResponseFactoryInterface::class => ResponseFactory::class,
    StreamFactoryInterface::class   => StreamFactory::class,
];
```

**Class with constructor arguments via `__construct()`:**

```php
return [
    CorsMiddleware::class => [
        'class' => CorsMiddleware::class,
        '__construct()' => [
            'allowedOrigins' => $params['cors']['allowedOrigins'],
        ],
    ],
];
```

**Factory closure (when complex setup is needed):**

```php
return [
    ConnectionInterface::class => static function (SchemaCache $schemaCache) use ($params): ConnectionInterface {
        $connection = new Connection(
            new Driver($params['yiisoft/db-mysql']['dsn'], $params['yiisoft/db-mysql']['username'], $params['yiisoft/db-mysql']['password']),
            $schemaCache,
        );
        ConnectionProvider::set($connection);
        return $connection;
    },
];
```

## Reference and DynamicReference

`Reference::to()` creates a lazy reference to another service in the container. `DynamicReference::to()` creates a definition resolved at call time, useful for building nested objects.

```php
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;

return [
    Application::class => [
        '__construct()' => [
            'dispatcher' => DynamicReference::to([
                'class' => MiddlewareDispatcher::class,
                'withMiddlewares()' => [[
                    ErrorCatcher::class,
                    Router::class,
                ]],
            ]),
            'fallbackHandler' => Reference::to(NotFoundHandler::class),
        ],
    ],
];
```

## ReferencesArray

`ReferencesArray::from()` converts a list of class names into an array of `Reference` objects, useful for injecting multiple targets.

```php
use Yiisoft\Definitions\ReferencesArray;

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

## Autowiring

The container autowires constructor parameters automatically when the type-hint matches a registered definition. Explicit config is only needed for scalar parameters, interfaces with multiple implementations, or non-trivial construction.

## Parameters ($params)

All DI config files receive the merged `$params` array. Parameters are defined in `config/common/params.php` and overlaid by environment-specific files. Use `$params` to pass configuration values into service definitions rather than hardcoding them.

```php
/** @var array $params */
return [
    JmapClient::class => [
        'class' => JmapClient::class,
        '__construct()' => [
            'sessionUrl' => $params['jmap']['sessionUrl'],
            'username'   => $params['jmap']['username'],
            'password'   => $params['jmap']['password'],
        ],
    ],
];
```
