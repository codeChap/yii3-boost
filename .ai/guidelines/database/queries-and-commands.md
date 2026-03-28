# Yii3 Database Queries and Commands

## ConnectionInterface

All database access starts with `Yiisoft\Db\Connection\ConnectionInterface`, injected via the DI container. There is no global `Yii::$app->db`.

```php
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class FeedAction
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}
}
```

## Raw SQL with createCommand

Use `createCommand()` for raw SQL queries with parameter binding.

```php
// SELECT with named parameters
$rows = $this->db->createCommand(
    'SELECT id, name FROM websites WHERE viable = :viable LIMIT :limit',
    [':viable' => 1, ':limit' => 50]
)->queryAll();

// Single row
$row = $this->db->createCommand(
    'SELECT * FROM enquiries WHERE id = :id LIMIT 1',
    [':id' => $enquiryId]
)->queryOne();

// Single scalar value
$slug = $this->db->createCommand(
    'SELECT slug FROM names WHERE id = :id LIMIT 1',
    [':id' => $nameId]
)->queryScalar();

// Single column
$ids = $this->db->createCommand(
    'SELECT id FROM alternates WHERE accommodation_id = :accId',
)->bindValue(':accId', $accId)->queryColumn();
```

## Command Methods

- `queryAll()` -- returns array of rows
- `queryOne()` -- returns single row or `false`
- `queryScalar()` -- returns single value
- `queryColumn()` -- returns single column as flat array
- `execute()` -- for INSERT/UPDATE/DELETE, returns affected row count

## INSERT, UPDATE, DELETE via Command

```php
// Insert
$this->db->createCommand()->insert('websites', [
    'url' => $url,
    'viable' => 1,
    'created_at' => time(),
])->execute();

$newId = (int) $this->db->getLastInsertID();

// Update
$this->db->createCommand()->update('websites', [
    'viable' => 0,
], ['id' => $id])->execute();

// Delete
$this->db->createCommand()->delete('websites', ['id' => $id])->execute();

// Raw UPDATE with parameters
$this->db->createCommand(
    "UPDATE enquiries SET status = 'sent' WHERE id = :id",
    [':id' => $enquiryId]
)->execute();
```

## Query Builder

The `Query` class provides a fluent interface for building SELECT queries without raw SQL.

```php
use Yiisoft\Db\Query\Query;

$sites = (new Query($this->db))
    ->select(['id', 'url'])
    ->from('websites')
    ->where(['viable' => 1])
    ->andWhere(['not', ['url' => null]])
    ->andWhere(['not', ['url' => '']])
    ->orderBy(['updated_at' => SORT_DESC])
    ->limit(50)
    ->all();
```

## Transactions

Wrap multiple operations in a transaction for atomicity.

```php
$transaction = $this->db->beginTransaction();
try {
    $this->db->createCommand()->insert('orders', $orderData)->execute();
    $orderId = (int) $this->db->getLastInsertID();

    $this->db->createCommand()->insert('order_items', [
        'order_id' => $orderId,
        'product_id' => $productId,
    ])->execute();

    $transaction->commit();
} catch (\Throwable $e) {
    $transaction->rollBack();
    throw $e;
}
```

## DI Configuration for Database

Register the connection in `config/common/di/db.php`:

```php
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Mysql\Connection;
use Yiisoft\Db\Mysql\Driver;
use Yiisoft\Db\Mysql\Dsn;

return [
    ConnectionInterface::class => [
        'class' => Connection::class,
        '__construct()' => [
            'driver' => new Driver(
                new Dsn(
                    host: $params['db']['host'],
                    databaseName: $params['db']['name'],
                    port: $params['db']['port'],
                ),
                $params['db']['username'],
                $params['db']['password'],
            ),
        ],
    ],
];
```
