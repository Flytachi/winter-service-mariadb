<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\MariaDb\Tests\Integration;

/**
 * The start script against a real server: a first start initialises the data, a later
 * one keeps it; a directory of something else is refused; the application's user, its
 * password and its database follow the code on every start; the user has no rights past
 * its database; root is the system's root's only, the check's account sees nothing; over
 * TCP only the user is let in, with its password.
 */
final class StartScriptTest extends ServerCase
{
    public function test_a_first_start_initialises_the_data_and_creates_the_database(): void
    {
        $db = $this->mariadb(user: 'app', password: 'pw', database: 'shop');

        self::assertTrue($this->start($db), $this->serverOutput());

        self::assertStringContainsString('initialised MariaDB 11.8', $this->serverOutput());
        self::assertStringContainsString('created database shop for app.', $this->serverOutput());
        $pdo = $this->app($db);
        self::assertSame('shop', $pdo->query('SELECT DATABASE()')->fetchColumn());
        self::assertSame('app@localhost', $pdo->query('SELECT CURRENT_USER()')->fetchColumn());
        self::assertSame('utf8mb4', $pdo->query("SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'shop'")->fetchColumn());
    }

    public function test_without_a_database_the_application_gets_one_named_after_its_user(): void
    {
        $db = $this->mariadb();
        self::assertTrue($this->start($db), $this->serverOutput());

        self::assertSame('winter', $this->app($db)->query('SELECT DATABASE()')->fetchColumn());
    }

    public function test_the_user_has_its_database_and_nothing_past_it(): void
    {
        $db = $this->mariadb(user: 'app', password: 'pw', database: 'shop');
        self::assertTrue($this->start($db), $this->serverOutput());
        $pdo = $this->app($db);

        $pdo->exec('CREATE TABLE notes (id INT PRIMARY KEY, body TEXT)');
        $pdo->exec("INSERT INTO notes VALUES (1, 'kept')");
        self::assertSame('kept', $pdo->query('SELECT body FROM notes')->fetchColumn());

        foreach ([
            'CREATE DATABASE other',
            'SELECT * FROM mysql.user',
            "CREATE USER 'x'@'localhost'",
            "GRANT ALL ON *.* TO 'app'@'localhost'",
            'SET GLOBAL max_connections = 10',
        ] as $sql) {
            try {
                $pdo->exec($sql);
                self::fail("allowed: {$sql}");
            } catch (\PDOException $e) {
                self::assertMatchesRegularExpression('/denied|privilege/i', $e->getMessage(), $sql);
            }
        }
    }

    public function test_a_later_start_keeps_the_data(): void
    {
        $db = $this->mariadb(password: 'pw');
        self::assertTrue($this->start($db), $this->serverOutput());
        $this->app($db)->exec('CREATE TABLE kept (id INT)');
        self::assertSame(0, $this->stop(), 'a normal shutdown exits 0');
        self::assertStringContainsString('Shutdown complete', $this->serverOutput());

        self::assertTrue($this->start($db), $this->serverOutput());
        self::assertStringNotContainsString('initialised', $this->serverOutput());
        self::assertStringNotContainsString('created database', $this->serverOutput());
        self::assertSame('kept', $this->app($db)->query("SHOW TABLES LIKE 'kept'")->fetchColumn());
    }

    public function test_a_directory_of_something_else_is_refused(): void
    {
        mkdir($this->dir . '/data');
        file_put_contents($this->dir . '/data/notes.txt', 'mine');

        self::assertSame(1, $this->failedStart($this->mariadb()));
        self::assertStringContainsString('holds files but no MariaDB data', $this->serverOutput());
        self::assertSame('mine', file_get_contents($this->dir . '/data/notes.txt'));
    }

    public function test_the_password_follows_the_code(): void
    {
        $first = $this->mariadb(password: 'old');
        self::assertTrue($this->start($first), $this->serverOutput());
        $this->stop();

        $second = $this->mariadb(password: 'new');
        self::assertTrue($this->start($second), $this->serverOutput());
        self::assertSame('winter', $this->app($second)->query('SELECT DATABASE()')->fetchColumn());
        try {
            $this->app($second, 'old');
            self::fail('the old password still works');
        } catch (\PDOException $e) {
            self::assertStringContainsString('Access denied', $e->getMessage());
        }
    }

