# GitHub Copilot Instructions

## Priority Guidelines

When generating code for this repository:

1. **Version Compatibility**: This is a Cacti plugin (`hmib`, version 3.5) targeting Cacti 1.2.25+
2. **Context Files**: Prioritize patterns and standards defined in this file (`.github/copilot-instructions.md`)
3. **Codebase Patterns**: When context files don't provide specific guidance, scan the codebase for established patterns
4. **Architectural Consistency**: Maintain plugin-based architecture extending Cacti core
5. **Code Quality**: Prioritize security, maintainability, and compatibility in all generated code

## Technology Stack

### Core Technologies
- **PHP**: Compatible with Cacti 1.2.x supported versions
- **Platform**: Cacti Plugin Architecture (Cacti 1.2.25+)
- **Database**: MySQL/MariaDB with InnoDB engine
- **SNMP**: Host Resources MIB (HR-MIB) polling via Cacti's SNMP library (`snmp.php`, `snmp_functions.php`)

### Key Dependencies
- Cacti core framework (`api_plugin_*`, `db_*`, `cacti_snmp_*`)
- Optional: `gettext` for internationalization

## Project Structure

```
hmib/                       # Repository root (install to plugins/hmib/ in Cacti)
├── images/                  # UI icons
├── locales/                  # Internationalization files
├── templates/                 # Device/graph template XML
├── associate_os_type.php        # Maps discovered devices to Host MIB types
├── hmib.php                      # Main viewer (summary, hardware, storage, software, running, devices)
├── hmib_types.php                  # Host MIB type administration
├── poller_graphs.php                # Graph URL helper output
├── poller_hmib.php                   # Background poller entry point (CLI)
├── snmp.php                           # SNMP collection routines
├── snmp_functions.php                  # SNMP helper/formatting functions
├── INFO                                 # Plugin metadata (name, version, compat)
├── README.md
└── setup.php                             # Plugin install/uninstall/upgrade hooks
```

## Naming Conventions

### Function Names
- **Plugin lifecycle/hook-registration functions** MUST be prefixed `plugin_hmib_`: `plugin_hmib_install()`, `plugin_hmib_upgrade()`, `plugin_hmib_version()`.
- **All other functions** MUST be prefixed `hmib_`: `hmib_check_upgrade()`, `hmib_get_runtime()`, `hmib_format_uptime()`.
- Match the existing prefix used by the function you are editing; do not introduce a third naming scheme.

### Database Tables
All plugin tables are prefixed `plugin_hmib_` (see `plugin_hmib_uninstall()`):

```
plugin_hmib_hrDevices, plugin_hmib_hrSWInstalled, plugin_hmib_hrProcessor,
plugin_hmib_hrStorage, plugin_hmib_hrSWRun, plugin_hmib_hrSWRun_ignore,
plugin_hmib_hrSWRun_last_seen, plugin_hmib_hrSystem, plugin_hmib_hrSystemTypes,
plugin_hmib_processes, plugin_hmib_types
```

### Variables and Constants
- Use snake_case for variables: `$host_type_id`, `$sysObjectID`.

## Code Style

### Indentation and Formatting
- **Tabs**: Use tabs (not spaces) for indentation throughout all PHP files.
- **Braces**: Opening brace on the same line for functions and control structures.
- **Spacing**: Space after control structure keywords (`if`, `foreach`, `while`).

### File Headers
ALL PHP files MUST include the standard GPL v2 license header used throughout this repository (see `setup.php`), crediting "The Cacti Group".

## Security Standards

### SQL Query Security
Use prepared statements for anything involving variable input:

```php
// CORRECT
db_fetch_cell("SELECT version FROM plugin_config WHERE directory='hmib'"); // static, no user input
db_execute_prepared('DELETE FROM plugin_hmib_types WHERE id = ?', array($id));

// WRONG - never interpolate request/user-controlled values into SQL
db_execute("DELETE FROM plugin_hmib_types WHERE id = $id");
```

### Input Validation
Use `get_filter_request_var()` / `get_nfilter_request_var()` for request input; never read `$_GET`/`$_POST` directly. Validate host-type fields (`sysDescrMatch`, `sysObjectID`) with `hmib_validate_request_vars()` before saving.

`get_filter_request_var()` (and its `gfrv()` shorthand, where available) called with only the
`$name` argument (no regex/filter as the 2nd/3rd argument) already validates the value as numeric
and returns it as a **string** -- it does not return an int, and it halts execution if the request
value is not numeric. Because of this, do NOT cast its output to `(int)` when the result is only
used for string output (e.g. `print`/`echo`, string concatenation, embedding in HTML/JS); the cast
is redundant. Only cast when the value is genuinely used in an integer/numeric context (e.g.
arithmetic, strict `===` comparisons).

