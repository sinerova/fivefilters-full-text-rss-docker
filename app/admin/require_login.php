<?php
// Require login for admin access
// Author: Keyvan Minoukadeh
// Copyright (c) 2013 Keyvan Minoukadeh
// License: AGPLv3
// Date: 2013-05-09
// More info: http://fivefilters.org/content-only/
// Help: http://help.fivefilters.org

/*
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

// Usage
// -----
// This file is included on pages which require admin privileges - e.g. updating the software.
// The username is 'admin' by default and the password should be set in the custom_config.php file.
require_once(dirname(dirname(__FILE__)).'/config.php');
require_once(dirname(__FILE__).'/session_csrf.php');
ftr_start_session($options);

if (!isset($options->admin_credentials) || $options->admin_credentials['username'] == '' || $options->admin_credentials['password'] == '') {
	http_response_code(403);
	die('Admin area is disabled.');
}

// Logout must be a CSRF-protected POST so cross-site GET requests cannot terminate sessions.
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
	ftr_require_csrf();
	$_SESSION = array();
	if (ini_get('session.use_cookies')) {
		$params = session_get_cookie_params();
		@setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
	}
	@session_destroy();
	header('Location: login.php');
	exit;
}

if (!isset($_SESSION['auth']) || $_SESSION['auth'] != 1) {
	if (isset($admin_page)) {
		header('Location: login.php?redirect='.urlencode((string)$admin_page));
	} else {
		header('Location: login.php');
	}
	exit;
}