    public function test_over_tcp_only_the_user_with_its_password_and_root_never(): void
    {
        $port = $this->freePort();
        $db = $this->mariadb(host: '127.0.0.1', port: $port, user: 'app', password: 'pw', database: 'shop');
        self::assertTrue($this->start($db), $this->serverOutput());

        self::assertSame('app@%', $this->tcp($db, 'app', 'pw')->query('SELECT CURRENT_USER()')->fetchColumn());
        foreach ([['app', 'wrong'], ['root', '']] as [$user, $password]) {
            try {
                $this->tcp($db, $user, $password);
                self::fail("{$user} got in over TCP");
            } catch (\PDOException $e) {
                self::assertStringContainsString('Access denied', $e->getMessage(), $user);
            }
        }
    }

    public function test_root_is_the_systems_root_only_and_a_forgotten_user_is_refused(): void
    {
        $db = $this->mariadb(password: 'pw');
        self::assertTrue($this->start($db), $this->serverOutput());

        foreach (['root', 'winter'] as $user) {
            try {
                $this->bare($db, $user);
                self::fail("{$user} got in through the socket without a password");
            } catch (\PDOException $e) {
                self::assertStringContainsString('Access denied', $e->getMessage(), $user);
            }
        }
        self::assertSame(['localhost unix_socket'], $this->bootstrapQuery(
            $db,
            "SELECT CONCAT(Host, ' ', JSON_VALUE(Priv, '$.plugin')) FROM mysql.global_priv WHERE User = 'root'",
        ), 'root: the socket, for the system root');
    }

    public function test_the_checks_account_sees_no_data(): void
    {
        $db = $this->mariadb(password: 'pw', database: 'shop');
        self::assertTrue($this->start($db), $this->serverOutput());
        $this->app($db)->exec('CREATE TABLE secrets (v TEXT)');

        $check = $this->bare($db, 'healthcheck');
        foreach (['SELECT * FROM shop.secrets', 'SELECT * FROM mysql.user', 'CREATE DATABASE x'] as $sql) {
            try {
                $check->query($sql);
                self::fail("healthcheck may: {$sql}");
            } catch (\PDOException $e) {
                self::assertMatchesRegularExpression('/denied/i', $e->getMessage(), $sql);
            }
        }
        self::assertStringNotContainsString('Access denied', $this->serverOutput(), 'the checks log no refused login');
    }

    public function test_without_a_host_the_tcp_account_goes(): void
    {
        $port = $this->freePort();
        self::assertTrue($this->start($this->mariadb(host: '127.0.0.1', port: $port, password: 'pw')), $this->serverOutput());
        $this->stop();

        $db = $this->mariadb(password: 'pw');
        self::assertTrue($this->start($db), $this->serverOutput());

        self::assertSame(['localhost'], $this->bootstrapQuery($db, "SELECT Host FROM mysql.global_priv WHERE User = 'winter'"));
        self::assertSame(['localhost'], $this->bootstrapQuery($db, "SELECT Host FROM mysql.global_priv WHERE User = 'root'"));
    }

    public function test_the_time_zone_reaches_the_server(): void
    {
        $db = $this->mariadb(timezone: 'Asia/Tashkent');
        self::assertTrue($this->start($db), $this->serverOutput());

        $hours = $this->app($db)->query('SELECT TIMESTAMPDIFF(HOUR, UTC_TIMESTAMP(), NOW())')->fetchColumn();
        self::assertSame('5', (string) $hours, 'NOW() is in Tashkent (+05)');
    }

    public function test_settings_reach_the_server(): void
    {
        $db = $this->mariadb(settings: ['max_connections' => 42, 'performance_schema' => false]);
        self::assertTrue($this->start($db), $this->serverOutput());

        $pdo = $this->app($db);
        self::assertSame('42', (string) $pdo->query('SELECT @@max_connections')->fetchColumn());
        self::assertSame('0', (string) $pdo->query('SELECT @@performance_schema')->fetchColumn());
    }

    public function test_the_password_is_not_on_the_servers_command_line(): void
    {
        self::assertTrue($this->start($this->mariadb(password: 'hidden-pw')), $this->serverOutput());

        exec('ps -o args', $lines);
        self::assertSame([], preg_grep('/hidden-pw/', $lines), 'no process shows it');
    }
}
