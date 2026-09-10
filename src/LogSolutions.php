<?php

namespace SuperAdmin\Admin\LogViewer;

/**
 * A small knowledge base that maps common Laravel / PHP log signatures to a
 * likely cause and a concrete remediation checklist.
 *
 * Rules are matched top to bottom, most specific first. Each rule is:
 *
 *  'match'    => regex tested against "<message> <stack trace>"
 *  'title'    => short name of the problem
 *  'cause'    => one sentence explaining why it usually happens
 *  'steps'    => ordered list of things to check / do
 *  'commands' => optional shell commands that usually fix it
 */
class LogSolutions
{
    /**
     * @var array|null
     */
    protected static $rules;

    /**
     * Get the active rule set.
     *
     * @return array
     */
    public static function rules()
    {
        if (self::$rules === null) {
            self::$rules = self::defaultRules();
        }

        return self::$rules;
    }

    /**
     * Register extra rules (or override the built-in set) from an application.
     *
     * @param array $rules
     * @param bool  $prepend put the new rules in front so they win over built-ins
     *
     * @return void
     */
    public static function extend(array $rules, $prepend = true)
    {
        $current = self::rules();

        self::$rules = $prepend ? array_merge($rules, $current) : array_merge($current, $rules);
    }

    /**
     * Find the best suggestion for a log entry.
     *
     * @param string $haystack message + stack trace
     * @param string $level
     *
     * @return array
     */
    public static function resolve($haystack, $level = 'ERROR')
    {
        foreach (self::rules() as $rule) {
            if (@preg_match($rule['match'], $haystack)) {
                unset($rule['match']);

                return $rule + ['confidence' => 'likely', 'steps' => [], 'commands' => []];
            }
        }

        return self::fallback($level);
    }

    /**
     * Generic advice when no rule matches.
     *
     * @param string $level
     *
     * @return array
     */
    protected static function fallback($level)
    {
        $level = strtoupper($level);

        if (in_array($level, ['EMERGENCY', 'ALERT', 'CRITICAL'], true)) {
            return [
                'title'      => 'Unclassified critical failure',
                'cause'      => 'Something aborted the application rather than being handled. Anything logged above ERROR usually means the process, a dependency or the whole site is down.',
                'steps'      => [
                    'Open the stack trace and find the first frame inside app/ - that is your code, the frames above it are framework plumbing.',
                    'Check whether an external dependency (database, cache, queue, disk, third-party API) was unreachable at that timestamp.',
                    'Correlate the timestamp with a deploy, migration or config change.',
                ],
                'commands'   => [],
                'confidence' => 'generic',
            ];
        }

        if ($level === 'WARNING' || $level === 'NOTICE') {
            return [
                'title'      => 'Unclassified warning',
                'cause'      => 'The request finished, but PHP or the framework flagged something unsafe - usually an undefined value, a deprecated call or a silently ignored failure.',
                'steps'      => [
                    'Warnings that repeat on every request are the ones worth fixing - check the occurrence count.',
                    'Read the file:line in the trace and guard the value with a null check, a default, or validation.',
                ],
                'commands'   => [],
                'confidence' => 'generic',
            ];
        }

        return [
            'title'      => 'Unclassified error',
            'cause'      => 'No known signature matched this message, so it is probably application-specific.',
            'steps'      => [
                'Open the stack trace and find the first frame inside app/.',
                'Reproduce it locally with the same input, then add the missing guard, validation or try/catch.',
                'If this is a known and acceptable condition, log it at a lower level so it stops polluting the error feed.',
            ],
            'commands'   => [],
            'confidence' => 'generic',
        ];
    }

