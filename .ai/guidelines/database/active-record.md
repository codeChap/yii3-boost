# Yii3 ActiveRecord

## Basic Model

Yii3 ActiveRecord models extend `Yiisoft\ActiveRecord\ActiveRecord`. Unlike Yii2, there are no magic property accessors. Use `get()` and `set()` to read and write attributes.

```php
use Yiisoft\ActiveRecord\ActiveRecord;

final class Task extends ActiveRecord
{
    public function tableName(): string
    {
        return '{{%task}}';
    }
}
```

Note: `tableName()` is an **instance method** (not static as in Yii2). The `{{%` prefix applies the configured table prefix.

## Reading and Writing Attributes

```php
// Read
$title = $task->get('title');
$id    = $task->get('id');

// Write
$task->set('title', 'New title');
$task->set('updated_at', time());
```

## Creating and Saving Records

ActiveRecord requires a `ConnectionInterface` passed to the constructor. The connection is injected, not accessed via a global singleton.

```php
use Yiisoft\Db\Connection\ConnectionInterface;

// Create a new record
$task = new Task($db);
$task->set('title', 'Build feature');
$task->set('status', 'pending');
$task->set('created_at', time());
$task->save();

// Find and update
$task = (new Task($db))->query()
    ->where(['id' => $id])
    ->one();

if ($task !== null) {
    $task->set('status', 'completed');
    $task->set('updated_at', time());
    $task->save();
}
```

## Querying

The `query()` method returns an `ActiveQueryInterface` instance for fluent query building.

```php
// Find one record
$user = (new User($db))->query()
    ->where(['username' => $username, 'status' => User::STATUS_ACTIVE])
    ->one();

// Find all with conditions
$tasks = (new Task($db))->query()
    ->where(['user_id' => $userId])
    ->andWhere(['status' => 'pending'])
    ->orderBy(['created_at' => SORT_DESC])
    ->all();
```

## Relations

Relations are defined using `hasOne()` and `hasMany()` methods, returning `ActiveQueryInterface`.

```php
public function getAuthor(): ActiveQueryInterface
{
    return $this->hasOne(User::class, ['id' => 'user_id']);
}

public function getTasks(): ActiveQueryInterface
{
    return $this->hasMany(Task::class, ['user_id' => 'id']);
}
```

## Common Patterns

**Timestamp helper:**

```php
public function touchTimestamps(): void
{
    $now = time();
    if ($this->get('id') === null) {
        $this->set('created_at', $now);
    }
    $this->set('updated_at', $now);
}
```

**API serialization:**

```php
public function toApiArray(): array
{
    return [
        'id'     => $this->get('id'),
        'title'  => $this->get('title'),
        'status' => $this->get('status'),
    ];
}
```

**Static finder with injected connection:**

```php
public static function getBaseRate(ConnectionInterface $db, string $contactId): ?float
{
    $setting = (new self($db))->query()
        ->where(['xero_contact_id' => $contactId])
        ->one();

    return $setting ? (float) $setting->get('base_rate') : null;
}
```
