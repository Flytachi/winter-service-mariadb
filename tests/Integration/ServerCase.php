<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\MariaDb\Tests\Integration;

use Flytachi\Winter\Service\MariaDb\MariaDb;
use PHPUnit\Framework\TestCase;

/**
 * Runs the start script the way the application's supervisor does — the component's
 * command, in a process of its own — against a data directory of the test's own, and
 * stops it as the supervisor does, with the component's stop signal.
 *
 * Needs MariaDB installed: run through docker/test.sh.
 */
abstract class ServerCase extends TestCase
{
    protected string $dir = '';
    /** @var resource|null */
    private mixed $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $output = '';

    protected function setUp(): void
    {
        if (!is_executable('/usr/bin/mariadbd')) {
            self::markTestSkipped('MariaDB is not installed here: run sh docker/test.sh.');
        }
        $this->dir = sys_get_temp_dir() . '/wsm-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/sock', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->stop();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    protected function mariadb(mixed ...$parameters): MariaDb
    {
        return new MariaDb(...[
            'data' => $this->dir . '/data',
            'socket' => $this->dir . '/sock',
            ...$parameters,
        ]);
    }

    /** Starts it and waits until it answers, or until it has exited. */
    protected function start(MariaDb $db): bool
    {
        $this->output = '';
        $this->process = proc_open($db->component()->command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        foreach ([1, 2] as $fd) {
            stream_set_blocking($pipes[$fd], false);
        }
        $this->pipes = $pipes;
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline) {
            $this->drain();
            if (!proc_get_status($this->process)['running']) {
                return false;
            }
            exec(implode(' ', array_map('escapeshellarg', $db->isReady())) . ' 2>/dev/null', $_, $code);
            if ($code === 0) {
                return true;
            }
            usleep(200_000);
        }
        return false;
    }

    /** Stops it with the component's signal — a normal shutdown — and waits. */
    protected function stop(int $signal = SIGTERM): ?int
    {
        if ($this->process === null) {
            return null;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            posix_kill($status['pid'], $signal);
        }
        $deadline = microtime(true) + 30;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
            $this->drain();
            usleep(100_000);
        }
        $this->drain();
        $code = proc_get_status($this->process)['exitcode'];
        foreach ($this->pipes as $pipe) {
            @fclose($pipe);
        }
        proc_close($this->process);
        $this->process = null;
        return $code;
    }

    /** What the script and the server wrote. */
    protected function serverOutput(): string
    {
        $this->drain();
        return $this->output;
    }

    /** Waits for a start that fails, and returns its exit code. */
    protected function failedStart(MariaDb $db): int
    {
        self::assertFalse($this->start($db), 'it was not supposed to start');
        return (int) $this->stop();
    }

    /** The application, through the socket. */
    protected function app(MariaDb $db, ?string $password = null): \PDO
    {
        return new \PDO($db->dsn(), $db->user, $password ?? $db->password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    /** An account through the socket, without a password. */
    protected function bare(MariaDb $db, string $user): \PDO
    {
        return new \PDO("mysql:unix_socket={$db->socketPath()}", $user, '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * What a query reads from the stopped server's data, in bootstrap mode — as the start
     * script works: the accounts' own tables, which only root sees, and root is the
     * system's here.
     *
     * @return list<string>
     */
    protected function bootstrapQuery(MariaDb $db, string $sql): array
    {
        $this->stop();
        $file = $this->dir . '/query-' . bin2hex(random_bytes(4)) . '.txt';
        $statement = sprintf("%s INTO OUTFILE '%s';", rtrim($sql, ';'), $file);
        exec(sprintf(
            'echo %s | mariadbd --no-defaults --bootstrap --datadir=%s 2>&1',
            escapeshellarg($statement),
            escapeshellarg($db->data),
        ), $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        $rows = (string) @file_get_contents($file);
        return $rows === '' ? [] : explode("\n", rtrim($rows, "\n"));
    }

    protected function tcp(MariaDb $db, string $user, string $password): \PDO
    {
        return new \PDO(
            "mysql:host=127.0.0.1;port={$db->port};dbname={$db->database}",
            $user,
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    protected function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server);
        return $port;
    }

    private function drain(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                $this->output .= (string) stream_get_contents($pipe);
            }
        }
    }
}
