<?php
/**
 * Title, author and date from schema.org Article data in JSON-LD <script> elements.
 *
 * Pure function over script contents (no DOM, no I/O) so it can be tested directly and holds no
 * state between calls: nothing can leak from one fetched item into the next.
 *
 * Page content is untrusted. Everything is bounded (script count, bytes, JSON depth, entities) and
 * only plain scalar strings are used; values are reduced to text (tags removed, entities decoded,
 * whitespace collapsed). Callers still escape for their output format.
 */
final class JsonLd {
	const MAX_SCRIPTS = 10;
	const MAX_BYTES = 524288;
	const MAX_DEPTH = 16;
	const MAX_ENTITIES = 500;
	const MAX_AUTHORS = 10;
	const MAX_TITLE = 500;
	const MAX_NAME = 200;

	/**
	 * @param string[] $scripts contents of <script type="application/ld+json"> elements
	 * @return array array('title' => string|null, 'authors' => string[], 'date' => int|null)
	 */
	public static function extract(array $scripts) {
		$result = array('title' => null, 'authors' => array(), 'date' => null);
		$entities = array();
		foreach (array_slice($scripts, 0, self::MAX_SCRIPTS) as $raw) {
			if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) continue;
			$data = self::decode($raw);
			if ($data === null) continue;
			self::collect($data, $entities, 0);
			if (count($entities) >= self::MAX_ENTITIES) break;
		}

		// Index by @id so that author references such as {"@id": "#/schema/person/1"} can be resolved.
		$byId = array();
		foreach ($entities as $e) {
			if (isset($e['@id']) && is_string($e['@id']) && !isset($byId[$e['@id']])) $byId[$e['@id']] = $e;
		}

		// Use the data only if the page describes exactly one article. A page with several (a listing,
		// say) says nothing reliable about the one article we are extracting.
		$articles = array();
		foreach ($entities as $e) {
			if (self::isArticle($e)) $articles[md5(serialize($e))] = $e;
		}
		if (count($articles) !== 1) return $result;
		$article = reset($articles);

		foreach (array('headline', 'name') as $key) {
			if (isset($article[$key]) && ($t = self::text($article[$key], self::MAX_TITLE)) !== null) {
				$result['title'] = $t;
				break;
			}
		}
		if (isset($article['author'])) {
			$result['authors'] = self::names($article['author'], $byId, 0);
		}
		foreach (array('datePublished', 'dateCreated') as $key) {
			if (isset($article[$key]) && is_string($article[$key]) && strlen($article[$key]) <= 64) {
				$ts = strtotime(trim($article[$key]));
				if ($ts !== false && $ts > 0) {
					$result['date'] = $ts;
					break;
				}
			}
		}
		return $result;
	}

	private static function decode($raw) {
		$raw = trim($raw);
		// Some sites wrap the JSON in a CDATA section, with or without // comment markers.
		$raw = preg_replace('~^(?://\s*)?<!\[CDATA\[~', '', $raw);
		$raw = preg_replace('~(?://\s*)?\]\]>$~', '', (string)$raw);
		$raw = trim((string)$raw, "\xEF\xBB\xBF \t\r\n");
		if ($raw === '' || ($raw[0] !== '{' && $raw[0] !== '[')) return null;
		$data = json_decode($raw, true, self::MAX_DEPTH, JSON_INVALID_UTF8_SUBSTITUTE);
		return is_array($data) ? $data : null;
	}

	/** Flatten top-level lists and @graph into a list of entities (associative arrays). */
	private static function collect(array $node, array &$out, $depth) {
		if ($depth > 4 || count($out) >= self::MAX_ENTITIES) return;
		if (self::isList($node)) {
			foreach ($node as $item) {
				if (is_array($item)) self::collect($item, $out, $depth + 1);
			}
			return;
		}
		$out[] = $node;
		if (isset($node['@graph']) && is_array($node['@graph'])) {
			self::collect($node['@graph'], $out, $depth + 1);
		}
	}

	private static function isList(array $a) {
		return $a === array() || array_keys($a) === range(0, count($a) - 1);
	}

	private static function isArticle(array $e) {
		if (!isset($e['@type'])) return false;
		$types = is_array($e['@type']) ? $e['@type'] : array($e['@type']);
		foreach ($types as $type) {
			if (!is_string($type)) continue;
			// "https://schema.org/NewsArticle" and "schema:NewsArticle" both reduce to "NewsArticle"
			$type = preg_replace('~^.*[/:#]~', '', $type);
			if (preg_match('/^(?:\w*Article|BlogPosting|SocialMediaPosting|LiveBlogPosting|Report)$/', $type)) return true;
		}
		return false;
	}

	/** A name, a Person or Organization with a name, an @id reference to one, or a list of those. */
	private static function names($value, array $byId, $depth) {
		if ($depth > 3) return array();
		$out = array();
		if (is_string($value)) {
			if (($n = self::text($value, self::MAX_NAME)) !== null) $out[] = $n;
		} elseif (is_array($value)) {
			if (self::isList($value)) {
				foreach ($value as $item) {
					$out = array_merge($out, self::names($item, $byId, $depth + 1));
					if (count($out) >= self::MAX_AUTHORS) break;
				}
			} elseif (isset($value['name'])) {
				if (($n = self::text($value['name'], self::MAX_NAME)) !== null) $out[] = $n;
			} elseif (isset($value['@id']) && is_string($value['@id']) && isset($byId[$value['@id']]) && $byId[$value['@id']] !== $value) {
				$out = self::names($byId[$value['@id']], $byId, $depth + 1);
			}
		}
		return array_slice(array_values(array_unique($out)), 0, self::MAX_AUTHORS);
	}

	/** Plain text from a scalar string, or null (arrays, numbers and empty results are rejected). */
	private static function text($value, $max) {
		if (!is_string($value)) return null;
		// Tags are removed before and after decoding: markup that arrives entity-encoded (&lt;img ...&gt;)
		// would otherwise come out as real markup. These fields are plain text by definition.
		$value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value = strip_tags($value);
		$value = preg_replace('/[\p{Cc}\p{Cf}\s]+/u', ' ', $value);
		if ($value === null) return null; // invalid UTF-8
		$value = trim($value);
		if ($value === '') return null;
		return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') : $value;
	}
}
