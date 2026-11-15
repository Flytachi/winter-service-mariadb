<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\MariaDb;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Priority;
use Flytachi\Winter\Kernel\App\Probe;
use Flytachi\Winter\Kernel\App\Service;
use Flytachi\Winter\Kernel\App\ServiceImage;

/**
 * MariaDB inside the application's container: started before the rest (High), ready once
 * it answers on its socket, checked while it runs, restarted when it crashes or hangs,
 * stopped last with a normal shutdown.
 *
 * ```php
 * protected static function components(Components $components): void
 * {
 *     $components->service(new MariaDb(database: 'app', password: env('DB_PASSWORD')));
 * }
 * ```
 *
 * On its first start the data directory is initialised (`mariadb-install-db`); on every
 * start the application's user, its password and its database are kept in step with the
 * code. By default the server listens on its Unix socket only: nothing outside the
 * container reaches it.
 *
 * Two accounts: `root`, for the start script and an administrator, through the socket
 * only, without a password; and the application's (`$user`) — all rights on its database,
 * none past it. An SQL injection through the application's connection reaches that
 * database's data, not the others, nor the accounts.
 *
 * @link https://winterframe.net/docs/services Services inside the application
 */
final readonly class MariaDb implements Service
{
    /** The variable the password reaches the start script in — never the command line. */
    public const string PASSWORD_VARIABLE = 'WINTER_MARIADB_PASSWORD';

    /** The account the start script and an administrator use: through the socket only. */
    public const string SUPERUSER = 'root';

    /** The name of the socket file in the socket directory. */
    public const string SOCKET_FILE = 'mysqld.sock';

    /** The accounts a server has of its own: not one to hand to the application. */
    private const array SYSTEM_USERS = ['root', 'mariadb.sys', 'mysql', 'PUBLIC'];

    /** The databases a server has of its own. */
    private const array SYSTEM_DATABASES = ['mysql', 'information_schema', 'performance_schema', 'sys'];

    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    /** The settings the service sets itself: changed through its parameters. */
    private const array OWN_SETTINGS = [
        'datadir', 'socket', 'pid-file', 'port', 'bind-address', 'skip-networking',
        'character-set-server', 'collation-server', 'default-time-zone', 'user',
    ];

    /** The application's database: the one given, or one named after the user. */
    public string $database;

    /** The server's time zone: the one given, or the application's (`TIME_ZONE`). */
    public string $timezone;

    /**
     * @param string $data The data directory — keep it in a volume.
     * @param string $socket The directory of the Unix socket ({@see SOCKET_FILE}).
     * @param string|null $host Null: the socket only. `127.0.0.1`: TCP inside the container
     *     too. `0.0.0.0`: other containers as well. A host needs a password.
     * @param int $port The TCP port.
     * @param string $user The application's account: all rights on its database, none past
     *     it; created when it is missing.
     * @param string|null $password Its password, asked for on every connection — through the
     *     socket too. Kept in step on every start; null — none (the socket only).
     * @param string|null $database The application's database, created when it is missing;
     *     null — one named after the user.
     * @param string $charset The character set of the server and of the database.
     * @param string|null $collation Their collation; null — the character set's default.
     * @param string|null $timezone The server's time zone, set on every start: what `NOW()`
     *     and `CURDATE()` count by. Null — the application's, `TIME_ZONE` (UTC without it).
     * @param array<string, scalar> $settings Server options, each passed as `--key=value`:
     *     `['innodb_buffer_pool_size' => '256M', 'max_connections' => 200]`.
     * @param list<string> $args Further `mariadbd` arguments, as they are.
     * @param bool $logs Whether MariaDB's log goes into the application's
     *     ({@see MariaDbOutput}): its levels, `LOG_LEVEL`, the line or JSON format. False:
     *     it is written as MariaDB writes it.
     * @param string $name How logs, `app status` and the volume name it.
     * @param Priority $priority When it starts and stops relative to the other components.
     * @param bool $critical Whether the application stops when it cannot run.
     * @param int $retries Restarts after a crash within 30 minutes.
     * @param float $grace Seconds it has to stop before SIGKILL.
     * @param int $startTimeout Seconds it has to become ready; a first start initialises the data.
     * @param Probe|bool|null $alive How it is checked once ready: true (the default) pings it
     *     every 10 s (5 s for an answer, 6 misses in a row mean it hangs); a Probe of your
     *     own; null or false — not checked.
     * @throws \InvalidArgumentException A value MariaDB or the image cannot take.
     */
    public function __construct(
        public string $data = '/var/lib/mysql',
        public string $socket = '/run/mysqld',
        public ?string $host = null,
        public int $port = 3306,
        public string $user = 'winter',
        #[\SensitiveParameter]
        public ?string $password = null,
        ?string $database = null,
        public string $charset = 'utf8mb4',
        public ?string $collation = null,
        ?string $timezone = null,
        public array $settings = [],
        public array $args = [],
        public bool $logs = true,
        public string $name = 'mariadb',
        public Priority $priority = Priority::High,
        public bool $critical = true,
        public int $retries = 3,
        public float $grace = 30.0,
        public int $startTimeout = 60,
        public Probe|bool|null $alive = true,
    ) {
        foreach (['data' => $data, 'socket' => $socket] as $what => $path) {
            if (preg_match('#^/[A-Za-z0-9._/-]+$#', $path) !== 1 || str_contains($path, '..')) {
                throw new \InvalidArgumentException("{$what} must be an absolute path, \"{$path}\" given.");
            }
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("port must be 1–65535, {$port} given.");
        }
        if ($host !== null && preg_match('/^[A-Za-z0-9.:_-]+$/', $host) !== 1) {
            throw new \InvalidArgumentException("host must be an address, \"{$host}\" given.");
        }
        if ($host !== null && ($password === null || $password === '')) {
            throw new \InvalidArgumentException(
                'a host needs a password: over TCP anyone could connect as the application.'
            );
        }
        if ($password !== null && (str_contains($password, "\n") || str_contains($password, "\0"))) {
            throw new \InvalidArgumentException('the password is one line.');
        }
        if (preg_match(self::NAME, $user) !== 1) {
            throw new \InvalidArgumentException("user must be letters, digits and _, \"{$user}\" given.");
        }
        if (in_array($user, self::SYSTEM_USERS, true)) {
            throw new \InvalidArgumentException(
                "user \"{$user}\" is the server's own account: the application connects as one of its own."
            );
        }
        $database ??= $user;
        if (preg_match(self::NAME, $database) !== 1) {
            throw new \InvalidArgumentException("database must be letters, digits and _, \"{$database}\" given.");
        }
        if (in_array(strtolower($database), self::SYSTEM_DATABASES, true)) {
            throw new \InvalidArgumentException(
                "database \"{$database}\" is the server's own: name the application's database."
            );
        }
        $this->database = $database;
        foreach (['charset' => $charset, 'collation' => $collation] as $what => $value) {
            if ($value !== null && preg_match('/^[a-z0-9_]+$/', $value) !== 1) {
                throw new \InvalidArgumentException("{$what} must be a name, as utf8mb4, \"{$value}\" given.");
            }
        }
        $timezone ??= (string) env('TIME_ZONE', 'UTC');
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \InvalidArgumentException(
                "timezone \"{$timezone}\" is not a time zone name, as Asia/Tashkent or UTC."
            );
        }
        $this->timezone = $timezone;
        foreach ($settings as $key => $value) {
            if (preg_match('/^[a-z][a-z0-9_-]*$/', (string) $key) !== 1) {
                throw new \InvalidArgumentException("not a setting name: \"{$key}\".");
            }
            if (in_array(str_replace('_', '-', (string) $key), self::OWN_SETTINGS, true)) {
                throw new \InvalidArgumentException("setting {$key} is the service's own: set it with its parameter.");
            }
            if (!is_scalar($value) || str_contains((string) $value, "\n")) {
                throw new \InvalidArgumentException("setting {$key}: a value is one line.");
            }
        }
        if (!array_is_list($args) || array_filter($args, static fn($arg): bool => !is_string($arg)) !== []) {
            throw new \InvalidArgumentException('args are a list of strings.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("name must be lowercase letters, digits, _ . -, \"{$name}\" given.");
        }
    }

    public function component(): Component
    {
        $script = ['/bin/sh', dirname(__DIR__) . '/bin/winter-mariadb'];
        $options = [
            '--data=' . $this->data,
            '--socket=' . $this->socket,
            '--port=' . $this->port,
            '--user=' . $this->user,
            '--database=' . $this->database,
            '--charset=' . $this->charset,
            '--timezone=' . $this->timezone,
        ];
        if ($this->collation !== null) {
            $options[] = '--collation=' . $this->collation;
        }
        if ($this->host !== null) {
            $options[] = '--host=' . $this->host;
        }
        $server = [];
        foreach ($this->settings as $key => $value) {
            $server[] = '--' . $key . '=' . (is_bool($value) ? ($value ? 'ON' : 'OFF') : (string) $value);
        }
        $command = [...$script, ...$options, '--', ...$server, ...$this->args];
        if ($this->password !== null && $this->password !== '') {
            $command = ['/usr/bin/env', self::PASSWORD_VARIABLE . '=' . $this->password, ...$command];
        }

        return Component::command(
            $command,
            $this->name,
            critical: $this->critical,
            grace: $this->grace,
            priority: $this->priority,
            ready: $this->isReady(),
            alive: $this->aliveProbe(),
            stopSignal: SIGTERM,
            retries: $this->retries,
            startTimeout: $this->startTimeout,
            output: $this->logs ? new MariaDbOutput() : null,
        );
    }

    public function image(): ServiceImage
    {
        return new ServiceImage(
            // tzdata: the server's time zone is the process's, and musl reads it from there.
            packages: ['mariadb', 'mariadb-client', 'tzdata'],
            directories: [$this->data => 0700, $this->socket => 0755],
            volumes: ["{$this->name}-data" => $this->data],
        );
    }

    /** The socket file: what PDO's `unix_socket` and the `mariadb` client take. */
    public function socketPath(): string
    {
        return $this->socket . '/' . self::SOCKET_FILE;
    }

    /** How the application connects: through the socket, to its database — a DSN for PDO. */
    public function dsn(): string
    {
        return sprintf(
            'mysql:unix_socket=%s;dbname=%s;charset=%s',
            $this->socketPath(),
            $this->database,
            $this->charset,
        );
    }

    /**
     * `mariadb-admin ping` as root through the socket: 0 once it answers. root needs no
     * password there, so a check never logs a refused login.
     *
     * @return list<string>
     */
    public function isReady(): array
    {
        return ['mariadb-admin', '--socket=' . $this->socketPath(), '--user=' . self::SUPERUSER, 'ping', '--silent'];
    }

    private function aliveProbe(): ?Probe
    {
        return match (true) {
            $this->alive instanceof Probe => $this->alive,
            $this->alive === true => Probe::command($this->isReady()),
            default => null,
        };
    }
}
