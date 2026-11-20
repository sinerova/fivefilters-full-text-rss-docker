<?php
// Test bootstrap. Loads the application libraries without running a request.
// Tests never make real outbound connections: fixtures are served from 127.0.0.1
// and are allowed only through SafeHttpUrl::$testAllowedHosts.

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('FTR_APP_DIR', dirname(__DIR__) . '/app');
define('FTR_FIXTURE_HOST', '127.0.0.1');
define('FTR_FIXTURE_PORT', (int)(getenv('FTR_FIXTURE_PORT') ?: 8082));

require_once FTR_APP_DIR . '/libraries/SafeHttpUrl.php';
require_once FTR_APP_DIR . '/libraries/humble-http-agent/HumbleHttpAgent.php';
require_once FTR_APP_DIR . '/libraries/simplepie/autoloader.php';
require_once FTR_APP_DIR . '/libraries/humble-http-agent/CookieJar.php';
