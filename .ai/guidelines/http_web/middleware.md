# Yii3 Middleware

## PSR-15 MiddlewareInterface

All Yii3 middleware implements the standard `Psr\Http\Server\MiddlewareInterface`. There is no framework-specific middleware base class.

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly string $allowedOrigins = '*',
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->responseFactory->createResponse(204)
                ->withHeader('Access-Control-Allow-Origin', '*');
        }

        $response = $handler->handle($request);
        return $response->withHeader('Access-Control-Allow-Origin', '*');
    }
}
```

## Application Middleware Pipeline

The global middleware pipeline is defined in `config/web/di/application.php` using `withMiddlewares()`. Order matters -- middleware executes top to bottom on request, bottom to top on response.

```php
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\Yii\Http\Application;

return [
    Application::class => [
        '__construct()' => [
            'dispatcher' => DynamicReference::to([
                'class' => MiddlewareDispatcher::class,
                'withMiddlewares()' => [[
                    ErrorCatcher::class,
                    SecurityHeadersMiddleware::class,
                    SessionMiddleware::class,
                    FormatDataResponse::class,
                    RequestCatcherMiddleware::class,
                    Router::class,
                ]],
            ]),
            'fallbackHandler' => Reference::to(NotFoundHandler::class),
        ],
    ],
];
```

## Error Handling Middleware

`ErrorCatcher` should be placed early in the pipeline to catch exceptions from all subsequent middleware.

```php
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
// Place ErrorCatcher before other middleware
'withMiddlewares()' => [[
    ErrorCatcher::class,
    // ... other middleware
    Router::class,
]],
```

## Per-Route Middleware

Middleware can be applied to route groups or individual routes:

```php
// Group-level middleware
Group::create()
    ->middleware(CsrfTokenMiddleware::class)
    ->routes(
        Route::get('/')->action(HomeAction::class)->name('home'),
        // Disable inherited middleware for specific routes
        Route::post('/api/webhook')
            ->action(WebhookAction::class)
            ->disableMiddleware(CsrfTokenMiddleware::class)
            ->name('webhook'),
    ),
```

## Middleware with DI

Middleware classes receive their dependencies through constructor injection, just like any other service. Register constructor arguments in `config/common/di/services.php` when needed.

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

## Worker-Mode Reset Middleware

When running under RoadRunner or similar persistent workers, place a reset middleware first in the pipeline to clear stateful singletons between requests.

```php
final class WorkerResetMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if ($this->db->isActive()) {
            $this->db->close();
        }
        return $handler->handle($request);
    }
}
```

## Fallback Handler

The `fallbackHandler` in the Application config handles requests that no middleware or route matched. Typically a 404 page or SPA catch-all controller.
