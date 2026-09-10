<?php

namespace SuperAdmin\Admin\LogViewer;

/**
 * Scans a log file and produces a health summary: how many entries at each
 * level, and the distinct problems behind them - deduplicated, counted and
 * paired with a suggested fix from {@see LogSolutions}.
 *
 * Unlike the paged reader in {@see LogViewer}, this looks at the whole file
 * (capped to a tail window) so that counts mean something.
 */
class LogAnalyzer
{
    /**
     * Only the last N bytes of a file are scanned, so a multi-gigabyte log
     * cannot stall the page.
     */
    const DEFAULT_MAX_BYTES = 2097152; // 2 MB

    /**
     * How much of the first occurrence's stack trace is kept for matching.
     */
    const TRACE_SAMPLE = 4000;

    /**
     * Levels worth grouping and suggesting fixes for.
     *
     * @var array
     */
    public static $alertLevels = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING'];

    /**
     * Lower number sorts first.
     *
     * @var array
     */
    public static $severity = [
        'EMERGENCY' => 0,
        'ALERT'     => 1,
        'CRITICAL'  => 2,
        'ERROR'     => 3,
        'WARNING'   => 4,
        'NOTICE'    => 5,
        'INFO'      => 6,
        'DEBUG'     => 7,
    ];

    /**
     * Start of a log entry: [time] env.LEVEL: message
     */
    const ENTRY_PATTERN = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}[^\]]*)\]\s*([^\s.]+)\.([A-Z]+):\s?(.*)$/s';

    /**
     * @var string
     */
    protected $path;

    /**
     * @var int
     */
    protected $maxBytes;

    /**
     * @var int
     */
    protected $maxIssues;

    /**
     * @param string   $path
     * @param int|null $maxBytes
     * @param int      $maxIssues
     */
    public function __construct($path, $maxBytes = null, $maxIssues = 8)
    {
        $this->path      = $path;
        $this->maxBytes  = $maxBytes ? (int) $maxBytes : self::DEFAULT_MAX_BYTES;
        $this->maxIssues = (int) $maxIssues;
    }

    /**
     * Build the summary.
     *
     * @return array
     */
    public function analyze()
    {
        $summary = [
            'levels'     => [],
            'total'      => 0,
            'alerts'     => 0,
            'issues'     => [],
            'issueCount' => 0,
            'scanned'    => 0,
            'size'       => 0,
            'truncated'  => false,
            'firstSeen'  => null,
            'lastSeen'   => null,
        ];

        if (!is_file($this->path) || !is_readable($this->path)) {
            return $summary;
        }

        $size = filesize($this->path);

        $summary['size'] = $size;

        if (!$size) {
            return $summary;
        }

        $start = 0;

        if ($size > $this->maxBytes) {
            $start                = $size - $this->maxBytes;
            $summary['truncated'] = true;
        }

        $summary['scanned'] = $size - $start;

        $handle = @fopen($this->path, 'rb');

        if (!$handle) {
            return $summary;
        }

        if ($start) {
            fseek($handle, $start);
            fgets($handle); // drop the partial line we landed in the middle of
        }

        $groups  = [];
        $current = null;

        while (($line = fgets($handle)) !== false) {
            if (preg_match(self::ENTRY_PATTERN, $line, $matches)) {
                if ($current !== null) {
                    $this->collect($current, $summary, $groups);
                }

                $current = [
                    'time'    => $this->normalizeTime($matches[1]),
                    'env'     => $matches[2],
                    'level'   => strtoupper($matches[3]),
                    'message' => rtrim($matches[4]),
                    'trace'   => '',
                ];

                continue;
            }

            // Continuation line - part of the stack trace of the open entry.
            if ($current !== null && strlen($current['trace']) < self::TRACE_SAMPLE) {
                $current['trace'] .= $line;
            }
        }

        if ($current !== null) {
            $this->collect($current, $summary, $groups);
        }

        fclose($handle);

        $summary['issueCount'] = count($groups);
        $summary['issues']     = $this->rank($groups);

        return $summary;
    }

    /**
     * Fold one parsed entry into the running totals and issue groups.
     *
     * @param array $entry
     * @param array $summary
     * @param array $groups
     *
     * @return void
     */
    protected function collect(array $entry, array &$summary, array &$groups)
    {
        $level = $entry['level'];

        $summary['levels'][$level] = isset($summary['levels'][$level]) ? $summary['levels'][$level] + 1 : 1;
        $summary['total']++;

        if ($summary['firstSeen'] === null || $entry['time'] < $summary['firstSeen']) {
            $summary['firstSeen'] = $entry['time'];
        }

        if ($summary['lastSeen'] === null || $entry['time'] > $summary['lastSeen']) {
            $summary['lastSeen'] = $entry['time'];
        }

        if (!in_array($level, self::$alertLevels, true)) {
            return;
        }

        $summary['alerts']++;

        $message = $this->stripContext($entry['message']);
        $key     = $level.'|'.$this->fingerprint($message);

        if (isset($groups[$key])) {
            $groups[$key]['count']++;
            $groups[$key]['last'] = $entry['time'];

            return;
        }

        $groups[$key] = [
            'level'     => $level,
            'count'     => 1,
            'first'     => $entry['time'],
            'last'      => $entry['time'],
            'message'   => $message,
            'exception' => $this->exceptionClass($entry['message'].' '.$entry['trace']),
            'location'  => $this->location($entry['message'].' '.$entry['trace']),
            'haystack'  => substr($entry['message'].' '.$entry['trace'], 0, self::TRACE_SAMPLE),
        ];
    }

    /**
     * Sort the groups by severity then frequency, keep the top N, and attach a
     * suggested fix to each.
     *
     * @param array $groups
     *
     * @return array
     */
    protected function rank(array $groups)
    {
        $issues = array_values($groups);

        usort($issues, function ($a, $b) {
            $sa = isset(self::$severity[$a['level']]) ? self::$severity[$a['level']] : 99;
            $sb = isset(self::$severity[$b['level']]) ? self::$severity[$b['level']] : 99;

            if ($sa !== $sb) {
                return $sa - $sb;
            }

            if ($a['count'] !== $b['count']) {
                return $b['count'] - $a['count'];
            }

            return strcmp($b['last'], $a['last']);
        });

        $issues = array_slice($issues, 0, $this->maxIssues);

        foreach ($issues as $index => $issue) {
            $issues[$index]['solution'] = LogSolutions::resolve($issue['haystack'], $issue['level']);

            unset($issues[$index]['haystack']);
        }

        return $issues;
    }

    /**
     * Laravel writes either "2026-09-08 10:00:00" or an ISO timestamp with
     * microseconds and an offset. Flatten both so string comparison orders
     * them correctly and the summary reads consistently.
     *
     * @param string $time
     *
     * @return string
     */
    protected function normalizeTime($time)
    {
        return substr(str_replace('T', ' ', trim($time)), 0, 19);
    }

    /**
     * Drop the JSON exception context Laravel appends to the message line.
     *
     * @param string $message
     *
     * @return string
     */
    protected function stripContext($message)
    {
        $position = strpos($message, ' {"exception":');

        if ($position !== false) {
            $message = substr($message, 0, $position);
        }

        return trim(preg_replace('/\s+/', ' ', $message));
    }

    /**
     * Reduce a message to a shape that is stable across occurrences, so that
     * "User 12 not found" and "User 4471 not found" count as one issue.
     *
     * @param string $message
     *
     * @return string
     */
    protected function fingerprint($message)
    {
        $signature = $message;

        $signature = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '{uuid}', $signature);
        $signature = preg_replace('/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/', '{email}', $signature);
        $signature = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '{ip}', $signature);
        $signature = preg_replace('#\b(?:/[\w.-]+){2,}#', '{path}', $signature);
        $signature = preg_replace('#\b[A-Za-z]:\\\\[^\s:"\']+#', '{path}', $signature);
        $signature = preg_replace('/\b0x[0-9a-f]+\b/i', '{hex}', $signature);

        // Collapse quoted values ("phone", 'shop.orders') so the same problem
        // about different keys/tables counts once. Anything containing a
        // backslash is left alone - that is a class name, and worth keeping.
        $signature = preg_replace('/"[^"\\\\]{0,120}"/', '{s}', $signature);
        $signature = preg_replace("/'[^'\\\\]{0,120}'/", '{s}', $signature);

        $signature = preg_replace('/\d+/', '{n}', $signature);

        return trim(preg_replace('/\s+/', ' ', $signature));
    }

    /**
     * Pull the exception class name out of a message or its context.
     *
     * @param string $haystack
     *
     * @return string
     */
    protected function exceptionClass($haystack)
    {
        if (preg_match('/\(([A-Za-z0-9_\\\\]+)\(code:/', $haystack, $m)) {
            return $m[1];
        }

        if (preg_match('/^([A-Za-z0-9_]+(?:\\\\[A-Za-z0-9_]+)*(?:Exception|Error))\s*:/', ltrim($haystack), $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Pull the first "file.php:123" reference out of a message or its trace,
     * preferring a frame inside the application over one in vendor/.
     *
     * @param string $haystack
     *
     * @return string
     */
    protected function location($haystack)
    {
        $pattern = '#((?:/|[A-Za-z]:\\\\)[^\s:()"\']+\.php)(?::| on line )(\d+)#';

        if (!preg_match_all($pattern, $haystack, $matches, PREG_SET_ORDER)) {
            return '';
        }

        $fallback = '';

        foreach ($matches as $match) {
            $file = str_replace('\\', '/', $match[1]);
            $line = $match[2];

            // Frames inside vendor/ are framework plumbing - the caller wants
            // the first frame that belongs to the application.
            if (strpos($file, '/vendor/') !== false) {
                continue;
            }

            // Shorten to the project-relative part when we can recognise it.
            // The greedy prefix makes this match the *last* such directory, so
            // /var/www/app/app/Services/X.php resolves to app/Services/X.php.
            if (preg_match('#^.*/((?:app|routes|config|database|resources|src|tests)/[^\s]*)$#', $file, $short)) {
                return $short[1].':'.$line;
            }

            if ($fallback === '') {
                $fallback = basename($file).':'.$line;
            }
        }

        if ($fallback !== '') {
            return $fallback;
        }

        // Everything was vendor code - show the topmost frame rather than nothing.
        return basename(str_replace('\\', '/', $matches[0][1])).':'.$matches[0][2];
    }
}
