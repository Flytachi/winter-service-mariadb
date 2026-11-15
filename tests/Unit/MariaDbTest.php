<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\MariaDb\Tests\Unit;

use Flytachi\Winter\Kernel\App\ComponentKind;
use Flytachi\Winter\Kernel\App\Priority;
use Flytachi\Winter\Kernel\App\Probe;
use Flytachi\Winter\Service\MariaDb\MariaDb;
use Flytachi\Winter\Service\MariaDb\MariaDbOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What the service declares, from its parameters, and what it refuses. */
final class MariaDbTest extends TestCase
{
    public function test_by_default_it_starts_first_is_checked_and_stops_normally(): void
    {
        $component = (new MariaDb())->component();

        self::assertSame(ComponentKind::Command, $component->kind);
        self::assertSame('mariadb', $component->name());
        self::assertSame(Priority::High, $component->priority);
        self::assertTrue($component->critical);
        self::assertSame(3, $component->retries);
        self::assertSame(30.0, $component->grace);
        self::assertSame(SIGTERM, $component->stopSignal, 'a normal shutdown');
        self::assertSame(
            ['mariadb-admin', '--socket=/run/mysqld/mysqld.sock', '--user=root', 'ping', '--silent'],
            $component->ready,
        );
        self::assertSame($component->ready, $component->alive?->command);
        self::assertInstanceOf(MariaDbOutput::class, $component->output);
    }

    public function test_the_parameters_reach_the_start_script(): void
    {
        $command = (new MariaDb(
            data: '/srv/db',
            socket: '/tmp/db',
            host: '127.0.0.1',
            port: 3307,
            user: 'app',
            password: 'pw',
            database: 'shop',
            collation: 'utf8mb4_unicode_ci',
            timezone: 'Asia/Tashkent',
            settings: ['innodb_buffer_pool_size' => '256M', 'max_connections' => 200, 'performance_schema' => false],
            args: ['--log-warnings=1'],
        ))->component()->command;

        self::assertSame(['/usr/bin/env', 'WINTER_MARIADB_PASSWORD=pw', '/bin/sh'], array_slice($command, 0, 3));
        self::assertStringEndsWith('/bin/winter-mariadb', $command[3]);
        self::assertSame([
            '--data=/srv/db',
            '--socket=/tmp/db',
            '--port=3307',
            '--user=app',
            '--database=shop',
            '--charset=utf8mb4',
            '--timezone=Asia/Tashkent',
            '--collation=utf8mb4_unicode_ci',
            '--host=127.0.0.1',
            '--',
            '--innodb_buffer_pool_size=256M',
            '--max_connections=200',
            '--performance_schema=OFF',
            '--log-warnings=1',
        ], array_slice($command, 4));
    }

    public function test_without_a_password_none_goes_to_the_script(): void
    {
        $command = (new MariaDb())->component()->command;

        self::assertSame('/bin/sh', $command[0]);
        self::assertSame([], preg_grep('/WINTER_MARIADB_PASSWORD/', $command));
    }

    public function test_the_database_is_named_after_the_user_unless_given(): void
    {
        self::assertSame('winter', (new MariaDb())->database);
        self::assertSame('app', (new MariaDb(user: 'app'))->database);
        self::assertSame('shop', (new MariaDb(user: 'app', database: 'shop'))->database);
    }

    public function test_the_time_zone_is_the_applications_unless_given(): void
    {
        $before = getenv('TIME_ZONE');
        putenv('TIME_ZONE=Europe/Berlin');
        $_ENV['TIME_ZONE'] = 'Europe/Berlin';
        try {
            self::assertSame('Europe/Berlin', (new MariaDb())->timezone);
            self::assertSame('UTC', (new MariaDb(timezone: 'UTC'))->timezone);
        } finally {
            $before === false ? putenv('TIME_ZONE') : putenv("TIME_ZONE={$before}");
            unset($_ENV['TIME_ZONE']);
        }
        self::assertSame('UTC', (new MariaDb())->timezone, 'UTC without TIME_ZONE');
    }

    public function test_the_image_gets_the_server_its_client_the_zones_and_its_directories(): void
    {
        $image = (new MariaDb(name: 'db'))->image();

        self::assertSame(['mariadb', 'mariadb-client', 'tzdata'], $image->packages);
        self::assertSame(['/var/lib/mysql' => 0700, '/run/mysqld' => 0755], $image->directories);
        self::assertSame(['db-data' => '/var/lib/mysql'], $image->volumes);
    }

    public function test_connecting(): void
    {
        $db = new MariaDb(database: 'shop');

        self::assertSame('/run/mysqld/mysqld.sock', $db->socketPath());
        self::assertSame('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=shop;charset=utf8mb4', $db->dsn());
    }

    public function test_alive_can_be_a_probe_of_its_own_or_none(): void
    {
        $probe = Probe::command(['true'], every: 30);

        self::assertSame($probe, (new MariaDb(alive: $probe))->component()->alive);
        self::assertNull((new MariaDb(alive: false))->component()->alive);
        self::assertNull((new MariaDb(logs: false))->component()->output);
    }

    /** @return iterable<string, array{\Closure(): MariaDb, string}> */
    public static function refusals(): iterable
    {
        yield 'a relative data directory' => [static fn() => new MariaDb(data: 'data'), 'data must be an absolute path'];
        yield 'a port out of range' => [static fn() => new MariaDb(port: 70000), 'port must be 1–65535'];
        yield 'a host without a password' => [static fn() => new MariaDb(host: '0.0.0.0'), 'a host needs a password'];
        yield 'a password on two lines' => [static fn() => new MariaDb(password: "a\nb"), 'the password is one line'];
        yield 'root as the application' => [static fn() => new MariaDb(user: 'root'), "user \"root\" is the server's own account"];
        yield 'a user of other letters' => [static fn() => new MariaDb(user: 'app-1'), 'user must be letters, digits and _'];
        yield 'the server\'s database' => [static fn() => new MariaDb(database: 'mysql'), "database \"mysql\" is the server's own"];
        yield 'a database of other letters' => [static fn() => new MariaDb(database: 'shop;drop'), 'database must be letters, digits and _'];
        yield 'a charset of other letters' => [static fn() => new MariaDb(charset: 'utf8 mb4'), 'charset must be a name'];
        yield 'not a time zone' => [static fn() => new MariaDb(timezone: 'Mars/Olympus'), 'is not a time zone name'];
        yield 'a setting the service sets' => [static fn() => new MariaDb(settings: ['bind_address' => '0.0.0.0']), "setting bind_address is the service's own"];
        yield 'a setting on two lines' => [static fn() => new MariaDb(settings: ['init_connect' => "a\nb"]), 'a value is one line'];
        yield 'args not strings' => [static fn() => new MariaDb(args: [1]), 'args are a list of strings'];
        yield 'a name of other letters' => [static fn() => new MariaDb(name: 'Maria DB'), 'name must be lowercase'];
    }

    #[DataProvider('refusals')]
    public function test_a_value_it_cannot_take_is_refused(\Closure $make, string $why): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($why);
        $make();
    }
}
