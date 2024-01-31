# hmib

The hmib plugin is designed to collect SNMP information from Cacti Devices that
support the SNMP Host Resources Mib structure.  This SNMP information includes
performance metrics like:

* CPU utilization

* Running processes

* Running applications

* Physical and Virtual Memory utilization

* Hardware details

* Device details

* Storage details

* Installed software

This Host Mib information in then grouped into a series of Dashboards for Cacti
Administrators to be able to view the status of their Devices.  When installed
Cacti will automatically discover your Cacti Devices that support the Host
Resources MIB and add them to hmib.  Once there, you can classify each of the
discovered Devices operating system, and when that step is completed properly,
the Cacti 'hmib' tab will classify system usage by Operating System.

The hmib plugin also includes several Graph Templates that allow you to track
application memory and cpu utilization across you environment as well as
several other Cacti Graphs.

A few of these Cacti Graph Templates include script files that will allow Cacti
to leverage the HMIB data for graphing instead of directly accessing the Cacti
Devices for that information.  This approach leads to reduce Cacti polling times
due to the elimination of latency obtaining the information.

## Installation

Just like any other Cacti plugin, untar the package to the Cacti plugins
directory, rename the directory to 'hmib', and then from Cacti's Plugin
Management interface, Install and Enable the plugin.

Once you have installed the hmib plugin, you need to install two Packages for Graphing.
First you need to install the 'Host_Mib_Summary_Device.xml.gz' package.  This Device
Package installs a Device Template and associated Data Queries and Graph Templates to
track roll-up statistics by OS Type and by application name.

The second package to install is the 'Host_Mib_Device_Level_Application_stats.xml.gz'.
This package  includes a Data Query to track Application memory and CPU use at the 
individual Device level.  You will need to manually add this Data Query to either the Device Template for your HMIB type devices and Sync Templates, or manually add it to devices 
that you wish to track specific memory and CPU stats by application.  For this specific
Data Query, you will either have to manually create the Graphs, or create an automation
rule to create the Graphs that you are interested in.

Once everything is in place, you need to goto Cacti's Settings page and locate
the 'Host MIB' tab and complete the hmib's setup.  From there, you can set
collections frequencies and levels of parallelism.  You can also turn on the
automation of Cacti Devices and Graphs as well.  The hmib plugin also monitors
application usage over time.

## Bugs and Feature Enhancements

Bug and feature enhancements for the hmib plugin are handled in GitHub.  If you
find a first search the Cacti forums for a solution before creating an issue in
GitHub.

## ChangeLog

--- develop ---

* issue: Fix warnings generated in more recent versions of PHP
* issue#34: Multiple HMIB errors with Graphing function returning no data.
* feature: Add new Data Query to track Application statistics at the Device Level

--- 3.4 ---

* issue: Relocate package files information to correct location
* issue: Automation problems with PHP8+
* feature: Support for 1.2.24+ packages

--- 3.3 ---

* issue#19: Cannot filter on 'Unknown'
* issue#22: Export of OS Types not working
* issue#23: Various issues with Host Type and filtering
* issue#24: 500 Error in Inventory page
* issue#26: Undefined variable i in hmib_types.php
* issue#27: The each() function is deprecated in hmib_types.php
* issue#28: Invalid characters cause error in HEX detection
* issue: Don't collect batch process history
* feature: Move images to glyphs
* feature: Minimum version Cacti 1.2.11

--- 3.2 ---

* issue#15: Searching from the hmib pages do not work with international characters
* issue#21: poller_hmib.php[681]:sizeof(), CactiErrorHandler())
* feature: PHP 7.2 compatibility
* issue: Update language support
* issue: Correct some stored XSS issues

--- 3.1 ---

* feature: More of the user interface using ajax callbacks
* issue#8: Correct sql errors in hmib.php page

--- 3.0 ---

* Cacti 1.0 Compatibility

--- 2.0 ---

* bug: Template detection is automatic now based upon Hash
* feature: Add new Summary Graph Template for average and peak memory use by process
* bug: trim core# off of processes that include that variable in the name of the binary
* bug: cpu graphs were still using snmp and not the hmib information, migrate to hmib.
* note: this may cause existing cpu graphs to break.
* feature: Support new Theme engine

--- 1.5 ---

* bug#0002123: hmib does not handle sysContact or other field that contains an apostrophe
* bug: Remove regex support for SysDesc as it is breaking discovery

--- 1.4 ---

* bug: Performance issues when viewing pages
* bug: Pagination issues with Use History

--- 1.3 ---

* bug: Workaround bug in IE6
* bug: Don't throw warning when using 'Use History'
* feature: Support Ugroup Plugin

--- 1.2 ---

* feature: provide use history interface
* bug: make UI W3C compliant
* bug: respect Host edit permissions
* bug: general UI inconsistencies
* bug: rescan desice was broken
* bug: fix various drill downs from summary page
* feature: allow sysDescMatch and sysObjectIDMatch use regex

--- 1.1 ---

* big: issue when deleting dead hosts
* feature: provide statistics for visualization of hmib runtime

--- 1.0 ---

* Initial release

-----------------------------------------------
Copyright (c) 2004-2024 - The Cacti Group, Inc.
