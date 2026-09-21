# pdo-retry

Retry wrapper for PDO on deadlocks and lost connections.

Wraps a PDO factory and re-runs a whole unit of work when it fails with something that is
safe to retry:

- deadlocks and serialization failures (SQLSTATE `40001`, `40P01`, MySQL `1213`, `1205`)
- lost connections (MySQL `2006` / `2013`, SQLSTATE class `08`, "server closed the connection
  unexpectedly" and friends); the connection is dropped and re-created before retrying

Backoff is exponential with full jitter. Anything else is rethrown immediately.

## Install

```
composer require jhalvorsen/pdo-retry
```

## Usage

```php
use Halvorsen\PdoRetry\RetryingConnection;

$db = new RetryingConnection(
    fn () => new PDO('mysql:host=db;dbname=app', 'app', getenv('DB_PASSWORD')),
    maxAttempts: 5,
    baseDelayMs: 25,
);

$db->onRetry(fn (PDOException $e, int $attempt) => error_log("retry $attempt: {$e->getMessage()}"));

$orderId = $db->transaction(function (PDO $pdo) use ($cart) {
    $pdo->prepare('update stock set qty = qty - ? where sku = ?')->execute([$cart->qty, $cart->sku]);
    $pdo->prepare('insert into orders (sku, qty) values (?, ?)')->execute([$cart->sku, $cart->qty]);
    return (int) $pdo->lastInsertId();
});
```

The closure may run more than once, so keep side effects (sending mail, calling APIs) outside
of it. `retry()` runs work without a transaction and should only be used for idempotent
statements.

## Tests

```
composer install
vendor/bin/phpunit tests
```

The tests use `pdo_sqlite`.
