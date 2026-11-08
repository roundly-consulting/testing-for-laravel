<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Concurrency;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\PackageTestCase;
use RuntimeException;
use Throwable;

/**
 * A second database session in a forked process, for real-engine races: a lock that must
 * hold, a unique key two writers hit at once, a counter two requests bump together.
 *
 * ```php
 * it('serialises two redemptions', function (): void {
 *     DB::beginTransaction();
 *     Coupon::query()->lockForUpdate()->find($coupon->id);
 *
 *     $racer = (new Racer)
 *         ->run(fn (): string => Coupons::redeem($coupon->code) ? 'redeemed' : 'refused')
 *         ->waitUntilBlockedOrDone();
 *
 *     Coupons::redeem($coupon->code);
 *     DB::commit();
 *
 *     expect($racer->outcome())->toBe('refused');
 * })->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl + posix');
 * ```
 *
 * The child:
 *
 *  - runs the operation on its **own** connection ({@see self::CONNECTION}, built by
 *    {@see DriverMatrix::connectionConfig()} — the worker's database under `--parallel`), never
 *    the parent's: it shares the parent's sessions, and closing one ends the parent's server
 *    session mid-test;
 *  - writes its outcome to a file and ends with `posix_kill(SIGKILL)` — no shutdown handlers, no
 *    destructors. A normal exit would close the sessions it shares with the parent, the kept
 *    schema's included ({@see PackageTestCase::keepsMigratedSchema()});
 *  - an operation that throws reports `error: <class>: <message>`; one that calls `exit()` is
 *    still SIGKILLed, from a shutdown function, and reports that it exited without an outcome.
 *
 * Needs `ext-pcntl` and `ext-posix` (suggested, not required: a host without them gets a
 * visible skip through {@see self::available()}, or a clear exception from {@see self::run()}).
 * A race is only meaningful on a real engine — an in-memory SQLite child starts with an empty
 * database of its own — so {@see self::available()} also requires pgsql or mysql.
 */
final class Racer
{
    /**
     * The connection the forked operation runs on — its default connection in the child.
     */
    public const string CONNECTION = 'racer';

    /**
     * The parent's connection that watches for the racer's lock wait.
     */
    private const string PROBE = 'racer_probe';

    /**
     * Engines where a second session sees the parent's committed rows and its locks.
     *
     * @var list<string>
     */
    private const array ENGINES = ['pgsql', 'mysql'];

    private const string EXITED = 'error: the racer exited without reporting an outcome';

    private const string DIED = 'error: the racer died without reporting an outcome';

    /**
     * Prefixes every report, so an empty outcome stays apart from a child that never wrote one.
     */
    private const string REPORTED = '>';

    /**
     * Where the child reports; created when the racer runs, removed when its outcome is read.
     */
    private string $file = '';

    private int $pid = 0;

    private bool $reaped = false;

    /**
     * Whether a real race can run here: a real engine on this leg, and a PHP that can fork.
     */
    public static function available(): bool
    {
        return in_array(DriverMatrix::driver(), self::ENGINES, true) && self::canFork();
    }

    /**
     * Fork, run the operation in the child, and return at once in the parent.
     *
     * @param  Closure(): string  $operation  returns the outcome to report
     */
    public function run(Closure $operation): self
    {
        if ($this->pid !== 0) {
            throw new LogicException('A racer runs once: start a new Racer for another race.');
        }

        if (! self::canFork()) {
            throw new RuntimeException(
                'Racer needs the pcntl and posix extensions to fork a second session. Install them, '
                .'or skip the test with ->skip(fn (): bool => ! Racer::available()).',
            );
        }

        $file = tempnam(sys_get_temp_dir(), 'roundly-racer-');

        if ($file === false) {
            throw new RuntimeException('Racer could not create the file its racer reports to.');
        }

        $this->file = $file;
        $pid = pcntl_fork();

        if ($pid === -1) {
            @unlink($file);

            throw new RuntimeException('Racer could not fork a second process.');
        }

        if ($pid !== 0) {
            $this->pid = $pid;

            return $this;
        }

        $this->runInChild($operation);
    }

