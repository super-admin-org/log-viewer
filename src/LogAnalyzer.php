<?php

namespace SuperAdmin\Admin\LogViewer;

/**
 * Scans a log file and produces a health summary: how many entries at each
 * level, the distinct problems behind them - deduplicated, counted and paired
 * with a suggested fix from {@see LogSolutions} - and, separately, the most
 * frequent INFO/DEBUG messages for a picture of normal traffic.
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
     * How much of a raw continuation block (real multi-line traces, not the
     * usual JSON-escaped single line) is kept per open entry.
     */
    const TRACE_SAMPLE = 20000;

    /**
     * Distinct messages are grouped by a normalised fingerprint. This caps how
     * many distinct groups are tracked per file, so a log that is mostly
     * unique noise (e.g. every DEBUG line carries a different payload) cannot
     * grow memory use unbounded.
     */
    const MAX_TRACKED_GROUPS = 4000;

    /**
     * Request input keys that are never shown, even redacted-in-place.
     *
     * @var array
     */
    public static $sensitiveKeys = [
        'password', 'password_confirmation', 'token', '_token', 'secret',
        'api_key', 'apikey', 'authorization', 'card_number', 'cvv', 'ssn',
        'remember_token',
    ];

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
     * @var int
     */
    protected $maxActivity;

    /**
     * @param string   $path
     * @param int|null $maxBytes
     * @param int      $maxIssues
     * @param int      $maxActivity
     */
    public function __construct($path, $maxBytes = null, $maxIssues = 8, $maxActivity = 6)
    {
        $this->path        = $path;
        $this->maxBytes    = $maxBytes ? (int) $maxBytes : self::DEFAULT_MAX_BYTES;
        $this->maxIssues   = (int) $maxIssues;
        $this->maxActivity = (int) $maxActivity;
    }

    /**
     * Build the summary.
     *
     * @return array
     */
    public function analyze()
    {
        $summary = [
            'levels'        => [],
            'total'         => 0,
            'alerts'        => 0,
            'issues'        => [],
            'issueCount'    => 0,
            'activity'      => [],
            'activityCount' => 0,
            'scanned'       => 0,
            'size'          => 0,
            'truncated'     => false,
            'firstSeen'     => null,
            'lastSeen'      => null,
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

            // Continuation line - part of a genuinely multi-line entry (rare;
            // Laravel normally JSON-escapes the whole exception onto one line).
            if ($current !== null && strlen($current['trace']) < self::TRACE_SAMPLE) {
                $current['trace'] .= $line;
            }
        }

        if ($current !== null) {
            $this->collect($current, $summary, $groups);
        }

        fclose($handle);

        $alertGroups    = [];
        $activityGroups = [];

        foreach ($groups as $key => $group) {
            if (in_array($group['level'], self::$alertLevels, true)) {
                $alertGroups[$key] = $group;
            } else {
                $activityGroups[$key] = $group;
            }
        }

        $summary['issueCount']    = count($alertGroups);
        $summary['issues']        = $this->rankIssues($alertGroups);
        $summary['activityCount'] = count($activityGroups);
        $summary['activity']      = $this->rankActivity($activityGroups);

        return $summary;
    }

    /**
     * Fold one parsed entry into the running totals and message groups.
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

        if (in_array($level, self::$alertLevels, true)) {
            $summary['alerts']++;
        }

        // Laravel appends structured context as trailing JSON on the same
        // physical line (json_encode escapes both backslashes and newlines),
        // so decode it once to get the exception text back in its original,
        // readable form rather than regexing the doubled-backslash raw bytes.
        $context       = $this->decodeContext($entry['message']);
        $exceptionText = (is_array($context) && isset($context['exception']) && is_string($context['exception']))
            ? $context['exception']
            : '';

        $displayMessage = $this->cleanCustomMessage($this->stripContext($entry['message']));
        $traceText      = $exceptionText !== '' ? $exceptionText : ($entry['message']."\n".$entry['trace']);

        $key = $level.'|'.$this->fingerprint($displayMessage);

        if (isset($groups[$key])) {
            $groups[$key]['count']++;
            $groups[$key]['last'] = $entry['time'];

            return;
        }

        // A log dominated by unique messages (every DEBUG line carrying a
        // different payload) would otherwise grow one group per line forever.
        if (count($groups) >= self::MAX_TRACKED_GROUPS) {
            return;
        }

        $groups[$key] = [
            'level'     => $level,
            'count'     => 1,
            'first'     => $entry['time'],
            'last'      => $entry['time'],
            'message'   => $displayMessage,
            'exception' => $this->exceptionClass($traceText),
            'location'  => $this->location($traceText),
            'route'     => $this->routeInfo($displayMessage),
            'sql'       => $this->sqlQuery($displayMessage),
            'request'   => $this->requestInfo($context),
            'haystack'  => substr($displayMessage.' '.$traceText, 0, 8000),
        ];
    }

    /**
     * Sort issues by severity then frequency, keep the top N, and attach a
     * suggested fix to each.
     *
     * @param array $groups
     *
     * @return array
     */
    protected function rankIssues(array $groups)
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
     * Rank INFO/DEBUG/NOTICE groups by frequency alone - no fix is suggested
     * for normal application activity, this is purely "what's noisy".
     *
     * @param array $groups
     *
     * @return array
     */
    protected function rankActivity(array $groups)
    {
        $items = array_values($groups);

        usort($items, function ($a, $b) {
            if ($a['count'] !== $b['count']) {
                return $b['count'] - $a['count'];
            }

            return strcmp($b['last'], $a['last']);
        });

        $items = array_slice($items, 0, $this->maxActivity);

        foreach ($items as $index => $item) {
            unset($items[$index]['haystack'], $items[$index]['solution']);
        }

        return $items;
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
     * Find where the trailing JSON context block starts, if there is one.
     * Monolog always appends it as the last thing on the line, so anchor on
     * the last occurrence rather than the first - a message that legitimately
     * contains an earlier ' {"' substring must not cut the display text short.
     *
     * @param string $message
     *
     * @return int|false
     */
    protected function contextBoundary($message)
    {
        $pos = strrpos($message, ' {"exception":');

        if ($pos !== false) {
            return $pos;
        }

        return strrpos($message, ' {"');
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
        $pos = $this->contextBoundary($message);

        if ($pos !== false) {
            $message = substr($message, 0, $pos);
        }

        return trim(preg_replace('/\s+/', ' ', $message));
    }

    /**
     * Decode the trailing JSON context block, if there is one and it parses.
     *
     * @param string $message
     *
     * @return array|null
     */
    protected function decodeContext($message)
    {
        $pos = $this->contextBoundary($message);

        if ($pos === false) {
            return null;
        }

        $decoded = json_decode(substr($message, $pos + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Some apps log exceptions through a custom wrapper instead of Laravel's
     * own formatter, e.g. "Exception caught in file: X at line: Y - Message:
     * Z - Code: C - Trace: ...". Unwrap it so the real message is what gets
     * displayed, grouped and fingerprinted - not the whole wrapper text.
     *
     * @param string $message
     *
     * @return string
     */
    protected function cleanCustomMessage($message)
    {
        if (preg_match('/^Exception caught in file:.*?-\s*Message:\s*(.+?)\s*-\s*Code:\s*\S+/is', $message, $m)) {
            return trim($m[1]);
        }

        return $message;
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
     * Pull Laravel's own "[Route: x] [URI: y]" annotation out of a message,
     * when the exception (route-model binding, URL generation, ...) includes
     * one. This is the closest thing to "which URL" for exception types that
     * embed it themselves.
     *
     * @param string $message
     *
     * @return array
     */
    protected function routeInfo($message)
    {
        $info = [];

        if (preg_match('/\[Route:\s*([^\]]+)\]/i', $message, $m)) {
            $info['route'] = trim($m[1]);
        }

        if (preg_match('/\[URI:\s*([^\]]+)\]/i', $message, $m)) {
            $info['uri'] = trim($m[1]);
        }

        return $info;
    }

    /**
     * Pull the failing query out of a Laravel QueryException message, e.g.
     * "... (Connection: pgsql, SQL: select * from ... where id = ?)".
     *
     * @param string $message
     *
     * @return string
     */
    protected function sqlQuery($message)
    {
        if (preg_match('/\bSQL:\s*(.+?)\)?\s*$/s', $message, $m)) {
            return rtrim(trim($m[1]), ')');
        }

        return '';
    }

    /**
     * Pull request URL/method/IP/payload out of the log context, when the
     * application logs them. Laravel does not do this by default - see the
     * README for the snippet that adds it. Reading it here means the moment
     * an app adds that context, the summary starts showing it with no further
     * change needed on this side.
     *
     * @param array|null $context
     *
     * @return array
     */
    protected function requestInfo($context)
    {
        if (!is_array($context)) {
            return [];
        }

        $info = [];

        foreach (['url', 'full_url', 'fullUrl', 'uri'] as $key) {
            if (!empty($context[$key]) && is_string($context[$key])) {
                $info['url'] = $context[$key];
                break;
            }
        }

        if (!empty($context['method']) && is_string($context['method'])) {
            $info['method'] = strtoupper($context['method']);
        }

        if (!empty($context['ip']) && is_string($context['ip'])) {
            $info['ip'] = $context['ip'];
        }

        foreach (['input', 'payload', 'request', 'post', 'get', 'data'] as $key) {
            if (isset($context[$key]) && is_array($context[$key]) && $context[$key]) {
                $info['payload'] = $this->redact($context[$key]);
                break;
            }
        }

        return $info;
    }

    /**
     * Mask sensitive values (passwords, tokens, ...) recursively rather than
     * ever showing them, even if the source app forgot to filter them itself.
     *
     * @param array $data
     *
     * @return array
     */
    protected function redact(array $data)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (in_array(strtolower((string) $key), self::$sensitiveKeys, true)) {
                $data[$key] = '••••••';
            }
        }

        return $data;
    }

    /**
     * Pull the first "file.php:123" / "file.php(123)" reference out of a
     * message or its trace, preferring a frame inside the application over
     * one in vendor/. Handles every location format actually seen in the
     * wild:
     *
     *  - PHP's Exception::getTraceAsString(): "#0 /path/File.php(123): ..."
     *  - PHP fatal errors / warnings: "/path/File.php on line 123"
     *  - Custom wrappers: "in file: /path/File.php at line: 123"
     *  - A plain "/path/File.php:123" some loggers use
     *
     * @param string $haystack
     *
     * @return string
     */
    protected function location($haystack)
    {
        $candidates = [];

        if (preg_match('/\bin\s+file:?\s*([^\s]+\.php)\s+(?:at|on)\s+line:?\s*(\d+)/i', $haystack, $m)) {
            $candidates[] = [$m[1], $m[2]];
        }

        $pattern = '#((?:/|[A-Za-z]:\\\\)[^\s:()"\']+\.php)(?:\((\d+)\)|:(\d+)|\s+on\s+line\s+(\d+)|\s+at\s+line:?\s+(\d+))#i';

        if (preg_match_all($pattern, $haystack, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $line = '';

                foreach ([2, 3, 4, 5] as $group) {
                    if (isset($m[$group]) && $m[$group] !== '') {
                        $line = $m[$group];
                        break;
                    }
                }

                if ($line !== '') {
                    $candidates[] = [$m[1], $line];
                }
            }
        }

        if (!$candidates) {
            return '';
        }

        $fallback = '';

        foreach ($candidates as $candidate) {
            $file = str_replace('\\', '/', $candidate[0]);
            $line = $candidate[1];

            // Frames inside vendor/ are framework plumbing - the caller wants
            // the first frame that belongs to the application. Remember the
            // very first vendor candidate as a fallback (it is usually the
            // most specific one available, e.g. an explicit "in file:" note).
            if (strpos($file, '/vendor/') !== false) {
                if ($fallback === '') {
                    $fallback = basename($file).':'.$line;
                }

                continue;
            }

            // Shorten to the project-relative part when we can recognise it.
            // The greedy prefix makes this match the *last* such directory, so
            // /var/www/app/app/Services/X.php resolves to app/Services/X.php.
            if (preg_match('#^.*/((?:app|routes|config|database|resources|src|tests)/[^\s]*)$#', $file, $short)) {
                return $short[1].':'.$line;
            }

            return basename($file).':'.$line;
        }

        return $fallback;
    }
}