    /**
     * The built-in signatures.
     *
     * @return array
     */
    protected static function defaultRules()
    {
        return [
            // ---------------------------------------------------------------
            // Database
            // ---------------------------------------------------------------
            [
                'match' => '/SQLSTATE\[42S02\]|Base table or view not found/i',
                'title' => 'Database table is missing',
                'cause' => 'The query refers to a table that does not exist in the connected database - normally pending migrations, or the app pointing at the wrong database.',
                'steps' => [
                    'Confirm DB_DATABASE in .env is the database you expect.',
                    'Run the pending migrations on this environment.',
                    'If the table was renamed, update the model $table property or the query builder call.',
                ],
                'commands' => ['php artisan migrate --force', 'php artisan migrate:status'],
            ],
            [
                'match' => '/SQLSTATE\[42S22\]|Unknown column/i',
                'title' => 'Unknown column in query',
                'cause' => 'The column was renamed, dropped, or a migration adding it has not run on this environment.',
                'steps' => [
                    'Compare the column list in the migration with the live schema.',
                    'Run outstanding migrations, then clear any cached query/model state.',
                    'Check for a typo in a where(), select() or $fillable entry.',
                ],
                'commands' => ['php artisan migrate --force'],
            ],
            [
                'match' => '/SQLSTATE\[HY000\] \[1045\]|Access denied for user/i',
                'title' => 'Database credentials rejected',
                'cause' => 'The DB user/password/host combination is wrong, or the user has no grant from this host.',
                'steps' => [
                    'Verify DB_USERNAME / DB_PASSWORD / DB_HOST in .env.',
                    'Config caching freezes .env values - rebuild the cache after any change.',
                    'Confirm the MySQL user is granted access from the application server host, not just localhost.',
                ],
                'commands' => ['php artisan config:clear', 'php artisan config:cache'],
            ],
            [
                'match' => '/SQLSTATE\[HY000\] \[1049\]|Unknown database/i',
                'title' => 'Database does not exist',
                'cause' => 'The database named in DB_DATABASE has not been created on this server.',
                'steps' => [
                    'Create the database, or correct DB_DATABASE in .env.',
                    'Rebuild the config cache and run migrations.',
                ],
                'commands' => ['php artisan config:clear', 'php artisan migrate --force'],
            ],
            [
                'match' => '/SQLSTATE\[HY000\] \[200[26]\]|Connection refused.*(?:3306|mysql)|MySQL server has gone away/i',
                'title' => 'Database server unreachable',
                'cause' => 'The database process is down, listening elsewhere, or blocked by a firewall / wrong socket path.',
                'steps' => [
                    'Check the database service is running and accepting connections on DB_HOST:DB_PORT.',
                    'Inside Docker, DB_HOST must be the service name, not 127.0.0.1.',
                    '"MySQL server has gone away" instead points at wait_timeout or max_allowed_packet - shorten long-running connections or raise those limits.',
                ],
                'commands' => [],
            ],
            [
                'match' => '/could not find driver/i',
                'title' => 'PDO driver not installed',
                'cause' => 'The PHP extension for this database (pdo_mysql, pdo_pgsql, pdo_sqlite) is missing from the CLI or FPM build.',
                'steps' => [
                    'Install the matching pdo extension and restart PHP-FPM.',
                    'CLI and FPM load different php.ini files - verify both with php -m.',
                ],
                'commands' => ['php -m | grep pdo'],
            ],
            [
                'match' => '/Integrity constraint violation: 1062|Duplicate entry/i',
                'title' => 'Duplicate entry on a unique index',
                'cause' => 'A row was inserted with a value that already exists on a unique column - almost always a missing validation rule or a double-submitted form.',
                'steps' => [
                    'Add a unique validation rule so the user gets a 422 instead of a 500.',
                    'For idempotent writes use updateOrCreate() / firstOrCreate() instead of create().',
                    'If it is a double submit, disable the button on submit or add a signed idempotency key.',
                ],
                'commands' => [],
            ],
            [
                'match' => '/Integrity constraint violation: 145[123]|foreign key constraint fails/i',
                'title' => 'Foreign key constraint violation',
                'cause' => 'A row references a parent that does not exist, or a parent was deleted while children still point at it.',
                'steps' => [
                    'Validate the foreign id with the exists: rule before writing.',
                    'Decide the delete behaviour explicitly: cascadeOnDelete(), nullOnDelete() or restrict.',
                    'Check seeding/import order - parents must be inserted first.',
                ],
                'commands' => [],
            ],
            [
                'match' => '/Integrity constraint violation: 1048|Column .* cannot be null/i',
                'title' => 'NOT NULL column received null',
                'cause' => 'A required field was absent from the request or was stripped by mass-assignment rules before the insert.',
                'steps' => [
                    'Add a required validation rule for the field.',
                    'Make sure the attribute is listed in the model $fillable array.',
                    'If the value is genuinely optional, make the column nullable in a migration and give it a default.',
                ],
                'commands' => [],
            ],
            [
                'match' => '/Deadlock found|Lock wait timeout exceeded/i',
                'title' => 'Database deadlock or lock timeout',
                'cause' => 'Concurrent transactions grabbed the same rows in a different order, or a transaction held a lock far too long.',
                'steps' => [
                    'Keep transactions short - never do HTTP calls or file IO inside DB::transaction().',
                    'Always lock rows in a consistent order across the codebase.',
                    'Pass a retry count as the second argument to DB::transaction().',
                ],
                'commands' => [],
            ],
            [
                'match' => '/Too many connections/i',
                'title' => 'Database connection limit reached',
                'cause' => 'More PHP workers/queue processes than the server max_connections allows, or connections that are never released.',
                'steps' => [
                    'Count your PHP-FPM children plus queue workers and compare against max_connections.',
                    'Raise max_connections, or lower worker concurrency.',
                    'Look for long-running jobs holding a connection open for the whole run.',
                ],
                'commands' => [],
            ],

            // ---------------------------------------------------------------
            // Wiring / resolution
            // ---------------------------------------------------------------
            [
                'match' => '/Class ["\']?[\w\\\\]+["\']? not found|Class .* does not exist/i',
                'title' => 'Class not found',
                'cause' => 'A stale autoloader, a namespace that does not match the file path, or a package that is not installed on this environment.',
                'steps' => [
                    'Check the namespace and file name match PSR-4 exactly, including letter case (Linux is case-sensitive, Windows is not - this is the classic "works locally" bug).',
                    'Regenerate the autoloader.',
                    'If it belongs to a package, confirm it is in composer.json and vendor/ was deployed.',
                ],
                'commands' => ['composer dump-autoload -o', 'php artisan clear-compiled'],
            ],
            [
                'match' => '/Target class \[.*\] does not exist|Unable to resolve dependency|BindingResolutionException/i',
                'title' => 'Container cannot resolve a dependency',
                'cause' => 'A controller/interface is not bound in a service provider, or a route points at a controller string that no longer resolves.',
                'steps' => [
                    'Bind the interface to a concrete class in a service provider.',
                    'Use the ::class syntax in routes rather than a "Controller@method" string.',
                    'For non-injectable scalars, give the constructor argument a default value.',
                ],
                'commands' => ['php artisan route:clear', 'php artisan optimize:clear'],
            ],
            [
                'match' => '/View \[.*\] not found|InvalidArgumentException.*view/i',
                'title' => 'Blade view not found',
                'cause' => 'Wrong view name/namespace, a file that was not deployed, or a cached view path pointing at an old location.',
                'steps' => [
                    'Confirm the file exists under resources/views with the .blade.php suffix.',
                    'Package views need the registered namespace prefix, e.g. super-admin-logs::logs.',
                    'Clear the compiled view cache.',
                ],
                'commands' => ['php artisan view:clear'],
            ],
            [
                'match' => '/Route \[.*\] not defined/i',
                'title' => 'Named route not defined',
                'cause' => 'route() was called with a name that no longer exists, or the route cache is stale.',
                'steps' => [
                    'Search the route list for the name and fix the typo, or add ->name() to the route.',
                    'Rebuild the route cache after every deploy that touches routes.',
                ],
                'commands' => ['php artisan route:list', 'php artisan route:clear', 'php artisan route:cache'],
            ],
            [
                'match' => '/NotFoundHttpException/i',
                'title' => 'HTTP 404 - no matching route or model',
                'cause' => 'Either no route matched the URL, or route-model binding could not find the record.',
                'steps' => [
                    'A steady trickle of these is normal (bots, old links). A sudden spike means a broken link or a renamed route.',
                    'Check the offending URL in the trace before assuming it is a bug.',
                    'Consider not logging 404s at error level so they stop hiding real failures.',
                ],
                'commands' => [],
            ],
            [
                'match' => '/MethodNotAllowedHttpException/i',
                'title' => 'HTTP 405 - wrong HTTP verb',
                'cause' => 'The URL exists but not for that verb - typically a form posting to a route registered as GET, or a missing @method spoof.',
                'steps' => [
                    'Check the form method and any method-spoofing directive on the form.',
                    'Verify the verb registered for that URI in the route list.',
                ],
                'commands' => ['php artisan route:list'],
            ],
            [
                'match' => '/TokenMismatchException|CSRF token mismatch/i',
                'title' => 'CSRF token mismatch (419)',
                'cause' => 'The session expired before the form was submitted, the CSRF field is missing, or session cookies are not surviving the domain/proxy setup.',
                'steps' => [
                    'Add the CSRF field to the form and the X-CSRF-TOKEN header to AJAX requests.',
                    'Check SESSION_DOMAIN, SESSION_SECURE_COOKIE and SESSION_SAME_SITE match how the site is actually served.',
                    'Behind a proxy or load balancer, confirm TrustProxies is configured and the session store is shared between nodes.',
                ],
                'commands' => [],
            ],
            [
                'match' => '/AuthenticationException|Unauthenticated\./i',
                'title' => 'Unauthenticated request',
                'cause' => 'A guarded route was reached without a valid session or token - often an expired session or the wrong auth guard.',
                'steps' => [
                    'Confirm the route uses the guard you expect (web vs sanctum vs api).',
                    'For SPA/token clients, check the token is actually sent in the Authorization header.',
                    'A spike after a deploy usually means the session driver or APP_KEY changed.',
                ],
                'commands' => [],
            ],
            [
                'match' => '/No application encryption key|The MAC is invalid|DecryptException|payload is invalid/i',
                'title' => 'Encryption key problem',
                'cause' => 'APP_KEY is missing, or it changed - which invalidates every existing session, cookie and encrypted column.',
                'steps' => [
                    'Never regenerate APP_KEY on a live app: existing encrypted data becomes unreadable.',
                    'If it is genuinely missing, generate it and rebuild the config cache.',
                    'Keep the same key across all servers in a cluster.',
                ],
                'commands' => ['php artisan key:generate', 'php artisan config:cache'],
            ],
            [
                'match' => '/MassAssignmentException|Add \[[^\]]+\] to fillable/i',
                'title' => 'Mass assignment blocked',
                'cause' => 'An attribute was passed to create()/fill() but is not listed in the model $fillable array.',
                'steps' => [
                    'Add the attribute to $fillable on the model.',
                    'Do not "fix" this with an empty $guarded on models fed by user input - that reopens the mass-assignment hole this is protecting you from.',
                ],
                'commands' => [],
            ],
            [
                'match' => '/ModelNotFoundException|No query results for model/i',
                'title' => 'Model record not found',
                'cause' => 'findOrFail()/firstOrFail() found nothing - a deleted record, a wrong id, or a query scoped to the wrong tenant/user.',
                'steps' => [
                    'If a missing record is a legitimate outcome, catch it and return a 404 page instead of letting it hit the error log.',
                    'Check soft deletes - the row may exist but be trashed.',
                    'Verify any global scope (tenant, active, published) is not filtering it out.',
                ],
                'commands' => [],
            ],

            // ---------------------------------------------------------------
            // Runtime limits and filesystem
            // ---------------------------------------------------------------
            [
                'match' => '/Allowed memory size of \d+ bytes exhausted/i',
                'title' => 'PHP memory exhausted',
                'cause' => 'A whole result set, file or export was loaded into memory at once.',
                'steps' => [
                    'Replace get()/all() with chunk(), chunkById() or lazy() on large tables.',
                    'Stream file downloads instead of reading them into a string.',
                    'Raising memory_limit is a stopgap, not a fix - it only moves the failure point.',
                ],
                'commands' => [],
            ],
            [
                'match' => '/Maximum execution time of \d+ seconds exceeded/i',
                'title' => 'Script timeout',
                'cause' => 'Synchronous work (report, import, mail, external API) outlived the request timeout.',
                'steps' => [
                    'Move the work into a queued job and return immediately.',
                    'Add an index for any slow query in the trace.',
                    'Check max_execution_time in php.ini alongside the FPM and nginx timeouts - the shortest one wins.',
                ],
                'commands' => [],
            ],
            [
                'match' => '/Permission denied|could not be opened in append mode|failed to open stream: Permission/i',
                'title' => 'Filesystem permission denied',
                'cause' => 'The web user (www-data/nginx) cannot write to storage/ or bootstrap/cache - usually after running artisan as root or deploying as another user.',
                'steps' => [
                    'Give storage/ and bootstrap/cache/ to the web server user and group.',
                    'Never run artisan commands as root on a live server - it leaves root-owned cache and log files behind.',
                ],
                'commands' => [
                    'chown -R www-data:www-data storage bootstrap/cache',
                    'chmod -R ug+rwx storage bootstrap/cache',
                ],
            ],
            [
                'match' => '/failed to open stream: No such file or directory|Directory .* does not exist/i',
                'title' => 'File or directory missing',
                'cause' => 'A path was expected that was never created, was not deployed, or is relative to the wrong working directory.',
                'steps' => [
                    'Build paths with storage_path()/base_path() rather than relative strings.',
                    'Create the directory on boot, or ensure it is deployed (empty dirs are not tracked by git).',
                    'For public file access, confirm the storage symlink exists.',
                ],
                'commands' => ['php artisan storage:link'],
            ],
            [
                'match' => '/Call to a member function \w+\(\) on null/i',
                'title' => 'Method called on null',
                'cause' => 'A lookup returned null and the result was used without a check - the single most common PHP fatal.',
                'steps' => [
                    'Use the nullsafe operator or optional() when the absence is expected.',
                    'Prefer firstOrFail() when a missing row really is exceptional, so the failure is explicit.',
                    'For relations, eager-load and guard with a null-coalescing default.',
                ],
                'commands' => [],
            ],
            [
                'match' => '/Undefined (array key|property|variable|index|offset)/i',
                'title' => 'Undefined key, variable or property',
                'cause' => 'A key was read that is not present - an optional request field, a missing view variable, or a renamed API response field.',
                'steps' => [
                    'Read with a default via the null-coalescing operator, or data_get() for nested payloads.',
                    'For views, pass the variable explicitly or guard it with @isset in the template.',
                    'Validate external API responses before reaching into them.',
                ],
                'commands' => [],
            ],
            [
                'match' => '/Deprecated:|is deprecated|Passing null to parameter|Implicit conversion/i',
                'title' => 'Deprecation notice',
                'cause' => 'Code still uses behaviour the current PHP version warns about - it will become a hard error in a future release.',
                'steps' => [
                    'Fix the call site rather than silencing it - these become fatal on the next major PHP version.',
                    'Passing null to a non-nullable parameter is the usual PHP 8.1 offender: coalesce to a default first.',
                    'If it comes from a vendor package, upgrade it; only then consider excluding deprecations from the log channel.',
                ],
                'commands' => ['composer outdated --direct'],
            ],

            // ---------------------------------------------------------------
            // External services
            // ---------------------------------------------------------------
            [
                'match' => '/cURL error 28|Operation timed out after|ConnectionException.*timed out/i',
                'title' => 'Outbound HTTP request timed out',
                'cause' => 'A third-party API did not respond within the client timeout.',
                'steps' => [
                    'Set an explicit timeout and a retry policy on the HTTP client.',
                    'Move non-critical outbound calls into a queued job so a slow vendor cannot take down your requests.',
                    'Add a circuit breaker or cached fallback for a vendor that fails often.',
                ],
                'commands' => [],
            ],
            [
                'match' => '/cURL error 60|SSL certificate problem|unable to get local issuer certificate/i',
                'title' => 'TLS certificate verification failed',
                'cause' => 'The CA bundle is missing or out of date on the server, or the remote host has an incomplete certificate chain.',
                'steps' => [
                    'Point curl.cainfo and openssl.cafile in php.ini at a current cacert.pem.',
                    'Update the system CA store.',
                    'Never disable verification in production - it turns a warning into a silent interception risk.',
                ],
                'commands' => ['update-ca-certificates'],
            ],
            [
                'match' => '/cURL error 6|Could not resolve host|getaddrinfo/i',
                'title' => 'DNS resolution failed',
                'cause' => 'The hostname could not be resolved from the application server - a typo, a private hostname, or broken DNS inside the container.',
                'steps' => [
                    'Check the URL in config/.env for typos.',
                    'Inside Docker, use the compose service name and confirm both containers share a network.',
                    'Test resolution from the same host the app runs on.',
                ],
                'commands' => [],
            ],
            [
                'match' => '/RedisException|Connection refused \[tcp:\/\/|No connection could be made.*6379|read error on connection/i',
                'title' => 'Redis unreachable',
                'cause' => 'Redis is down or configured with the wrong host/port/password - this takes cache, sessions and queues with it.',
                'steps' => [
                    'Check the Redis service is up and REDIS_HOST/REDIS_PORT/REDIS_PASSWORD are correct.',
                    'Inside Docker, REDIS_HOST is the service name, not 127.0.0.1.',
                    'Consider a cache driver fallback so a Redis outage degrades instead of erroring.',
                ],
                'commands' => ['redis-cli ping'],
            ],
            [
                'match' => '/Swift_TransportException|Mailer\\\\Exception|Failed to authenticate on SMTP|Connection could not be established with host/i',
                'title' => 'Email delivery failed',
                'cause' => 'SMTP credentials, host, port or encryption settings are wrong, or the provider rejected the connection.',
                'steps' => [
                    'Verify MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD / MAIL_ENCRYPTION.',
                    'Port 465 needs ssl, port 587 needs tls - a mismatch fails exactly like bad credentials.',
                    'Queue your mailables so a provider outage retries instead of failing the request.',
                ],
                'commands' => ['php artisan config:clear'],
            ],
            [
                'match' => '/MaxAttemptsExceededException|has been attempted too many times|job.*timed out/i',
                'title' => 'Queued job exhausted its retries',
                'cause' => 'The job kept throwing, or each attempt outlived the worker timeout.',
                'steps' => [
                    'Read the underlying failure in the failed_jobs table - this message is only the symptom.',
                    'Set $tries/$backoff/$timeout on the job to something matched to the work it does.',
                    'Make the job idempotent so a retry cannot double-charge or double-send.',
                ],
                'commands' => ['php artisan queue:failed', 'php artisan queue:retry all'],
            ],
            [
                'match' => '/Vite manifest not found|Unable to locate Mix file|mix-manifest/i',
                'title' => 'Frontend asset manifest missing',
                'cause' => 'The production asset build never ran, or the build output was not deployed.',
                'steps' => [
                    'Run the production build as part of the deploy, not on the developer machine only.',
                    'Confirm public/build (Vite) or public/mix-manifest.json (Mix) exists on the server.',
                    'Check the build output is not excluded by .gitignore or the deploy rsync filter.',
                ],
                'commands' => ['npm ci', 'npm run build'],
            ],
            [
                'match' => '/ThrottleRequests|Too Many Attempts/i',
                'title' => 'Rate limit hit',
                'cause' => 'A client exceeded the throttle middleware limit, or you exceeded a third-party API quota.',
                'steps' => [
                    'Check whether it is one abusive client or your own retry loop hammering an endpoint.',
                    'Tune the throttle middleware limits for the route.',
                    'For outbound calls, honour Retry-After and back off exponentially.',
                ],
                'commands' => [],
            ],
        ];
    }
}
