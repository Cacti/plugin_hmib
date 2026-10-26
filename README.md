# hmib

The hmib plugin is designed to collect SNMP information from Cacti Devices that
support the SNMP Host Resources MIB (HOST-RESOURCES-MIB).  This SNMP information
includes performance metrics like:

* CPU utilization

* Running processes

* Running applications

* Physical and Virtual Memory utilization

* Hardware details

* Device details

* Storage details

* Installed software

This Host MIB information is stored in the Cacti database and grouped into a
series of Dashboards for Cacti Administrators to be able to view the status of
their Devices.  Once the plugin is enabled and its poller is turned on, it
discovers the Cacti Devices that answer for the Host Resources MIB and adds them
to hmib.  You can then classify each of the discovered Devices by operating
system, and when that step is completed properly, the Cacti 'Host MIB' tab will
report system usage by Operating System.

The hmib plugin also includes several Graph Templates that allow you to track
application memory and cpu utilization across your environment as well as
several other Cacti Graphs.

A few of these Cacti Graph Templates include script files that allow Cacti to
use the HMIB data for graphing instead of accessing the Cacti Devices directly
for that information.  This approach reduces Cacti polling times by removing the
latency of obtaining the information from the Device.

## Requirements

* Cacti 1.2.25 or later.  The plugin's INFO file declares `compat = 1.2.25`, and
  Cacti's Plugin Management page shows it as 'Requires: Cacti >= 1.2.25'.  The
  plugin was checked on Cacti 1.2.25, 1.2.32 and on the development branch.  The
  Graphs tab does not work on Cacti 1.2.32, because that release builds the
  Graph Template filter with a function it declares only in graph_view.php.  The
  other tabs work on all three.

* PHP 8.0 or later.  setup.php and hmib.php use union types, so they do not
  parse on PHP 7.4.  The plugin's automated tests run on PHP 8.2, 8.3 and 8.4.

* MariaDB or MySQL, the database Cacti itself uses.  The plugin creates its
  tables itself; see 'Database tables'.

* Devices with SNMP enabled in Cacti (an SNMP version other than 'None') whose
  SNMP agent implements the Host Resources MIB groups listed under 'SNMP data'.
  The plugin polls with the Device's own SNMP settings: version, community or
  SNMPv3 credentials, port, timeout and maximum OIDs per request.  The number of
  retries is Cacti's global 'SNMP Retries' setting.

* The Cacti poller.  The plugin starts its own poller at the end of every Cacti
  polling cycle, so Cacti's regular polling has to be running.

* The plugin's INFO file does not declare support for remote data collectors
  (`remote_collect:0`).

## Installation

Just like any other Cacti plugin, untar the package to the Cacti plugins
directory, rename the directory to 'hmib', and then from Cacti's Plugin
Management interface, Install and Enable the plugin.  The same can be done from
the command line, in the Cacti directory:

    php cli/plugin_manage.php --plugin=hmib --install --enable

Installing the plugin:

* creates the database tables described below, with 28 operating system types
  and 51 Host Resources type names already in them;

* registers two realms, 'Host MIB Viewer' (hmib.php) and 'Host MIB Admin'
  (hmib_types.php), and grants them to the Cacti 'admin' account.  Give them to
  other users from User Management: edit the user and open the Permissions tab;

* adds a 'Host MIB' tab to Cacti's top tabs for users who have the Viewer realm,
  an 'OS Types' page under Management, and a 'Host MIB' tab on Cacti's Settings
  page.

After installing, turn the poller on: go to Cacti's Settings page, open the
'Host MIB' tab and check 'Host MIB Poller Enabled'.  It is off by default, and
until it is checked poller_hmib.php and poller_graphs.php exit with 'The Host
Mib Collection is Down!'.  On that page you can also set the collection
frequencies and the levels of parallelism, and turn on the automatic discovery
of Cacti Devices and the automatic creation of Graphs.

Once you have installed the hmib plugin, you need to install two Packages for
Graphing.  Both are in the plugin's templates directory and can be imported from
Import/Export, Import Templates, or from the command line:

    php cli/import_package.php --filename=plugins/hmib/templates/Host_Mib_Summary_Device.xml.gz

