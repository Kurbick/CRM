<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class TwoConnectionDatabaseHarness
{
    private const TIMEOUT_SECONDS = 10;

    /**
     * @param  callable(): mixed  $first
     * @param  callable(): mixed  $second
     * @return array{
     *     first: array<string, mixed>,
     *     second: array<string, mixed>,
     *     first_connection_id: int,
     *     second_connection_id: int,
     *     waiting_sql: string
     * }
     */
    public function runBlockedPair(
        callable $first,
        callable $second,
        string $firstPausePattern,
        string $secondWaitPattern,
    ): array {
        $firstWorker = $this->startWorker($first, $firstPausePattern);
        $secondWorker = null;

        try {
            $this->send($firstWorker['socket'], ['command' => 'start']);
            $this->receiveType($firstWorker['socket'], 'running');
            $paused = $this->receiveType($firstWorker['socket'], 'paused');

            $secondWorker = $this->startWorker($second);
            $this->send($secondWorker['socket'], ['command' => 'start']);
            $this->receiveType($secondWorker['socket'], 'running');
            $waitingSql = $this->waitForActiveQuery(
                $secondWorker['connection_id'],
                $secondWaitPattern,
            );

            $this->send($firstWorker['socket'], ['command' => 'release']);
            $firstResult = $this->receiveType($firstWorker['socket'], 'result');
            $secondResult = $this->receiveType($secondWorker['socket'], 'result');

            $this->waitForWorker($firstWorker['pid']);
            $this->waitForWorker($secondWorker['pid']);

            return [
                'first' => $firstResult,
                'second' => $secondResult,
                'first_connection_id' => $firstWorker['connection_id'],
                'second_connection_id' => $secondWorker['connection_id'],
                'waiting_sql' => $waitingSql,
                'paused_sql' => (string) ($paused['sql'] ?? ''),
            ];
        } finally {
            $this->closeWorker($firstWorker);
            if ($secondWorker !== null) {
                $this->closeWorker($secondWorker);
            }
        }
    }

    /**
     * @param  callable(): mixed  $operation
     * @return array{pid: int, socket: resource, connection_id: int}
     */
    private function startWorker(callable $operation, ?string $pausePattern = null): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create concurrency synchronization sockets.');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork concurrency worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runWorker($sockets[1], $operation, $pausePattern);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], self::TIMEOUT_SECONDS);
        $ready = $this->receiveType($sockets[0], 'ready');

        return [
            'pid' => $pid,
            'socket' => $sockets[0],
            'connection_id' => (int) $ready['connection_id'],
        ];
    }

    /** @param resource $socket @param callable(): mixed $operation */
    private function runWorker($socket, callable $operation, ?string $pausePattern): never
    {
        stream_set_timeout($socket, self::TIMEOUT_SECONDS);

        try {
            DB::purge('mysql');
            $connection = DB::connection('mysql');
            $connection->statement('SET SESSION innodb_lock_wait_timeout = 8');
            $connectionId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->send($socket, [
                'type' => 'ready',
                'connection_id' => $connectionId,
            ]);
            $this->receiveCommand($socket, 'start');
            $this->send($socket, ['type' => 'running']);

            if ($pausePattern !== null) {
                $paused = false;
                DB::listen(function ($query) use ($socket, $pausePattern, &$paused): void {
                    if ($paused || preg_match($pausePattern, $query->sql) !== 1) {
                        return;
                    }

                    $paused = true;
                    $this->send($socket, [
                        'type' => 'paused',
                        'sql' => $query->sql,
                    ]);
                    $this->receiveCommand($socket, 'release');
                });
            }

            $value = $operation();
            $this->send($socket, [
                'type' => 'result',
                'ok' => true,
                'value' => $value,
            ]);
        } catch (Throwable $exception) {
            $this->send($socket, [
                'type' => 'result',
                'ok' => false,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ]);
        } finally {
            DB::disconnect('mysql');
            fclose($socket);
        }

        exit(0);
    }

    private function waitForActiveQuery(int $connectionId, string $pattern): string
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        do {
            foreach (DB::select('SHOW FULL PROCESSLIST') as $process) {
                if ((int) $process->Id !== $connectionId || ! is_string($process->Info)) {
                    continue;
                }

                if (preg_match($pattern, $process->Info) === 1) {
                    return $process->Info;
                }
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Connection {$connectionId} was not observed waiting on the expected lock query.");
    }

    /** @param resource $socket @param array<string, mixed> $message */
    private function send($socket, array $message): void
    {
        $encoded = json_encode($message, JSON_THROW_ON_ERROR)."\n";
        if (fwrite($socket, $encoded) !== strlen($encoded)) {
            throw new RuntimeException('Unable to write concurrency synchronization message.');
        }
        fflush($socket);
    }

    /** @param resource $socket @return array<string, mixed> */
    private function receiveType($socket, string $expectedType): array
    {
        $message = $this->receive($socket);
        if (($message['type'] ?? null) !== $expectedType) {
            throw new RuntimeException("Expected concurrency message {$expectedType}.");
        }

        return $message;
    }

    /** @param resource $socket */
    private function receiveCommand($socket, string $expectedCommand): void
    {
        $message = $this->receive($socket);
        if (($message['command'] ?? null) !== $expectedCommand) {
            throw new RuntimeException("Expected concurrency command {$expectedCommand}.");
        }
    }

    /** @param resource $socket @return array<string, mixed> */
    private function receive($socket): array
    {
        $line = fgets($socket);
        if ($line === false) {
            $metadata = stream_get_meta_data($socket);
            $reason = ($metadata['timed_out'] ?? false) ? 'timed out' : 'closed';
            throw new RuntimeException("Concurrency synchronization socket {$reason}.");
        }

        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }

    private function waitForWorker(int $pid): void
    {
        $result = pcntl_waitpid($pid, $status);
        if ($result !== $pid || ! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException("Concurrency worker {$pid} did not exit cleanly.");
        }
    }

    /** @param array{pid: int, socket: resource, connection_id: int} $worker */
    private function closeWorker(array $worker): void
    {
        if (is_resource($worker['socket'])) {
            fclose($worker['socket']);
        }

        $result = pcntl_waitpid($worker['pid'], $status, WNOHANG);
        if ($result === 0) {
            posix_kill($worker['pid'], SIGTERM);
            pcntl_waitpid($worker['pid'], $status);
        }
    }
}
