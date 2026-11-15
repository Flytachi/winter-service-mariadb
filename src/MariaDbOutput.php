<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\MariaDb;

use Flytachi\Winter\Kernel\App\OutputLine;
use Flytachi\Winter\Kernel\App\ProgramOutput;

/**
 * MariaDB's log, read into the application's: each entry at its level, with the thread
 * that wrote it in its context when it is a connection's.
 *
 * Reads the server's lines (`2026-11-15 10:59:44 3 [Warning] message`) and the start
 * script's (`winter-mariadb: …`). A tab-led line continues the entry before; any other
 * line it does not know — `Version: '11.8.9-MariaDB' socket: …` — is an entry of its own,
 * at info, as it is.
 *
 * | MariaDB | the log |
 * | --- | --- |
 * | Note | info |
 * | Warning | warning |
 * | ERROR | error |
 */
final readonly class MariaDbOutput implements ProgramOutput
{
    /** The time is the log's own; the hour is padded with a space before 10. */
    private const string LINE = '/^\d{4}-\d\d-\d\d +\d{1,2}:\d\d:\d\d (\d+) \[(Note|Warning|ERROR)\] (.*)$/s';

    private const string SCRIPT = 'winter-mariadb: ';

    private const array LEVELS = ['Note' => 'info', 'Warning' => 'warning', 'ERROR' => 'error'];

    public function line(string $line): OutputLine
    {
        if (str_starts_with($line, self::SCRIPT)) {
            $said = substr($line, strlen(self::SCRIPT));
            foreach (['WARNING' => 'warning', 'ERROR' => 'error'] as $tag => $level) {
                if (str_starts_with($said, "{$tag}: ")) {
                    return OutputLine::entry($level, substr($said, strlen($tag) + 2));
                }
            }
            return OutputLine::entry('info', $said);
        }
        if (preg_match(self::LINE, $line, $m) === 1) {
            [, $thread, $severity, $text] = $m;
            // Thread 0 is the server's own; any other is a connection's.
            $context = $thread !== '0' ? ['thread' => (int) $thread] : [];
            return OutputLine::entry(self::LEVELS[$severity], $text, $context);
        }
        if (trim($line) === '') {
            return OutputLine::skip();
        }
        if (str_starts_with($line, "\t")) {
            return OutputLine::more(substr($line, 1));
        }
        return OutputLine::entry('info', $line);
    }
}