First you need to install the 'Host_Mib_Summary_Device.xml.gz' package.  This
Device Package installs a Device Template ('Host Mib Summary Device') and
associated Data Queries ('Host MIB - Summary Application Stats' and 'Host MIB -
Host Type Graphs') and seven Graph Templates to track roll-up statistics by OS
Type and by application name.  It also copies the script server scripts
ss_hmib_sum_apps.php and ss_hmib_htypes.php into Cacti's scripts directory and
their definitions into resource/script_server.

The second package to install is the
'Host_Mib_Device_Level_Application_Stats.xml.gz'.  This package includes a Data
Query ('Host Mib - Device Level Application Stats') to track Application memory
and CPU use at the individual Device level, the three 'Host MIB - Total App'
Graph Templates it uses, and the script ss_hmib_apps.php.  You will need to
manually add this Data Query to either the Device Template for your HMIB type
devices and Sync Templates, or manually add it to devices that you wish to track
specific memory and CPU stats by application.  For this specific Data Query, you
will either have to manually create the Graphs, or create an automation rule to
create the Graphs that you are interested in.

The Summary tab shows 'NOTE: Import the Host MIB Device Package to view Graphs.'
until every Data Query and Graph Template it links to exists: the two Data
Queries and the Device Template of the Summary package, and the 'SNMP - Get
Processor Information' Data Query and the 'Host MIB - Logged in Users' and 'Host
MIB - Processes' Graph Templates that come with Cacti's Net-SNMP Device
package.

### Automatic Graphs

If 'Automatically Add New Graphs' is set to something other than 'Never', the
poller starts poller_graphs.php after each collection.  The script runs when the
time since its last run is greater than the setting multiplied by 86400 seconds,
so the number is used as a number of days whatever the label says: '1 Hour' is
one day, '12 Hours' twelve days.  When it runs, it:

* creates a Device named 'Summary Device' from the 'Host Mib Summary Device'
  template if one does not exist yet, and creates the Graphs of its Data Queries
  and Graph Templates;

* for every Host MIB Device that is up and enabled, adds the 'Host MIB - Logged
  in Users' and 'Host MIB - Processes' Graph Templates, and Graphs for the 'SNMP
  - Get Processor Information' Data Query and for the filesystems of the 'SNMP -
  Get Mounted Partitions' Data Query that report a size greater than zero.

The 'Host MIB - Logged in Users' and 'Host MIB - Processes' Graph Templates and
the two 'SNMP - Get' Data Queries are not part of hmib.  They come with Cacti's
Net-SNMP Device package; where one is missing, those Graphs are skipped.

### Upgrade

Replace the plugin files and open Cacti's Plugin Management page or the Host MIB
tab.  When the version in INFO differs from the one Cacti has stored, the plugin
updates the stored version, enables its current hooks, adds the
`total_time` column to plugin_hmib_hrSWRun_last_seen if it is missing, removes a
retired `config_form` hook, and deletes the `tests` directory and `phpunit.xml`
from the plugin directory.  It also writes a warning to the Cacti log for every
top-level entry of the plugin directory that manifest.json does not list.

### Uninstall

Disable and Uninstall the plugin from Plugin Management, or from the command
line:

    php cli/plugin_manage.php --plugin=hmib --disable
    php cli/plugin_manage.php --plugin=hmib --uninstall

Uninstalling drops the plugin's tables and removes its hooks and realms.  It
leaves the saved 'Host MIB' settings, the indexes the plugin added to Cacti's
data_input_data and snmp_query_graph tables, and everything the two Packages
imported (templates, queries and script files).

## Using the plugin

The Host MIB tab has these pages:

* Fleet Dashboard: cards for all Devices, or for one OS Type.

* Summary: statistics by OS Type, and by process name.

* Devices: one row per Device, with links to its other pages.

* Dashboard: cards for one Device.  The tab appears after you open it from a
  Device row.

* Storage, Hardware, Processes, Inventory (installed software).

* Use History: for each Device and process name, when the process was last seen
  and for how long (days:hours:minutes) it has been seen running.  'Process
  History Retention' deletes the rows of processes not seen for that long.

* Graphs: Graphs of the Host MIB Devices.

### Operating system types

Each Device is assigned an OS Type by comparing what it reports with the types
under Management, OS Types (needs the Host MIB Admin realm).  A type matches a
Device when the Device's sysDescr contains the type's 'System Description Match'
(SQL LIKE wildcards such as % are allowed) and its sysObjectID starts with the
type's 'Vendor snmp Object ID'.

* After every collection the poller applies every type in turn, to all Devices,
  so with overlapping patterns the type that comes last in the table wins.
  `php plugins/hmib/associate_os_type.php` does the same on demand.

* 'Rescan' tries the stored types in table order against the Devices that have
  no type, comparing sysObjectID and sysDescr as LIKE substrings, so such a
  Device takes the first type that matches.  It then adds a placeholder type
  named 'New Type' with version 'Unknown' for the sysDescr and sysObjectID of
  the Devices that still have none.  Rename those types and give them real
  patterns.  Rescan does not change a Device that already has a type.

* 'Export' downloads cacti_host_type_xport.csv.  Its first line is
  `"id","name","version","sysDescrMatch","sysObjectID"`, followed by one quoted
  line per type.

* 'Import' reads the same format.  The header line must contain the columns
  `id`, `sysDescrMatch` and `sysObjectID`; `name` and `version` are optional and
  other columns are ignored.  A row that already exists (the same id,
  sysDescrMatch and sysObjectID) is skipped unless 'Allow Existing Rows to be
  Updated?' is checked.

### Settings

Cacti's Settings page, 'Host MIB' tab.  A frequency is Disabled, or 1, 5, 10 or
20 minutes, or 1, 2, 4 or 12 hours, or 1 day.

| Setting | Name | Default | Values |
|---------|------|---------|--------|
| hmib_enabled | Host MIB Poller Enabled | off | on, off |
| hmib_autodiscovery | Automatically Discover Cacti Devices | on | on, off |
| hmib_autopurge | Automatically Purge Devices | on | on, off |
| hmib_history_retention_days | Process History Retention | Never Purge | Never Purge, 30, 90 or 180 days, 1 or 2 years |
| hmib_os_type_rows | Default Row Count | 10 | Cacti's row counts, or Default |
| hmib_top_types | Default Top Host Types | 10 | 5, 8, 10, 15 |
| hmib_top_processes | Default Top Processes | 10 | 5, 10, 20, 30 |
| hmib_concurrent_processes | Maximum Concurrent Collectors | 10 | 1, 2, 3, 4, 5, 10, 20, 30, 40, 50 |
| hmib_autodiscovery_freq | Auto Discovery Frequency | 5 minutes | a frequency |
| hmib_automation_frequency | Automatically Add New Graphs | Never | Never, 1 Hour, 12 Hours, 1 Day, 2 Days (used as 1, 12, 24 and 48 days) |
| hmib_hrSWRun_freq | Running Programs Frequency | 5 minutes | a frequency |
| hmib_hrSWRunPerf_freq | Running Programs CPU/Memory Frequency | 5 minutes | a frequency |
| hmib_hrSWInstalled_freq | Installed Software Frequency | 1 day | a frequency |
| hmib_hrStorage_freq | Storage Frequency | 1 hour | a frequency |
| hmib_hrDevices_freq | Device Frequency | 1 hour | a frequency |
| hmib_hrProcessor_freq | Processor Frequency | 5 minutes | a frequency |

Auto discovery looks at the Cacti Devices that are enabled, not down, and have
SNMP enabled, and adds the ones that answer for the Host MIB system group.  With
'Automatically Purge Devices' on, data of Devices that were deleted from Cacti
is deleted too.

A discovered Device stays in hmib.  On each cycle its collectors run only while
SNMP is still enabled for it and it still answers for the Host MIB system group;
when it does not, the other walks are skipped.  When the walk of a table fails
or times out, the stored rows of that table are kept.  An empty answer clears
them only when the Device still answers a sysUpTime request.

### SNMP data

The collectors walk these parts of the Host Resources MIB, each at its own
frequency, plus the MIB-II system group (sysDescr, sysObjectID, sysUpTime,
sysContact, sysName, sysLocation):

| Collector | OID |
|-----------|-----|
| System | .1.3.6.1.2.1.25.1 (hrSystem), memory size from .1.3.6.1.2.1.25.2.2.0 |
| Running programs | .1.3.6.1.2.1.25.4.2.1 (hrSWRunTable) |
| Running programs CPU and memory | .1.3.6.1.2.1.25.5.1.1 (hrSWRunPerfTable) |
| Installed software | .1.3.6.1.2.1.25.6.3.1 (hrSWInstalledTable) |
| Storage | .1.3.6.1.2.1.25.2.3 (hrStorageTable) |
| Devices | .1.3.6.1.2.1.25.3.2.1 (hrDeviceTable) |
| Processors | .1.3.6.1.2.1.25.3.3.1 (hrProcessorTable) |

Memory and swap use are computed from the hrStorage rows of type RAM and
virtual memory.

### Database tables

Created on install, dropped on uninstall: plugin_hmib_hrDevices,
plugin_hmib_hrProcessor, plugin_hmib_hrSWInstalled, plugin_hmib_hrSWRun,
plugin_hmib_hrSWRun_ignore, plugin_hmib_hrSWRun_last_seen,
plugin_hmib_hrStorage, plugin_hmib_hrSystem, plugin_hmib_hrSystemTypes and
plugin_hmib_types are MyISAM tables; plugin_hmib_processes, which locks the
collector processes, is a MEMORY table.

### Command line

The scripts are run by the plugin; you can also run them by hand, in any
directory, as the user Cacti runs as.  All of them print their version with
`--version`, and their usage with `--help`.

    php plugins/hmib/poller_hmib.php [-M] [-f] [-fd] [-d]
    php plugins/hmib/poller_hmib.php --host-id=N [--seed=N] [-f] [-d]

poller_hmib.php is started by Cacti at the end of every polling cycle with -M,
the master process.  It runs the auto discovery and the collectors that are due,
starts one child process per Device (up to the Maximum Concurrent Collectors),
waits for them, assigns the OS Types, and then starts poller_graphs.php.  It
holds a database lock for the whole run, so a master started while another is
running exits.  Auto discovery, launching children and waiting for them all
stop 10 minutes after the master started.  A child is started with
`--host-id`.  `-f` or `--force` runs every collector
whatever its frequency, `-d` or `--debug` prints more.  `--key` and `--start` are
passed from the master to the child, and `-fd` or `--force-discovery` is
accepted and has no effect.

    php plugins/hmib/poller_graphs.php [--force] [--debug]

Creates the Summary Device and the Graphs described under 'Automatic Graphs'
when 'Automatically Add New Graphs' is due; `--force` runs it regardless.

    php plugins/hmib/associate_os_type.php [--debug]

Assigns the OS Types to all Devices, as described under 'Operating system
types'.

## Translations

The text domain is 'hmib'.  The translation sources are the .po files in
locales/po (23 languages) and the template locales/po/cacti.pot; the compiled
.mo files are in locales/LC_MESSAGES.  locales/build_gettext.sh rebuilds the
template, merges it into every .po file and compiles the .mo files.  It needs GNU
gettext (xgettext, msgmerge and msgfmt) and `realpath`.  A pull request that
changes translatable strings has to include the updated locales/po/cacti.pot.

## Development

The tests are Pest tests that run inside a Cacti checkout, with the plugin in
its plugins directory and Cacti's Composer packages installed (the checkout's
include/vendor/autoload.php is loaded by tests/bootstrap-unit.php).  The file
tests/.cacti-version holds `1.2.x` or `develop`, or the exact version of the
checkout.  From the Cacti directory:

    include/vendor/bin/pest --configuration=plugins/hmib/phpunit.xml

