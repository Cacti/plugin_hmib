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
application memory and cpu utilization accross you environment as well as
several other Cacti Graphs.

A few of these Cacti Graph Templates include script files that will allow Cacti
to leverage the HMIB data for graphing instead of directly accessing the Cacti
Devices for that information.  This appoach leads to reduce Cacti polling times
due to the elimination of latency obtaining the information.

## Installation

Just like any other Cacti plugin, untar the package to the Cacti plugins
directory, rename the directory to 'hmib', and then from Cacti's Plugin
Management interface, Install and Enable the pluign.

Once you have installed the hmib plugin, you need to install it's Device
Template, and then in addition, you must copy the script server script files to
Cacti's scripts directory.  Those files are located in the plugins
templates/scripts directory.  In addition, you should copy the script server
resource files to Cacti's resource/script_server directory.  Once there,
graphing for Cacti's build in Host Resources Mib objects will come from the hmib
plugin.  Make backups of all files replaced, just in case you decide to remove
the plugin at a later date.

Once everything is in place, you need to goto Cacti's Settings page and locate
the 'Host MIB' tab and complete the hmib's setup.  From there, you can set
collections frequencies and levels of parallelism.  You can also turn on the
automation of Cacti Devices and Graphs as well.  The hmib plugin also monitors
application usage over time.

Once you have installed and enabled the Plugin, ensure that you import the
Device Package Host_Mib_Summary_Device.xml.gz that is located in the
hmib/templates directory.

## Bugs and Feature Enhancements

Bug and feature enhancements for the hmib plugin are handled in GitHub.  If you
find a first search the Cacti forums for a solution before creating an issue in
GitHub.

## ChangeLog

--- develop ---

* issue#15: Searching from the hmib pages do not work with international
  characters

* issue#21: poller_hmib.php[681]:sizeof(), CactiErrorHandler())

* feature: PHP 7.2 compability

* issue: Update language support

* issue: Correct some stored XSS issues

--- 3.1 ---

* feature: More of the user interface using ajax callbacks

* issue#8: Correct sql errors in hmib.php page

--- 3.0 ---

* Cacti 1.0 Compatibility

--- 2.0 ---

* bug: Template detection is automatic now based upon Hash

* feature: Add new Summary Graph Template for average and peak memory use by
  process

* bug: trim core# off of processes that include that variable in the name of the
  binary

* bug: cpu graphs were still using snmp and not the hmib information, migrate to
  hmib.

* note: this may cause existing cpu graphs to break.

* feature: Support new Theme engine

--- 1.5 ---

* bug#0002123: hmib does not handle sysContact or other field that contains an
  apostrophe

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
