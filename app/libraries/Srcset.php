<?php
/**
 * srcset handling for extracted content.
 *
 * Since 3.9.5 relative URLs in srcset are rewritten to absolute form, in line with img src
 * (3.8 removed srcset when it only held relative URLs).
 */
final class Srcset {
	const MAX_LENGTH = 16384;
	const MAX_CANDIDATES = 50;

	/**
	 * Split a srcset value into array(array(url, descriptor), ...). Follows the HTML parsing rules in outline:
	 * a candidate URL is a run of non-whitespace (it may contain commas), trailing commas end the candidate,
	 * and the descriptor runs to the next comma outside parentheses.
	 */
	public static function parse($srcset) {
		$srcset = (string)$srcset;
		$out = array();
		$len = strlen($srcset);
		$i = 0;
		while ($i < $len && count($out) < self::MAX_CANDIDATES) {
			while ($i < $len && ($srcset[$i] === ',' || ctype_space($srcset[$i]))) $i++;
			if ($i >= $len) break;
			$start = $i;
			while ($i < $len && !ctype_space($srcset[$i])) $i++;
			$url = substr($srcset, $start, $i - $start);
			$desc = '';
			if (substr($url, -1) === ',') {
				$url = rtrim($url, ',');
			} else {
				$dstart = $i;
				$depth = 0;
				while ($i < $len) {
					$ch = $srcset[$i];
					if ($ch === '(') $depth++;
					elseif ($ch === ')' && $depth > 0) $depth--;
					elseif ($ch === ',' && $depth === 0) break;
					$i++;
				}
				$desc = trim(substr($srcset, $dstart, $i - $dstart));
			}
			if ($url !== '') $out[] = array($url, $desc);
		}
		return $out;
	}

	/**
	 * Return $srcset with every URL made absolute against $base (a string or SimplePie_IRI).
	 * Candidates that are not http(s) after resolution, or whose descriptor is not "<n>w" or "<n>x",
	 * are dropped. Returns '' if nothing usable is left or the value is oversized.
	 */
	public static function absolutize($srcset, $base) {
		$srcset = (string)$srcset;
		if ($srcset === '' || strlen($srcset) > self::MAX_LENGTH) return '';
		$kept = array();
		foreach (self::parse($srcset) as $candidate) {
			list($url, $desc) = $candidate;
			if (!preg_match('!^https?://!i', $url)) {
				$absolute = SimplePie_IRI::absolutize($base, str_replace(' ', '%20', $url));
				$url = $absolute ? (string)$absolute->get_uri() : '';
			}
			if (!preg_match('!^https?://\S+$!i', $url) || substr($url, -1) === ',') continue;
			if ($desc !== '' && !preg_match('/^\d+(\.\d+)?[wx]$/i', $desc)) continue;
			$kept[] = ($desc === '') ? $url : $url.' '.$desc;
		}
		return implode(', ', $kept);
	}
}
