Log viewer for super-admin
============================

[![StyleCI](https://styleci.io/repos/491059283/shield?branch=main)](https://styleci.io/repos/491059283)
[![Packagist](https://img.shields.io/github/license/super-admin-org/log-viewer.svg?style=flat-square&color=brightgreen)](https://packagist.org/packages/super-admin-org/log-viewer)
[![Total Downloads](https://img.shields.io/packagist/dt/super-admin-org/log-viewer.svg?style=flat-square)](https://packagist.org/packages/super-admin-org/log-viewer)
[![Pull request welcome](https://img.shields.io/badge/pr-welcome-green.svg?style=flat-square&color=brightgreen)]()

## Screenshot

![image](https://github.com/user-attachments/assets/183c5be9-c78c-4b93-9c42-499c552178c4)


## Installation

```
$ composer require super-admin-org/log-viewer

$ php artisan admin:import log-viewer
```

Open `http://localhost/admin/logs`.


## Summary analyser

Above the log table the viewer shows a summary of the current file:

* a count per level (EMERGENCY … DEBUG) — click one to filter the table below;
* the errors and warnings **grouped into distinct issues**, so 400 copies of the
  same exception count as one row rather than flooding the list;
* for each issue: how many times it occurred, when it was first and last seen,
  the exception class, and **where it happened** — the first frame inside your
  application (`app/Http/Controllers/OrderController.php:41`), skipping past
  vendor/ noise. It understands every location format PHP actually produces:
  `getTraceAsString()`'s `File.php(123)`, `File.php on line 123`, and a plain
  `File.php:123`;
* **route, URI and SQL**, when the exception carries them. Laravel bakes these
  into some exception messages already (route-model binding, `UrlGenerationException`,
  `QueryException`) — no app change needed to see them;
* **request URL, method, IP and submitted payload**, when your app logs them
  (see below — this one *does* need a small addition to your exception handler,
  since Laravel does not log it by default). Sensitive fields (`password`,
  `token`, `secret`, `api_key`, …) are always masked, even if the app forgot to;
* a **How to fix** panel with the likely cause, a remediation checklist and any
  artisan/shell commands that usually resolve it;
* a collapsed **Info & Debug activity** panel below the issues — the most
  frequent non-error log lines, so you can see what's noisy without it
  crowding out actual problems.

Grouping normalises ids, uuids, emails, paths, numbers and quoted values, so
`User 12 not found` and `User 4471 not found` are recognised as one issue.

Suggestions come from a pattern knowledge base covering common Laravel and PHP
failures across both MySQL and PostgreSQL — missing migrations, rejected DB
credentials, duplicate/foreign key/not-null violations, deadlocks, a database
still in recovery, class/view/route not found, a route missing a URL parameter,
CSRF and auth failures, a misconfigured `LOG_CHANNEL`, a missing `config/`
directory (broken deploy), memory and execution-time limits (including the
CLI-specific "Out of memory" fatal), storage permissions, null method calls,
raw validation exceptions, deprecations, cURL/TLS/DNS problems, Redis and SMTP
outages, duplicate function declarations, exhausted queue retries and missing
asset builds. Anything unmatched still gets generic triage advice for its level.

> Suggestions are pattern-based. They are a starting point — confirm against the
> stack trace before acting.

Only the last 2 MB of a file are scanned, so a large log cannot stall the page.
All of it is configurable in `config/admin.php`:

```php
'extensions' => [
    'log-viewer' => [
        //'analyzer' => false,               // hide the summary card entirely
        //'analyzer_max_bytes' => 2097152,   // how much of the tail to scan (default 2 MB)
        //'analyzer_max_issues' => 8,        // how many issues to list (default 8)
        //'analyzer_max_activity' => 6,      // how many Info/Debug rows to list (default 6)
    ]
]
```

### Seeing the request URL and payload on an error

Laravel does **not** log the request URL or its input by default — only what
the exception message itself says. To have that appear in the summary, add it
to your app's exception reporting once, and every future error will carry it
automatically.

**Laravel 11+** (`bootstrap/app.php`):

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->reportable(function (\Throwable $e) {
        if (! app()->runningInConsole() && request()) {
            \Log::error($e->getMessage(), [
                'exception' => $e,
                'url'       => request()->fullUrl(),
                'method'    => request()->method(),
                'ip'        => request()->ip(),
                'input'     => request()->except(['password', 'password_confirmation', 'token']),
            ]);
        }
    });
})
```

**Laravel ≤10** (`app/Exceptions/Handler.php`):

```php
public function report(Throwable $e)
{
    if ($this->shouldReport($e) && ! app()->runningInConsole() && request()) {
        \Log::error($e->getMessage(), [
            'exception' => $e,
            'url'       => request()->fullUrl(),
            'method'    => request()->method(),
            'ip'        => request()->ip(),
            'input'     => request()->except(['password', 'password_confirmation', 'token']),
        ]);
    }

    parent::report($e);
}
```

The analyser reads `url`, `method`, `ip` and `input` (also accepts `uri`,
`full_url`, `payload`, `request`, `post`, `get` or `data` for the payload key)
from this context the moment it's present — nothing else to configure. Keep
excluding secrets from `input` yourself; the built-in redaction is a backstop,
not a substitute for it.

### Adding your own suggestions

Register extra signatures from a service provider — application rules are
matched before the built-in ones:

```php
use SuperAdmin\Admin\LogViewer\LogSolutions;

LogSolutions::extend([
    [
        'match' => '/PaymentGatewayException/',
        'title' => 'Payment gateway rejected the charge',
        'cause' => 'The provider returned a hard decline.',
        'steps' => ['Check the gateway dashboard for the transaction id.'],
        'commands' => ['php artisan payments:reconcile'],
    ],
]);
```

## Configuration
If your server doesn't allow you to access log files for example by blocking requests with '.log' in the url you can enable the following bypass function.

See `config/admin.php` and add in the `extensions` section
```php
'extensions' => [
    'log-viewer' => [
        'bypass_protected_urls' => true,
        //'bypass_protected_urls_find' => ['.'],          // default ['.']
        //'bypass_protected_urls_replace' => ['[dot]'],   // default ['[dot]']
    ]
]
```

License
------------
Licensed under [The MIT License (MIT)](LICENSE).
Special thanks to z-song for original development
