# Winter Service · MariaDB

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-service-mariadb.svg)](https://packagist.org/packages/flytachi/winter-service-mariadb)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-service-mariadb.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-service-mariadb)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Tests](https://github.com/flytachi/winter-service-mariadb/actions/workflows/tests.yml/badge.svg)](https://github.com/flytachi/winter-service-mariadb/actions/workflows/tests.yml)
[![Docs](https://img.shields.io/badge/docs-winterframe.net-blue.svg)](https://winterframe.net/docs/services)

MariaDB inside a Winter application's container, declared in one line. The application
starts it before everything else, waits until it answers, keeps checking it, restarts it
when it crashes or hangs, and stops it last — with a normal shutdown.

```php
$components->service(new MariaDb(database: 'app', password: env('DB_PASSWORD')));
```

It runs the server; to **connect** to it, use PDO or winter-ppa — see *Connecting*.

📖 **[Services in the documentation](https://winterframe.net/docs/services)**

---

## Installation

```bash
composer require flytachi/winter-service-mariadb
```

Requires PHP **8.4+**, `flytachi/winter-kernel` **4.5+** and the `flytachi/winter` image
(Alpine): the server comes from `apk` — MariaDB 11.8 on Alpine 3.24 — and
`call service docker` puts it there.

---

## Quick start

**1. Declare it** in the application:

```php
use Flytachi\Winter\Service\MariaDb\MariaDb;

final class Application extends WinterApplication
{
    protected static function components(Components $components): void
    {
        $components->service(new MariaDb(database: 'app', password: env('DB_PASSWORD')));
    }
}
```

**2. Put it in the image:**

```bash
php call service docker          # Dockerfile block; prints what compose needs
```

The Dockerfile gets `apk add mariadb mariadb-client tzdata` and its directories, owned by
`winter`. Compose is yours: the command prints what to add to `docker-compose.yml`:

```yaml
services:
  server:
    stop_grace_period: 70s
    volumes:
      - mariadb-data:/var/lib/mysql
volumes:
  mariadb-data:
```

- **`stop_grace_period`** — always: HTTP 30 s + MariaDB 30 s + 5 + 5. Docker's default is
  10 s, after which it kills the container mid-shutdown.
- **The volume** — if the database must outlive a recreated container.

```bash
docker compose up -d --build
```

**3. Start it.** On the first start the log says:

```
[2026-11-15 16:12:37] [INFO ] -sys- [1]: Application starting High: mariadb
[2026-11-15 16:12:38] [INFO ] -mariadb- [10]: initialised MariaDB 11.8.9 data in /var/lib/mysql.
[2026-11-15 16:12:38] [INFO ] -mariadb- [10]: created database app for winter.
[2026-11-15 16:12:39] [INFO ] -mariadb- [10]: mariadbd: ready for connections.
[2026-11-15 16:12:39] [INFO ] -sys- [1]: mariadb is up.
[2026-11-15 16:12:39] [INFO ] -sys- [1]: Application starting Normal: Http
```

---

## Parameters

### The server

| Parameter | Default | What it sets |
| --- | --- | --- |
| `data` | `/var/lib/mysql` | The data directory — keep it in a volume |
| `socket` | `/run/mysqld` | The directory of the Unix socket (`mysqld.sock`) |
| `host` | `null` | `null`: the socket only, nothing outside the container reaches it. `127.0.0.1`: TCP inside the container too. `0.0.0.0`: other containers as well. **A host needs a password** |
| `port` | `3306` | The TCP port |
| `user` | `'winter'` | The application's account: all rights on its database, none past it. Created when it is missing |
| `password` | `null` | Its password, asked for on every connection — through the socket too. Kept in step on every start: changed in the code, changed in the server |
| `database` | `null` | The application's database, created when it is missing; `null` — named after the user |
| `charset` | `'utf8mb4'` | The character set of the server and of the database |
| `collation` | `null` | Their collation; `null` — the character set's default |
| `timezone` | `null` | The server's time zone, set on every start: what `NOW()` and `CURDATE()` count by. `null` — the application's `TIME_ZONE` (UTC without it). An IANA name, `Asia/Tashkent` |
| `settings` | `[]` | Server options, each passed as `--key=value`: `['innodb_buffer_pool_size' => '256M', 'max_connections' => 200]`; `true`/`false` become `ON`/`OFF` |
| `args` | `[]` | Further `mariadbd` arguments, as they are |
| `logs` | `true` | Whether MariaDB's log goes into the application's — see *Log* |

The system's option files (`/etc/my.cnf`, `/etc/my.cnf.d/`) are not read: the server is
what these parameters say. Alpine's own turns networking off.

### The component

| Parameter | Default | What it sets |
| --- | --- | --- |
| `name` | `'mariadb'` | How logs and `app status` name it; the volume in the compose hint is `{name}-data` |
| `priority` | `Priority::High` | Starts before the rest, stops after them |
| `critical` | `true` | When it cannot run, the application stops |
| `retries` | `3` | Restarts after a crash within 30 minutes |
| `grace` | `30.0` | Seconds to stop before SIGKILL |
| `startTimeout` | `60` | Seconds to become ready; a first start initialises the data |
| `alive` | `true` | `true`: `mariadb-admin ping` as `healthcheck` every 10 s, 5 s for an answer, 6 misses in a row mean it hangs. A `Probe` of your own. `null` or `false`: not checked |

Every value is checked when the object is made — a relative path, a port out of range, a
host without a password, a name not of letters, digits and `_`, `user: 'root'`,
`database: 'mysql'`, an unknown time zone, a setting the service sets itself
(`bind_address`, `port`…), a value on two lines: each is an `InvalidArgumentException`
saying what is wrong.

---

## What a start does

1. Refuses to start without `mariadbd` in the image: *"Add it to the image: call service docker"*.
2. With no data yet, runs `mariadb-install-db`. A directory that holds files but no
   MariaDB data is refused, not initialised over.
3. Before the server takes connections — in bootstrap mode, without one:
   - `root` keeps the socket, for the system's root only (`unix_socket`): the passwordless
     `root` accounts `mariadb-install-db` makes for `127.0.0.1`, `::1` and the host name go;
   - `healthcheck`, the readiness check's account, is there — with no rights;
   - creates the database when it is missing, and says so;
   - creates the application's account when it is missing, sets its password, grants it
     its database; with a host, the same for TCP (`'user'@'%'`), and without one that
     account goes.
4. Becomes the server (`exec`), so the stop signal reaches MariaDB itself.

The password reaches the script through the environment and the server through a pipe:
it is on no command line and never on disk.

---

## Accounts

| Account | Where from | How it signs in | Rights |
| --- | --- | --- | --- |
| `root` | the socket only | as the system's root (`unix_socket`) | everything: an administrator |
| `healthcheck` | the socket only | without a password | none: the readiness check |
| `user` | the socket; TCP with a host | `password` | all on its database, nothing past it |

An administrator, inside the container, as the system's root:

```bash
docker compose exec -u root server mariadb --socket=/run/mysqld/mysqld.sock -u root
```

The application runs as `winter`, so it is never `root` here: a configuration that forgot
its user — `MySqlDbConfig`'s is `root` by default — gets `Access denied`, not every right.

The application's account cannot create databases or accounts, read `mysql.user`, grant
rights or change global variables: an SQL injection through its connection stays in its
database.

---

## Connecting

The `MariaDb` object knows where its server is:

```php
$db->dsn();          // mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=app;charset=utf8mb4
$db->socketPath();   // /run/mysqld/mysqld.sock
$db->user;           // winter
$db->database;       // app — without database: winter, the user's name
```

Keep how the server runs and how the code connects in **one place** — one method both
the application and the connecting code call.

### With PDO

```php
namespace Main;

use Flytachi\Winter\Service\MariaDb\MariaDb;

final class Db
{
    public static function service(): MariaDb
    {
        return new MariaDb(database: 'app', password: env('DB_PASSWORD'));
    }

    public static function connect(): \PDO
    {
        $db = self::service();
        return new \PDO($db->dsn(), $db->user, $db->password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
```

```php
// bootstrap.php
$components->service(Db::service());

// anywhere
$rows = Db::connect()->query('SELECT id, text FROM records')->fetchAll();
```

### With winter-ppa

The database configuration winter-ppa connects with runs the server too: the socket, the
database and the user cannot drift apart. PDO's MySQL driver takes a socket as
`unix_socket`, not as a host — so the configuration gives the service's DSN:

```php
namespace Main\Configs;

use Flytachi\Winter\Cdo\Config\MySqlDbConfig;
use Flytachi\Winter\Ppa\Pool\PpaPoolConfigInterface;
use Flytachi\Winter\Ppa\Pool\PpaPoolTrait;
use Flytachi\Winter\Service\MariaDb\MariaDb;

class MainDbConfig extends MySqlDbConfig implements PpaPoolConfigInterface
{
    use PpaPoolTrait;

    public int $poolMaxConnections = 10;
    public float $poolWaitTimeout = 5.0;

    /** What the application runs. */
    public static function service(): MariaDb
    {
        return new MariaDb(database: 'app', password: env('DB_PASSWORD'));
    }

    /** How winter-ppa connects to it. */
    public function setUp(): void
    {
        $db = self::service();
        $this->database = $db->database;
        $this->username = $db->user;
        $this->password = $db->password ?? '';
    }

    /** Through the socket: PDO's MySQL driver takes it as unix_socket. */
    public function getDns(): string
    {
        return self::service()->dsn();
    }
}
```

```php
// bootstrap.php
$components->service(MainDbConfig::service());
```

Repositories point at it as at any database configuration:

```php
class RecordRepository extends Repository
{
    protected string $dbConfigClassName = MainDbConfig::class;
    protected string $entityClassName = Record::class;
    public static string $table = 'records';
}
```

Over TCP (`host` set, another container connecting) `getDns()` is not overridden;
`setUp()` sets `host` — `127.0.0.1` or the container's name — and `port`, and the password
is mandatory.

### How many connections

MariaDB's default is `max_connections = 151`. winter-ppa's pool holds its connections
**in every process**, so count:

```
(HTTP workers + the processes, daemons, scheduler, WebSockets that use the database)
  × $poolMaxConnections
  + ~5 — the client, migrations, checks
```

By default that is 1 worker × 5 = 5. It is hit when the workers grow: 8 workers
(`SERVER_WORKERS=8`) × a pool of 20 is 160, and at a peak the server answers
`Too many connections`. Make the pool smaller, or raise the limit —
`settings: ['max_connections' => 300]` — with the container's memory in mind.

---

## Log

MariaDB's log goes into the application's: the same format, the `-mariadb-` channel (blue
in colour).

| MariaDB | Log |
| --- | --- |
| `Note` | `INFO` |
| `Warning` | `WARN` |
| `ERROR` | `ERROR` |

- A connection's line carries its thread in the context: `{"thread":3}`.
- `LOG_LEVEL` applies: `LOG_LEVEL=warning` keeps the warnings and errors only. Without
  `LOG_LEVEL` (the log off) MariaDB's lines are not seen either — the reason it did not
  start among them. `logs: false` leaves its output as MariaDB writes it.
- MariaDB does not log a failed query of a client: that error is the client's.

---

## Stopping

SIGTERM: a normal shutdown — the connections are closed, InnoDB writes what it holds and
the server exits 0. It stops last, after HTTP, so the container needs
`stop_grace_period: 70s` (30 for HTTP, 30 for MariaDB, 5 + 5 margins).

---

## Upgrading

Minor versions (11.8.8 → 11.8.9) come with the image's next build. A new major version
comes with a new Alpine release: MariaDB reads the older data, and `mariadb-upgrade`, run
once by hand after the first start, brings its system tables up to date. Take a dump
before all the same (`mariadb-dump --all-databases`).

---

## Testing

```bash
composer test                     # unit tests
composer test-integration         # against a real MariaDB, in Docker
```

## License

MIT — see [LICENSE](LICENSE).
