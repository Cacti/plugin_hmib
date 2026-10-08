ChangeLog

--- develop ---
* feature: Add Host Resources MIB (hrDeviceStatus) device-status pills, a clickable status legend, and a Status filter dropdown to the Hardware tab; clicking a pill sets the dropdown and filters
* issue: Fix 'Undefined array key' PHP warnings on the Processes and Hardware tabs when a run-status or device-status code falls outside the known set
* issue: Center the device Status pills on the Devices tab
* feature: Theme-align the action glyph colors on the Host MIB views via per-theme CSS instead of hardcoded inline colors
* dev: Replace the translation-template CI check that regenerated locales/po/cacti.pot and compared it with a diff-based gate (tests/bin/check-i18n-pot.php) requiring cacti.pot to be updated only when a pull request adds, removes, or modifies an i18n function call
* dev: Relocate schema provisioning into includes/database.php (loaded from setup.php) and add the fleet manifest.json with upgrade-time file pruning
* dev: Enforce patch coverage of changed lines in CI and remove the inert COMPOSER_ROOT_VERSION env from the Pest step, and measure coverage with xdebug so the plugin's own sources are instrumented
* issue: Fix PHP 8.1+ 'float-string to int loses precision' deprecation from runCollector() by typing the last-run timestamp parameter as float
* dev: Bring all plugin code to PHPStan level 8 with accurate parameter and return types
* dev: Adopt Cacti core's SNMP library and remove the bundled snmp.php/snmp_functions.php copies
* issue: Fix Host Type CSV import (undefined name/version values and header-row parsing)
* issue: Correct stale html_header_sort() and form_save_button() argument order
* security: Add a version-safe CSP nonce (`plugin_hmib_csp_nonce()`) to every inline `<script>` tag so pages stay compatible with Cacti's Content-Security-Policy nonce enforcement, while falling back cleanly on older Cacti releases that lack the `CactiSecureHeaders` class
* feature: Add page counter to the processes filter for the Summary tab
* feature: Move the main hmib interface to prepared statements where possible
* issue#35: Hmib tab produces fatal memory error no matter the amount of memory allocated
* issue: The processes sub-tab does not search on the command parameters
* issue: Fix Graph Filtering to include proper regular expression

--- 3.5 ---
* feature: Add new Data Query to track Application statistics at the Device Level
* issue#33: Escaping issues with SQL arguments
* issue#34: Multiple HMIB errors with Graphing function returning no data.
* issue: Fix warnings generated in more recent versions of PHP

--- 3.4 ---
* feature: Support for 1.2.24+ packages
* issue: Relocate package files information to correct location
* issue: Automation problems with PHP8+

--- 3.3 ---
* feature: Move images to glyphs
* feature: Minimum version Cacti 1.2.11
* issue#19: Cannot filter on 'Unknown'
* issue#22: Export of OS Types not working
* issue#23: Various issues with Host Type and filtering
* issue#24: 500 Error in Inventory page
* issue#26: Undefined variable i in hmib_types.php
* issue#27: The each() function is deprecated in hmib_types.php
* issue#28: Invalid characters cause error in HEX detection
* issue: Don't collect batch process history

--- 3.2 ---
* feature: PHP 7.2 compatibility
* issue#15: Searching from the hmib pages do not work with international characters
* issue#21: poller_hmib.php[681]:sizeof(), CactiErrorHandler())
* issue: Update language support
* issue: Correct some stored XSS issues

--- 3.1 ---
* feature: More of the user interface using ajax callbacks
* issue#8: Correct sql errors in hmib.php page

--- 3.0 ---
* Cacti 1.0 Compatibility

--- 2.0 ---
* feature: Support new Theme engine
* feature: Add new Summary Graph Template for average and peak memory use by process
* bug: Template detection is automatic now based upon Hash
* bug: trim core# off of processes that include that variable in the name of the binary
* bug: cpu graphs were still using snmp and not the hmib information, migrate to hmib.
* note: this may cause existing cpu graphs to break.

--- 1.5 ---
* bug#0002123: hmib does not handle sysContact or other field that contains an apostrophe
* bug: Remove regex support for SysDesc as it is breaking discovery

--- 1.4 ---
* bug: Performance issues when viewing pages
* bug: Pagination issues with Use History

--- 1.3 ---
* feature: Support Ugroup Plugin
* bug: Workaround bug in IE6
* bug: Don't throw warning when using 'Use History'

--- 1.2 ---
* feature: provide use history interface
* feature: allow sysDescMatch and sysObjectIDMatch use regex
* bug: make UI W3C compliant
* bug: respect Host edit permissions
* bug: general UI inconsistencies
* bug: rescan desice was broken
* bug: fix various drill downs from summary page

--- 1.1 ---
* feature: provide statistics for visualization of hmib runtime
* bug: issue when deleting dead hosts

--- 1.0 ---
* Initial release

-----------------------------------------------
Copyright (c) 2004-2026 - The Cacti Group, Inc.
