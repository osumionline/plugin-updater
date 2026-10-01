# Osumi Framework Updater Plugin

Composer plugin used by [Osumi Framework](https://github.com/osumionline/framework) to automatically execute framework migrations after Composer updates.

## Purpose

`osumionline/plugin-updater` acts as the bridge between Composer and the migration system included in Osumi Framework.

The plugin itself does **not** contain application migrations.

Its responsibility is limited to:

- Detecting updates of the `osumionline/framework` package.
- Running after Composer has regenerated the autoloader.
- Detecting the currently installed Osumi Framework version.
- Calling the framework migration runner when available.
- Passing Composer-related execution options to the migration system.

All migration definitions, transformations, rollback handling and migration state are implemented by Osumi Framework itself.

## Requirements

- PHP 8.5 or newer.
- Composer 2.x.
- `composer-plugin-api` 2.x.

## Installation

The plugin is intended to be installed as a dependency of Osumi Framework.

It can also be installed manually with Composer:

```bash
composer require osumionline/plugin-updater
```

Because this package is a Composer plugin, Composer must explicitly allow it to execute.

Add the following configuration to the root `composer.json` of the application:

```json
{
	"config": {
		"allow-plugins": {
			"osumionline/plugin-updater": true
		}
	}
}
```

Composer may also ask interactively whether the plugin should be trusted when it is installed for the first time.

## How it works

During a Composer operation, the plugin listens for framework package updates and for the `post-autoload-dump` event.

When the Composer autoloader has been rebuilt, the plugin checks whether the Osumi Framework migration runner is available.

If available, it invokes:

```php
Osumi\OsumiFramework\Migrations\Runner::runPending(...)
```

The migration runner then determines which migrations still need to be executed using the migration state maintained by Osumi Framework.

This also allows migrations to run when the updater plugin itself is installed during the same Composer operation as a framework update.

If the installed framework version does not provide a migration runner, the plugin simply does nothing.

## Migration state

Migration state is managed entirely by Osumi Framework.

The updater does not decide which migration steps have already been applied. It delegates that responsibility to the framework migration runner.

This makes the migration state authoritative even when:

- several framework versions are skipped during an update;
- the updater plugin is installed for the first time;
- Composer triggers more than one autoload event;
- no explicit framework update event was captured.

## Git working tree protection

Osumi Framework migrations normally require a clean Git working tree.

During a Composer update, however, Composer may legitimately modify:

```text
composer.json
composer.lock
```

The updater explicitly allows those two files to be dirty when invoking the framework migration runner.

Changes to application source files or other project files are **not** ignored and continue to be protected by the framework migration safety checks.

## Configuration

Optional updater behavior can be configured through the root application's `composer.json`:

```json
{
	"extra": {
		"ofw": {
			"verbose": true
		}
	}
}
```

Currently supported plugin option:

- `verbose`: enables additional migration information during Composer operations.

Additional values inside `extra.ofw` are forwarded to the framework migration system through its `extra` options.

## Environment variables

The following environment variables are supported:

### `OFW_DRY_RUN`

Runs pending migrations in dry-run mode.

```bash
OFW_DRY_RUN=1 composer update
```

No migration changes are persisted.

### `OFW_FORCE`

Bypasses migration safety checks that support forced execution.

```bash
OFW_FORCE=1 composer update
```

Use this option carefully.

### `OFW_VERBOSE`

Enables verbose updater and migration output.

```bash
OFW_VERBOSE=1 composer update
```

Accepted boolean values include:

```text
1
true
yes
y
on
```

and:

```text
0
false
no
n
off
```

## Manual migrations

The updater is intended for migrations triggered automatically by Composer.

Osumi Framework also provides its own command-line migration tool for manual execution:

```bash
vendor/bin/ofw-migrate
```

For example:

```bash
vendor/bin/ofw-migrate --dry-run --verbose
```

The CLI belongs to Osumi Framework itself and is independent from this Composer plugin.

## Architecture

The responsibilities are intentionally separated:

```text
Composer
   │
   ▼
osumionline/plugin-updater
   │
   │ detects framework installation/update
   │ waits for post-autoload-dump
   ▼
Osumi Framework Migration Runner
   │
   ├── selects pending migration steps
   ├── validates the project
   ├── applies transformations
   ├── manages rollback backups
   └── persists migration state
```

This keeps Composer-specific functionality outside the framework migration engine and prevents the core migration system from depending on Composer APIs.

## Version

Current version:

```text
1.0.1
```

## License

This project is licensed under the MIT License.

## Author

Iñigo Gorosabel
[Osumi](https://osumi.es)
