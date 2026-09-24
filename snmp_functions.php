<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/**
 * Escapes a command string for safe shell execution, using PHP's native
 * escapeshellcmd() on Unix, or replacing shell-metacharacters with
 * spaces on Windows (where escapeshellcmd()'s behavior is unreliable for
 * this plugin's SNMP command-line construction). Called throughout this
 * plugin wherever an external command string is built for exec_background()/exec().
 *
 * @param string $string The command string to escape.
 *
 * @return string The escaped command string.
 *
 * @global array $config Cacti global configuration array; used to
 *                       detect the server OS.
 */
function cacti_escapeshellcmd($string) {
	global $config;

	if ($config['cacti_server_os'] == 'unix') {
		return escapeshellcmd($string);
	} else {
		$replacements = '#&;`|*?<>^()[]{}$\\';

		for ($i = 0; $i < strlen($replacements); $i++) {
			$string = str_replace($replacements[$i], ' ', $string);
		}

		return $string;
	}
}

/**
 * mimics escapeshellarg, even for windows
 * @param  $string - the string to be escaped
 * @param  $quote  - true: do NOT remove quotes from result; false: do remove quotes
 * @return  - the escaped [quoted|unquoted] string
 *
 * Called throughout this plugin wherever a single shell argument (e.g.
 * an SNMP community string or command parameter) needs to be safely
 * quoted for exec_background()/exec().
 *
 * @param string $string The argument string to escape.
 * @param bool   $quote  Whether to keep the surrounding quote characters
 *                       in the result; defaults to true.
 *
 * @return string The escaped [quoted|unquoted] argument string.
 *
 * @global array $config Cacti global configuration array; used to
 *                       detect the server OS.
 */
function cacti_escapeshellarg($string, $quote = true) {
	global $config;

	/* we must use an apostrophe to escape community names under Unix in case the user uses
	characters that the shell might interpret. the ucd-snmp binaries on Windows flip out when
	you do this, but are perfectly happy with a quotation mark. */
	if ($config['cacti_server_os'] == 'unix') {
		$string = escapeshellarg($string);

		if ($quote) {
			return $string;
		} else {
			// remove first and last char
			return substr($string, 1, (strlen($string) - 2));
		}
	} else {
		if (substr_count($string, CACTI_ESCAPE_CHARACTER)) {
			$string = str_replace(CACTI_ESCAPE_CHARACTER, '\\' . CACTI_ESCAPE_CHARACTER, $string);
		}

		if ($quote) {
			return CACTI_ESCAPE_CHARACTER . $string . CACTI_ESCAPE_CHARACTER;
		} else {
			return $string;
		}
	}
}
