# Yii3 Routing

## Route Definitions

Routes are defined in `config/common/routes.php` using the fluent `Route` and `Group` API from `yiisoft/router`.

```php
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

return [
    Route::get('/')->action([SpaController::class, 'index'])->name('home'),

    Group::create('/api')->routes(
        Route::get('/health')->action([AuthController::class, 'health'])->name('api.health'),
        Route::post('/auth/login')->action([AuthController::class, 'login'])->name('api.auth.login'),
        Route::methods(['PUT', 'PATCH'], '/invoice/update')
            ->action([InvoiceController::class, 'update'])
            ->name('api.invoice.update'),
    ),
];
```

## HTTP Method Helpers

- `Route::get($path)` -- GET requests
- `Route::post($path)` -- POST requests
- `Route::put($path)` -- PUT requests
- `Route::delete($path)` -- DELETE requests
- `Route::methods(['PUT', 'PATCH', 'DELETE'], $path)` -- multiple methods

## Pattern Constraints

Route parameters support inline regex constraints:

```php
Route::get('/hotel/{slug:[a-zA-Z0-9_-]+}-accommodation')
    ->action(Web\Hotel\ViewAction::class)
    ->name('hotel-view'),

Route::get('/v1/hotels/{wid:\d+}')
    ->action(Web\Api\HotelViewAction::class)
    ->name('api-hotel-view'),

Route::get('/{city:[a-z0-9-]+}-accommodation')
    ->action(Web\Search\IndexAction::class)
    ->name('search-city'),
```

## Accessing Route Parameters

Inject `CurrentRoute` to access matched route arguments:

```php
use Yiisoft\Router\CurrentRoute;

public function __invoke(CurrentRoute $currentRoute): ResponseInterface
{
    $hash = $currentRoute->getArgument('hash');
}
```

## Action Classes (Invokable)

Yii3 supports single-action classes with `__invoke()`. This pattern gives each route handler its own class with focused dependencies.

```php
final readonly class Action
{
    public function __construct(
        private ViewRenderer $viewRenderer,
        private CityQuery $cityQuery,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $cities = $this->cityQuery->popular(12);
        return $this->viewRenderer->render(__DIR__ . '/template', [
            'cities' => $cities,
        ]);
    }
}
```

Register the action class directly in routes:

```php
Route::get('/feed')->action(Web\Feed\Action::class)->name('feed'),
```

## Groups with Middleware

Groups can apply middleware to all contained routes:

```php
Group::create()
    ->middleware(CsrfTokenMiddleware::class)
    ->routes(
        Route::get('/')->action(Web\HomePage\Action::class)->name('home'),
        Route::post('/v1/enquiries')
            ->action(Web\Api\EnquiryAction::class)
            ->disableMiddleware(CsrfTokenMiddleware::class)
            ->name('api-enquiry'),
    ),
```

Use `disableMiddleware()` to exclude inherited middleware from specific routes.

## Route Names

Always name routes with `->name()`. Names enable URL generation without hardcoding paths. Use dot-separated conventions for API routes (e.g., `api.invoice.index`).

## Router DI Configuration

The router is wired in `config/common/di/router.php`:

```php
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollectionInterface;
use Yiisoft\Router\RouteCollector;

return [
    RouteCollectionInterface::class => [
        'class' => RouteCollection::class,
        '__construct()' => [
            'collector' => DynamicReference::to(
                static fn() => (new RouteCollector())->addRoute(...$config->get('routes')),
            ),
        ],
    ],
];
```
