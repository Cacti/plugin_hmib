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

-----------------------------------------------
Copyright (c) 2004-2024 - The Cacti Group, Inc.
