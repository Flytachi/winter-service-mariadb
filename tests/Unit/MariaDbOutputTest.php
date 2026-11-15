<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\MariaDb\Tests\Unit;

use Flytachi\Winter\Kernel\App\OutputLine;
use Flytachi\Winter\Service\MariaDb\MariaDbOutput;
use PHPUnit\Framework\TestCase;

/** MariaDB's lines and the start script's, as the application's log reads them. */
final class MariaDbOutputTest extends TestCase
{
    private function read(string $line): OutputLine
    {
        return (new MariaDbOutput())->line($line);
    }

    public function test_a_severity_is_a_level(): void
    {
        foreach (['Note' => 'info', 'Warning' => 'warning', 'ERROR' => 'error'] as $severity => $level) {
            $line = $this->read("2026-11-15 10:59:44 0 [{$severity}] something happened");
            self::assertSame([$level, 'something happened', []], [$line->level, $line->message, $line->context], $severity);
        }
    }

    public function test_a_connections_thread_goes_into_the_context(): void
    {
        $line = $this->read("2026-11-15  9:59:48 3 [Warning] Access denied for user 'winter'@'localhost' (using password: NO)");

        self::assertSame('warning', $line->level);
        self::assertSame(['thread' => 3], $line->context, 'a single-digit hour is padded with a space');
        self::assertStringStartsWith('Access denied', $line->message);
    }

    public function test_the_start_scripts_lines(): void
    {
        $info = $this->read('winter-mariadb: created database shop for app.');
        $error = $this->read('winter-mariadb: ERROR: /var/lib/mysql holds files but no MariaDB data: it is not initialised over them.');

        self::assertSame(['info', 'created database shop for app.'], [$info->level, $info->message]);
        self::assertSame('error', $error->level);
        self::assertStringStartsWith('/var/lib/mysql holds files', $error->message);
    }

    public function test_a_line_it_does_not_know_is_an_entry_as_it_is_and_an_empty_one_is_skipped(): void
    {
        $version = $this->read("Version: '11.8.9-MariaDB'  socket: '/run/mysqld/mysqld.sock'  port: 0  Alpine Linux");

        self::assertSame('info', $version->level);
        self::assertStringStartsWith('Version:', $version->message);
        self::assertTrue($this->read('')->skip);
        self::assertNull($this->read("\tat line 2")->level, 'a tab-led line continues');
    }
}