The tests replace Cacti's database and request functions with stubs and do not
need a database.  With Xdebug, to measure coverage:

    php -d xdebug.mode=coverage include/vendor/bin/pest --configuration=plugins/hmib/phpunit.xml --coverage-clover=plugins/hmib/coverage/clover.xml

From the plugin directory:

    php tests/bin/validate-manifest.php
    php tests/bin/check-i18n-pot.php <base-ref>
    php tests/bin/patch-coverage.php coverage/clover.xml <base-ref> 100

validate-manifest.php checks the top-level entries of the plugin directory
against manifest.json (run it before creating a coverage directory, or remove
the directory).  check-i18n-pot.php fails when translatable strings changed
since `<base-ref>` and locales/po/cacti.pot did not.  patch-coverage.php fails
when less than the given percentage of the lines changed since `<base-ref>` is
covered by the Clover file; hmib.php, hmib_types.php and poller_hmib.php cannot
be loaded by the tests and are not measured.

GitHub Actions (.github/workflows/plugin-ci-workflow.yml) runs on pushes and
pull requests to main and develop.  It installs Cacti 1.2.x and the plugin on a
MariaDB 11.8 database with PHP 8.2, 8.3 and 8.4, checks the manifest, runs
`php -l` on every PHP file, runs the tests with coverage, and then runs a Cacti
poller cycle.  On pull requests it also runs the gettext check and the coverage
check.

## Bugs and Feature Enhancements

Bug and feature enhancements for the hmib plugin are handled in GitHub, at
https://github.com/Cacti/plugin_hmib.  If you find a bug, first search the Cacti
forums for a solution before creating an issue in GitHub.

-----------------------------------------------
Copyright (c) 2004-2026 - The Cacti Group, Inc.
