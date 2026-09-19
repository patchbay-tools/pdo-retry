<?php

declare(strict_types=1);

namespace Halvorsen\PdoRetry\Tests;

use Halvorsen\PdoRetry\RetryingConnection;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class RetryingConnectionTest extends TestCase
{
    private function deadlock(): PDOException
    {
        $e = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
        $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
        return $e;
    }

    private function goneAway(): PDOException
    {
        $e = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $e->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
        return $e;
    }

    private function connection(int &$connects = 0): RetryingConnection
    {
        return new RetryingConnection(function () use (&$connects) {
            $connects++;
            return new PDO('sqlite::memory:');
        }, maxAttempts: 3, baseDelayMs: 1);
    }

    public function testRetriesDeadlockThenSucceeds(): void
    {
        $calls = 0;
        $result = $this->connection()->transaction(function () use (&$calls) {
            if (++$calls < 3) {
                throw $this->deadlock();
            }
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        $this->expectException(PDOException::class);
        $this->connection()->transaction(fn () => throw $this->deadlock());
    }

    public function testReconnectsWhenConnectionLost(): void
    {
        $connects = 0;
        $calls = 0;
        $this->connection($connects)->retry(function () use (&$calls) {
            if (++$calls === 1) {
                throw $this->goneAway();
            }
        });

        $this->assertSame(2, $connects);
    }

    public function testDoesNotRetryOtherErrors(): void
    {
        $calls = 0;
        try {
            $this->connection()->retry(function (PDO $pdo) use (&$calls) {
                $calls++;
                $pdo->exec('select * from missing_table');
            });
        } catch (PDOException) {
        }

        $this->assertSame(1, $calls);
    }
}
