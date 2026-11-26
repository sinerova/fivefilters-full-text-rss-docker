<?php
/**
 * Host name matching for the allowed_hosts option.
 *
 * A host matches an entry when it is equal to it or is a subdomain of it
 * (comparison at label boundaries, never a substring match). Case, a trailing dot
 * and, where ext/intl is available, IDN spelling are ignored.
 */
final class HostList {
	public static function normalise($host) {
		$host = strtolower(rtrim(trim((string)$host), '.'));
		if ($host !== '' && preg_match('/[^\x00-\x7f]/', $host) && function_exists('idn_to_ascii')) {
			$puny = defined('INTL_IDNA_VARIANT_UTS46') ? @idn_to_ascii($host, 0, INTL_IDNA_VARIANT_UTS46) : @idn_to_ascii($host);
			if ($puny !== false && $puny !== '') $host = strtolower($puny);
		}
		return $host;
	}

	/** @param string[] $list */
	public static function matches($host, array $list) {
		$host = self::normalise($host);
		if ($host === '') return false;
		foreach ($list as $entry) {
			$entry = ltrim(self::normalise($entry), '.');
			if ($entry === '') continue;
			if ($host === $entry) return true;
			if (strlen($host) > strlen($entry) && substr($host, -strlen($entry) - 1) === '.'.$entry) return true;
		}
		return false;
	}

	/** True if the URL's host is covered by $list. URLs without a host never match. */
	public static function urlMatches($url, array $list) {
		$host = parse_url((string)$url, PHP_URL_HOST);
		return is_string($host) && self::matches($host, $list);
	}
}