    /**
     * Return once the racer waits on a lock the parent holds, or has finished because nothing
     * blocked it. A racer that does neither within the timeout is killed, and this throws.
     */
    public function waitUntilBlockedOrDone(float $timeoutSeconds = 30.0): self
    {
        $this->assertStarted();

        $probe = in_array(DriverMatrix::driver(), self::ENGINES, true) ? $this->probe() : null;
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            if ($this->finished()) {
                return $this;
            }

            if ($probe !== null && (int) $probe->selectOne($this->lockWaitSql())->n > 0) {
                return $this;
            }

            usleep(25_000);
        } while (microtime(true) < $deadline);

        posix_kill($this->pid, SIGKILL);
        $this->reap(blocking: true);
        file_put_contents($this->file, self::REPORTED."error: the racer was killed after neither blocking nor finishing within {$timeoutSeconds} s");

        throw new RuntimeException("The racer neither blocked nor finished within {$timeoutSeconds} s.");
    }

    /**
     * Wait for the racer to end and return what it reported.
     */
    public function outcome(): string
    {
        $this->assertStarted();
        $this->reap(blocking: true);

        $report = is_file($this->file) ? (string) file_get_contents($this->file) : '';
        @unlink($this->file);
        DB::purge(self::PROBE);

        // Nothing written: the child was killed from outside, or crashed before it could report.
        return str_starts_with($report, self::REPORTED) ? substr($report, strlen(self::REPORTED)) : self::DIED;
    }

    private static function canFork(): bool
    {
        return function_exists('pcntl_fork') && function_exists('posix_kill');
    }

    /**
     * The child's whole life. It never returns: every path ends in SIGKILL.
     *
     * @param  Closure(): string  $operation
     */
    private function runInChild(Closure $operation): never
    {
        $file = $this->file;

        // An operation that calls exit() would otherwise run the destructors that close the
        // parent's sessions. Shutdown functions run before them.
        register_shutdown_function(static function () use ($file): void {
            clearstatcache();

            if ((int) @filesize($file) === 0) {
                file_put_contents($file, self::REPORTED.self::EXITED);
            }

            posix_kill(posix_getpid(), SIGKILL);
        });

        try {
            config()->set('database.connections.'.self::CONNECTION, DriverMatrix::connectionConfig(DriverMatrix::driver()));
            DB::setDefaultConnection(self::CONNECTION);
            $outcome = $operation();
        } catch (Throwable $exception) {
            $outcome = 'error: '.$exception::class.': '.$exception->getMessage();
        }

        file_put_contents($file, self::REPORTED.$outcome);
        posix_kill(posix_getpid(), SIGKILL);

        exit(1); // unreachable: SIGKILL cannot be caught
    }

    private function probe(): Connection
    {
        config()->set('database.connections.'.self::PROBE, DriverMatrix::connectionConfig(DriverMatrix::driver()));
        DB::purge(self::PROBE);

        return DB::connection(self::PROBE);
    }

    /**
     * A lock wait in this test's database only: parallel workers share the server, and another
     * worker's wait must not release this one early.
     */
    private function lockWaitSql(): string
    {
        return DriverMatrix::driver() === 'pgsql'
            ? "select count(*) as n from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'"
            : 'select count(*) as n from performance_schema.data_lock_waits w join performance_schema.data_locks l '
                .'on l.engine_lock_id = w.requesting_engine_lock_id where l.object_schema = database()';
    }

    private function finished(): bool
    {
        $this->reap(blocking: false);

        return $this->reaped;
    }

    private function reap(bool $blocking): void
    {
        if ($this->reaped) {
            return;
        }

        if (pcntl_waitpid($this->pid, $status, $blocking ? 0 : WNOHANG) !== 0) {
            $this->reaped = true;
        }
    }

    private function assertStarted(): void
    {
        if ($this->pid === 0) {
            throw new LogicException('The racer has not run yet: call run() first.');
        }
    }
}
