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

	// Test-only seams: never set in production; see tests/bootstrap.php.
	public static $testAllowedHosts = null;
	public static $testHostIpMap = null;
	public static $dnsResolver = null;

	private static function resolveForTest($url)
	{
		$parts = parse_url($url);
		if ($parts === false || !isset($parts['host'], $parts['port'])) return false;
		$host = strtolower($parts['host']);
		$port = (int)$parts['port'];
		$key = $host.':'.$port;
		if (is_array(self::$testHostIpMap) && isset(self::$testHostIpMap[$key])) {
			$ips = array_values(self::$testHostIpMap[$key]);
			if (!$ips) return false;
			return array('url' => $url, 'host' => $host, 'port' => $port, 'ip' => $ips[0], 'ips' => $ips);
		}
		if (!is_array(self::$testAllowedHosts) || !in_array($key, self::$testAllowedHosts, true)) return false;
		return array('url' => $url, 'host' => $host, 'port' => $port, 'ip' => '127.0.0.1', 'ips' => array('127.0.0.1'));
	}

	/**
	 * Parse and validate a URL, resolve its host, and return the vetted target.
	 *
	 * @return array|false array('url' => string, 'host' => string, 'port' => int, 'ip' => string, 'ips' => string[]) or false
	 */
	public static function resolve($url)
	{
		if (!is_string($url) || $url === '' || strlen($url) > 2048) return false;
		if (self::$testAllowedHosts !== null || self::$testHostIpMap !== null) {
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

		$ips = self::resolvePublicIps($host);
		if ($ips === false) return false;

		return array('url' => $url, 'host' => $host, 'port' => $port, 'ip' => $ips[0], 'ips' => $ips);
	}

	/**
	 * Build a CURLOPT_RESOLVE entry pinning all validated addresses for dual-stack fallback.
	 */
	public static function formatResolveEntry(array $target)
	{
		$host = (string)$target['host'];
		if (strpos($host, ':') !== false && $host[0] !== '[') {
			$host = '[' . $host . ']';
		}
		$ips = isset($target['ips']) && is_array($target['ips']) && $target['ips']
			? $target['ips']
			: array((string)$target['ip']);
		$formatted = array();
		foreach ($ips as $ip) {
			$ip = (string)$ip;
			$formatted[] = (strpos($ip, ':') !== false && $ip[0] !== '[') ? ('[' . $ip . ']') : $ip;
		}
		return $host . ':' . (int)$target['port'] . ':' . implode(',', $formatted);
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
	 * Resolve host to all public IPs (preserving DNS order, deduplicated).
	 * Returns false if resolution fails or ANY resolved address is non-public.
	 */
	public static function resolvePublicIps($host)
	{
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return self::isPublicIp($host) ? array($host) : false;
		}
		$ips = array();
		if (is_callable(self::$dnsResolver)) {
			$resolved = call_user_func(self::$dnsResolver, $host);
			if (is_array($resolved)) {
				$ips = $resolved;
			}
		} else {
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
		}
		if (!$ips) return false;
		$unique = array();
		foreach ($ips as $ip) {
			if (!is_string($ip) || !self::isPublicIp($ip)) return false;
			if (!in_array($ip, $unique, true)) {
				$unique[] = $ip;
			}
		}
		return $unique;
	}

	/**
	 * Resolve host to its first public IP (backward-compatible helper).
	 */
	public static function resolvePublicIp($host)
	{
		$ips = self::resolvePublicIps($host);
		return ($ips === false) ? false : $ips[0];
	}

	/**
	 * True only for globally routable unicast addresses.
	 * Blocks loopback, private, link-local, CGNAT, multicast, reserved, and
	 * IPv4-mapped/compatible IPv6 forms of those, plus special-use IPv6 ranges.
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
			// Reject fc00::/7 (ULA), fe80::/10 (link-local), 2002::/16 (6to4),
			// and special-use ranges inside 2000::/3 (IANA RFC 6890):
			// - 2001::/23 (IETF protocol assignments, incl. Teredo 2001::/32,
			//   Benchmarking 2001:2::/48, ORCHID 2001:10::/28, ORCHIDv2 2001:20::/28)
			// - 2001:db8::/32 (Documentation, RFC 3849)
			$first = ord($bin[0]);
			if (($first & 0xfe) === 0xfc) return false;
			if ($first === 0xfe && (ord($bin[1]) & 0xc0) === 0x80) return false;
			if ($first === 0x20 && ord($bin[1]) === 0x02) return false;
			if ($first === 0x20 && ord($bin[1]) === 0x01) {
				// 2001:0000::/23 covers 2001:0000:: through 2001:01ff::
				if ((ord($bin[2]) & 0xfe) === 0x00) return false;
				// 2001:0db8::/32 documentation prefix
				if ($bin[2] === "\x0d" && $bin[3] === "\xb8") return false;
			}
			// 64:ff9b::/96 well-known NAT64 (only allow if embedded IPv4 is public;
			// note 64:ff9b:1::/48 local-use NAT64 is rejected below by 2000::/3 check).
			if (substr($bin, 0, 12) === "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00") {
				return self::isPublicIp(inet_ntop(substr($bin, 12)));
			}
			// Only global unicast 2000::/3 is allowed (blocks 100::/64 discard, 64:ff9b:1::/48, etc.).
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
