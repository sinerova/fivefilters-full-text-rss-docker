<?php
// Session and CSRF helpers for Full-Text RSS admin pages (GNU AGPLv3).

function ftr_is_https($options = null) {
	if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
		return true;
	}
	if ($options !== null && !empty($options->trust_forwarded_headers) && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
		$proto = strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
		if ($proto === 'https') {
			return true;
		}
	}
	return false;
}

function ftr_start_session($options = null) {
	if (session_status() !== PHP_SESSION_ACTIVE) {
		@ini_set('session.cookie_httponly', '1');
		@ini_set('session.cookie_samesite', 'Lax');
		@ini_set('session.use_strict_mode', '1');
		if (ftr_is_https($options)) {
			@ini_set('session.cookie_secure', '1');
		}
		session_start();
	}
	if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
}

function ftr_csrf_token() {
	if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['csrf_token'];
}

function ftr_csrf_field() {
	return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars(ftr_csrf_token(), ENT_QUOTES, 'UTF-8').'" />';
}

function ftr_verify_csrf($submitted = null) {
	if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
		return false;
	}
	if ($submitted === null) {
		$submitted = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
	}
	if (!is_string($submitted) || $submitted === '') {
		return false;
	}
	return hash_equals($_SESSION['csrf_token'], $submitted);
}

function ftr_require_csrf() {
	if (!ftr_verify_csrf()) {
		http_response_code(403);
		die('Invalid or missing CSRF token.');
	}
}