## Database Operations

### Upgrade Handling
Version-gate schema changes in `hmib_check_upgrade()` (`setup.php`), comparing against `plugin_config`, guarded with `db_column_exists()`:

```php
function hmib_check_upgrade() {
	global $config, $database_default;

	$info    = plugin_hmib_version();
	$current = $info['version'];
	$old     = db_fetch_cell("SELECT version FROM plugin_config WHERE directory='hmib'");

	if ($current != $old) {
		if (api_plugin_is_enabled('hmib')) {
			api_plugin_enable_hooks('hmib');
		}

		if (!db_column_exists('plugin_hmib_hrSWRun_last_seen', 'total_time')) {
			db_execute("ALTER TABLE plugin_hmib_hrSWRun_last_seen ADD COLUMN `total_time` BIGINT unsigned not null default '0' AFTER `name`");
		}
	}
}
```

## Internationalization

ALL user-facing strings MUST use `__()` with the `'hmib'` text domain:

```php
api_plugin_register_realm('hmib', 'hmib.php', __('Host MIB Viewer', 'hmib'), 1);
```

## Plugin Architecture

### Plugin Hooks
Register all plugin hooks in `plugin_hmib_install()` (`setup.php`):

```php
api_plugin_register_hook('hmib', 'config_arrays',         'hmib_config_arrays',         'setup.php');
api_plugin_register_hook('hmib', 'config_settings',       'hmib_config_settings',       'setup.php');
api_plugin_register_hook('hmib', 'draw_navigation_text',  'hmib_draw_navigation_text',  'setup.php');
api_plugin_register_hook('hmib', 'poller_bottom',         'hmib_poller_bottom',         'setup.php');
api_plugin_register_hook('hmib', 'top_header_tabs',       'hmib_show_tab',              'setup.php');
api_plugin_register_hook('hmib', 'top_graph_header_tabs', 'hmib_show_tab',              'setup.php');

api_plugin_register_realm('hmib', 'hmib.php', __('Host MIB Viewer', 'hmib'), 1);
api_plugin_register_realm('hmib', 'hmib_types.php', __('Host MIB Admin', 'hmib'), 1);
```

## Best Practices

1. Match existing filter/graph-URL helper patterns (`hmib_get_graph_url()`, `hmib_get_device_status_url()`).
2. Always guard schema changes with `db_column_exists()`.
3. Use SNMP calls defensively (`@cacti_snmp_get()`/`@cacti_snmp_walk()`); devices may not support every Host Resources MIB branch.
4. Wrap all user-facing strings with `__('text', 'hmib')`.

## Common Pitfalls to Avoid

```php
// WRONG - unfiltered request var
$host_type_id = $_GET['host_type_id'];

// CORRECT
$host_type_id = get_filter_request_var('host_type_id');
```

## Version Control

Document all changes in `CHANGELOG.md`; use descriptive commit messages referencing issue/PR numbers when applicable.

## CI & Dependency Baselines

- Do not commit a `composer.json` or `composer.lock` in this plugin's own repo root — the shared CI workflow installs Pest/dev dependencies into Cacti's own Composer-managed vendor tree (checked out alongside the plugin). Use Cacti's `composer.json`, not a plugin-local one.
- Do not add a plugin-local `.phpstan.neon`/`phpstan.neon` or `.php-cs-fixer.php`/`.php-cs-fixer.dist.php` — lint/static-analysis steps run against Cacti's own config from the Cacti core checkout, targeting this plugin's directory. Use the Cacti version, not a plugin-local config.
- Prefer Cacti's `cacti_count()`/`cacti_sizeof()` wrappers over the raw `count()`/`sizeof()` builtins in new or edited code.

## Internationalization (i18n)

- Translatable strings are managed with GNU gettext via `locales/build_gettext.sh`. `locales/po/cacti.pot` is the source template; Weblate owns syncing the per-language `.po`/`.mo` files from it.
- When a pull request adds or changes a string wrapped in `__()`/`__n()`/`__esc()`/`__x()`/`__xn()`/`__gettext()`, run `locales/build_gettext.sh` before pushing and add the resulting change to `locales/po/cacti.pot` only. Do not commit the regenerated per-language `.po`/`.mo` files in the same PR — Weblate takes care of the rest.

## References

- [Cacti main repo](https://github.com/Cacti/cacti/tree/1.2.x)
- [Cacti Documentation](https://www.github.com/Cacti/documentation)
- `README.md` for feature descriptions
- `CHANGELOG.md` for version history
