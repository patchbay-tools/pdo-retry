<?php

declare(strict_types=1);

namespace Halvorsen\PdoRetry;

use PDOException;

/**
 * Decides whether a PDOException is worth retrying, and whether the connection has to be
 * thrown away first.
 */
class ErrorClassifier
{
    // SQLSTATEs: 40001 serialization failure, 40P01 deadlock (PostgreSQL)
    protected const RETRY_STATES = ['40001', '40P01'];

    // MySQL driver codes: 1213 deadlock, 1205 lock wait timeout
    protected const RETRY_CODES = [1213, 1205];

    // MySQL 2006 server gone away, 2013 lost connection during query
    protected const LOST_CODES = [2006, 2013];

    protected const LOST_MESSAGES = [
        'server has gone away',
        'lost connection',
        'no connection to the server',
        'server closed the connection unexpectedly',
        'connection timed out',
        'broken pipe',
        'ssl connection has been closed unexpectedly',
    ];

    public function isTransient(PDOException $e): bool
    {
        $state = $this->sqlState($e);
        $code = (int) ($e->errorInfo[1] ?? 0);

        return in_array($state, static::RETRY_STATES, true)
            || in_array($code, static::RETRY_CODES, true)
            || $this->isConnectionLost($e);
    }

    public function isConnectionLost(PDOException $e): bool
    {
        if (in_array((int) ($e->errorInfo[1] ?? 0), static::LOST_CODES, true)) {
            return true;
        }

        // SQLSTATE class 08 is "connection exception"
        if (str_starts_with($this->sqlState($e), '08')) {
            return true;
        }

        $message = strtolower($e->getMessage());
        foreach (static::LOST_MESSAGES as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function sqlState(PDOException $e): string
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode());
    }
}
