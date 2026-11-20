# Changelog

## v1.3.3 - 2026-11-20

### Changed

- Works with super-admin 2.x: the package now requires `super-admin-org/super-admin` `^1.0|^2.0`. Earlier versions required `^1.0`, so Composer could not install them next to super-admin v2.0.0.
- Package homepage and documentation: https://super-admin.org/laravel/extension-log-viewer/

## v1.3.2 - 2026-11-20

### Fixed

- `ERROR` and `ALERT` entries were shown in warning (orange) and primary (blue) colours. They now use the danger colour like `CRITICAL` and `EMERGENCY`; `WARNING` keeps the warning colour (badges, summary chips and the issue border).
