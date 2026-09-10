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
  the exception class and the first application frame (`app/...:42`);
* a **How to fix** panel with the likely cause, a remediation checklist and any
  artisan/shell commands that usually resolve it.

Grouping normalises ids, uuids, emails, paths, numbers and quoted values, so
`User 12 not found` and `User 4471 not found` are recognised as one issue.

Suggestions come from a pattern knowledge base covering the usual Laravel and
PHP failures — missing migrations, rejected DB credentials, duplicate/foreign
key violations, deadlocks, class/view/route not found, CSRF and auth failures,
memory and execution-time limits, storage permissions, null method calls,
deprecations, cURL/TLS/DNS problems, Redis and SMTP outages, exhausted queue
retries and missing asset builds. Anything unmatched still gets generic triage
advice for its level.

> Suggestions are pattern-based. They are a starting point — confirm against the
> stack trace before acting.

Only the last 2 MB of a file are scanned, so a large log cannot stall the page.
All of it is configurable in `config/admin.php`:

```php
'extensions' => [
    'log-viewer' => [
        //'analyzer' => false,             // hide the summary card entirely
        //'analyzer_max_bytes' => 2097152, // how much of the tail to scan (default 2 MB)
        //'analyzer_max_issues' => 8,      // how many issues to list (default 8)
    ]
]
```

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
