<?php

declare(strict_types=1);

namespace Halvorsen\PdoRetry;

use Closure;
use PDO;
use PDOException;

/**
 * Wraps a PDO factory and re-runs a unit of work when it fails on a deadlock, a
 * serialization failure or a dropped connection.
 *
 * Retrying only makes sense for the whole transaction, so work is passed in as a closure
 * rather than retrying individual statements.
 */
class RetryingConnection
{
    private ?PDO $pdo = null;
    private Closure $factory;
    private ErrorClassifier $classifier;

    /** @var callable|null fn(PDOException $e, int $attempt): void */
    private $onRetry = null;

    public function __construct(
        callable $factory,
        private int $maxAttempts = 3,
        private int $baseDelayMs = 50,
        ?ErrorClassifier $classifier = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1');
        }

        $this->factory = Closure::fromCallable($factory);
        $this->classifier = $classifier ?? new ErrorClassifier();
    }

    public function onRetry(callable $callback): static
    {
        $this->onRetry = $callback;
        return $this;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = ($this->factory)();
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return $this->pdo;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * Run $work(PDO) inside a transaction, retrying the whole thing on transient errors.
     */
    public function transaction(callable $work): mixed
    {
        return $this->retry(function (PDO $pdo) use ($work) {
            $pdo->beginTransaction();
            try {
                $result = $work($pdo);
                $pdo->commit();
                return $result;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                    } catch (PDOException) {
                        // connection is probably gone; the retry will reconnect
                    }
                }
                throw $e;
            }
        });
    }

    /**
     * Run $work(PDO) without a transaction. Only use this for idempotent work.
     */
    public function retry(callable $work): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return $work($this->pdo());
            } catch (PDOException $e) {
                if ($attempt >= $this->maxAttempts || !$this->classifier->isTransient($e)) {
                    throw $e;
                }

                if ($this->classifier->isConnectionLost($e)) {
                    $this->disconnect();
                }

                if ($this->onRetry) {
                    ($this->onRetry)($e, $attempt);
                }

                usleep($this->backoff($attempt) * 1000);
            }
        }
    }

    private function backoff(int $attempt): int
    {
        $delay = $this->baseDelayMs * (2 ** ($attempt - 1));

        // full jitter, so competing workers don't retry in lockstep
        return random_int(0, $delay);
    }
}
