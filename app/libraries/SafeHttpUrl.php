<?php
/**
 * SSRF guard for outbound requests made on behalf of a user-supplied URL.
 *
 * Every URL is checked before a connection is opened and again for each
 * redirect hop. The host is resolved here, every resolved address must be
 * public, and the connection is pinned to the vetted address (CURLOPT_RESOLVE),
 * so a second DNS answer (DNS rebinding) cannot redirect the request inward.
 *
 * Licensed under the GNU AGPL v3 (same as the rest of Full-Text RSS).
 */
class SafeHttpUrl
{
	const ALLOWED_SCHEMES = array('http', 'https');
	const DEFAULT_PORTS = array('http' => 80, 'https' => 443);
	const ALLOWED_PORTS = array(80, 443, 8080, 8443);

	// Test-only seam: when set, only these hosts (with explicit port) are allowed to resolve to loopback.
	// Never set in production; see tests/bootstrap.php.
	public static $testAllowedHosts = null;

	private static function resolveForTest($url)
	{
		$parts = parse_url($url);
		if ($parts === false || !isset($parts['host'], $parts['port'])) return false;
		$key = strtolower($parts['host']).':'.(int)$parts['port'];
		if (!in_array($key, self::$testAllowedHosts, true)) return false;
		return array('url' => $url, 'host' => strtolower($parts['host']), 'port' => (int)$parts['port'], 'ip' => '127.0.0.1');
	}

	/**
	 * Parse and validate a URL, resolve its host, and return the vetted target.
	 *
	 * @return array|false array('url' => string, 'host' => string, 'port' => int, 'ip' => string) or false
	 */
	public static function resolve($url)
	{
		if (!is_string($url) || $url === '' || strlen($url) > 2048) return false;
		if (self::$testAllowedHosts !== null) {
			return self::resolveForTest($url);
		}
		if (preg_match('/[\x00-\x20\x7f]/', $url)) return false;

		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) return false;

		$scheme = strtolower($parts['scheme']);
		if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) return false;
		if (isset($parts['user']) || isset($parts['pass'])) return false;

		$host = strtolower(rtrim($parts['host'], '.'));
		if ($host !== '' && $host[0] === '[' && substr($host, -1) === ']') {
			$host = substr($host, 1, -1);
		}
		if ($host === '' || strlen($host) > 253) return false;
		if (strpos($host, ':') !== false) {
			if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return false;
		} elseif (!self::isAllowedHostname($host)) {
			return false;
		}

		$port = isset($parts['port']) ? (int)$parts['port'] : self::DEFAULT_PORTS[$scheme];
		if (!in_array($port, self::ALLOWED_PORTS, true)) return false;

		$ip = self::resolvePublicIp($host);
		if ($ip === false) return false;

		return array('url' => $url, 'host' => $host, 'port' => $port, 'ip' => $ip);
	}

	public static function isValid($url)
	{
		return self::resolve($url) !== false;
	}

	/**
	 * Reject literal IPs written in odd notations and hosts that are obviously internal.
	 * Actual IP checks happen after DNS resolution in resolvePublicIp().
	 */
	private static function isAllowedHostname($host)
	{
		if ($host === 'localhost' || substr($host, -10) === '.localhost') return false;
		if (substr($host, -6) === '.local' || substr($host, -9) === '.internal') return false;
		if (substr($host, -5) === '.arpa') return false;
		if (!preg_match('/^[a-z0-9.\-]+$/', $host)) return false;
		if (preg_match('/^[0-9.]+$/', $host) && !filter_var($host, FILTER_VALIDATE_IP)) return false;
		return true;
	}

	/**
	 * Resolve host to exactly one public IP. Returns false if any address is non-public.
	 */
	public static function resolvePublicIp($host)
	{
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return self::isPublicIp($host) ? $host : false;
		}
		$ips = array();
		$records = @dns_get_record($host, DNS_A | DNS_AAAA);
		if (is_array($records)) {
			foreach ($records as $rec) {
				if (isset($rec['ip'])) $ips[] = $rec['ip'];
				if (isset($rec['ipv6'])) $ips[] = $rec['ipv6'];
			}
		}
		if (!$ips) {
			$v4 = @gethostbynamel($host);
			if (is_array($v4)) $ips = $v4;
		}
		if (!$ips) return false;
		foreach ($ips as $ip) {
			if (!self::isPublicIp($ip)) return false;
		}
		return $ips[0];
	}

	/**
	 * True only for globally routable unicast addresses.
	 * Blocks loopback, private, link-local, CGNAT, multicast, reserved, and
	 * IPv4-mapped/compatible IPv6 forms of those, plus cloud metadata ranges.
	 */
	public static function isPublicIp($ip)
	{
		if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;

		if (strpos($ip, ':') !== false) {
			$bin = inet_pton($ip);
			if ($bin === false) return false;
			// IPv4-mapped ::ffff:a.b.c.d and IPv4-compatible ::a.b.c.d
			$prefix = substr($bin, 0, 12);
			if ($prefix === str_repeat("\x00", 10) . "\xff\xff" || $prefix === str_repeat("\x00", 12)) {
				return self::isPublicIp(inet_ntop(substr($bin, 12)));
			}
			// Reject 64:ff9b::/96 (NAT64) to internal space, fc00::/7 (ULA), fe80::/10 (link-local),
			// and 2002::/16 (6to4, can embed private IPv4).
			$first = ord($bin[0]);
			if (($first & 0xfe) === 0xfc) return false;
			if ($first === 0xfe && (ord($bin[1]) & 0xc0) === 0x80) return false;
			if ($first === 0x20 && ord($bin[1]) === 0x02) return false;
			if (substr($bin, 0, 12) === "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00") {
				return self::isPublicIp(inet_ntop(substr($bin, 12)));
			}
			// Only global unicast 2000::/3 is allowed.
			return ($first & 0xe0) === 0x20;
		}

		$long = ip2long($ip);
		if ($long === false) return false;
		$deny = array(
			array('0.0.0.0', 8), array('10.0.0.0', 8), array('100.64.0.0', 10),
			array('127.0.0.0', 8), array('169.254.0.0', 16), array('172.16.0.0', 12),
			array('192.0.0.0', 24), array('192.0.2.0', 24), array('192.88.99.0', 24),
			array('192.168.0.0', 16), array('198.18.0.0', 15), array('198.51.100.0', 24),
			array('203.0.113.0', 24), array('224.0.0.0', 4), array('240.0.0.0', 4),
		);
		foreach ($deny as $cidr) {
			$net = ip2long($cidr[0]);
			$mask = -1 << (32 - $cidr[1]);
			if (($long & $mask) === ($net & $mask)) return false;
		}
		return true;
	}
}
