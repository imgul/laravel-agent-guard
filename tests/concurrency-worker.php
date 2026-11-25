<?php

declare(strict_types=1);

/**
 * Standalone worker for RedisLedgerConcurrencyTest.
 *
 * Spawned via proc_open as a real PHP file (not an inline `php -r` snippet) so
 * the worker logic is ordinary, reviewable PHP and is immune to shell/inline
 * string-quoting pitfalls. It connects to the same Redis instance/database the
 * parent test uses, applies the same key prefix Laravel's Redis client uses
 * (so it reads/writes the SAME physical key the parent's RedisLedger does),
 * runs the atomic reserve.lua once, and reports the outcome.
 *
 * Usage: php concurrency-worker.php <host> <port> <db> <prefix> <key> <luaPath>
 *
 * stdout: the Lua eval code (0 denied / 1 allowed / 101 soft-exceeded).
 * stderr: a single diagnostic line (db / prefix / spent / code) for triage.
 */

[$host, $port, $db, $prefix, $key, $luaPath] = array_slice($argv, 1);

try {
    if (! extension_loaded('redis')) {
        throw new RuntimeException('redis extension not loaded');
    }

    $r = new Redis();
    $r->connect($host, (int) $port);
    $r->select((int) $db);

    // Laravel's phpredis client applies a key prefix to every key operation.
    // The raw client here must do the same, otherwise the worker reads/writes
    // a different physical key than the parent's RedisLedger (the parent's
    // Redis::del() and spent() operate on the prefixed key).
    $r->setOption(Redis::OPT_PREFIX, (string) $prefix);

    $pre = $r->get($key);

    $script = file_get_contents($luaPath);
    if ($script === false) {
        throw new RuntimeException('cannot read lua script: '.$luaPath);
    }

    $code = $r->eval($script, [$key, 1.0, 0.0, 5.0, 86400], 1);

    fwrite(STDERR, 'db='.var_export($db, true).' prefix='.var_export($prefix, true).' spent='.var_export($pre, true).' code='.var_export($code, true));
    echo is_string($code) ? $code : '';
} catch (Throwable $e) {
    fwrite(STDERR, 'concurrency-worker: '.$e->getMessage());
    exit(1);
}
