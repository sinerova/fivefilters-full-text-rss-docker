<?php
/**
 * HTML to plain text for &content=text (3.9) and &content=text80 (3.9.1: wrap width in the parameter).
 *
 * Written for this fork rather than vendored: it walks a parsed DOM and only ever emits text nodes,
 * alt text and link targets, so no markup can survive into the output whatever the input contains.
 * Lines are wrapped at the given width (default 70) at spaces; words longer than the width, such as
 * URLs, are left whole.
 */
final class HtmlToText {
	const DEFAULT_WIDTH = 70;
	const MIN_WIDTH = 20;
	const MAX_WIDTH = 500;
	const MAX_DEPTH = 100;

	private $width;
	private $blocks = array(); // each: array(text, is a list item line, ends a list)
	private $buf = '';
	private $inPre = false;
	private $quote = 0;
	private $lists = array();   // stack of array(type, counter)
	private $marker = null;     // pending list marker for the next flushed block

	private static $skip = array('script', 'style', 'head', 'title', 'template', 'iframe', 'object', 'embed', 'svg', 'canvas', 'noscript', 'select', 'textarea', 'audio', 'video', 'map');
	private static $block = array('p', 'div', 'section', 'article', 'header', 'footer', 'nav', 'aside', 'main', 'figure', 'figcaption', 'form', 'address', 'dl', 'dt', 'dd', 'details', 'summary', 'fieldset', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'caption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6');

	/**
	 * Parse the value of the content request parameter: "text" or "text<width>".
	 * @return int|null wrap width, or null if the value does not ask for plain text
	 */
	public static function widthFromParameter($value) {
		if (!is_string($value) || !preg_match('/^text(\d{1,4})?$/', $value, $m)) return null;
		if (!isset($m[1]) || $m[1] === '') return self::DEFAULT_WIDTH;
		return max(self::MIN_WIDTH, min(self::MAX_WIDTH, (int)$m[1]));
	}

	public static function convert($html, $width = self::DEFAULT_WIDTH) {
		$c = new self();
		$c->width = max(self::MIN_WIDTH, min(self::MAX_WIDTH, (int)$width));
		return $c->run((string)$html);
	}

	private function run($html) {
		if (trim($html) === '') return '';
		$parser = new Masterminds\HTML5(array('disable_html_ns' => true));
		$fragment = $parser->loadHTMLFragment($html);
		$this->walk($fragment, 0);
		$this->flush();
		$out = '';
		foreach ($this->blocks as $i => $b) {
			// Consecutive items of the same list sit on adjacent lines; everything else is a paragraph apart.
			if ($i > 0) $out .= ($b[1] && $this->blocks[$i - 1][1] && !$this->blocks[$i - 1][2]) ? "\n" : "\n\n";
			$out .= $b[0];
		}
		$out = preg_replace("/\n{3,}/", "\n\n", $out);
		return trim((string)$out);
	}

	private function walk(DOMNode $node, $depth) {
		foreach ($node->childNodes as $n) {
			if ($n->nodeType === XML_TEXT_NODE || $n->nodeType === XML_CDATA_SECTION_NODE) {
				$t = (string)$n->nodeValue;
				if (!$this->inPre) $t = preg_replace('/[ \t\r\n\f\x{00A0}]+/u', ' ', $t);
				$this->buf .= $t;
				continue;
			}
			if ($n->nodeType !== XML_ELEMENT_NODE) continue; // comments, processing instructions
			$tag = strtolower($n->nodeName);
			if (in_array($tag, self::$skip, true)) continue;
			if ($depth >= self::MAX_DEPTH) { // pathological nesting: keep the text, drop the structure
				$this->buf .= preg_replace('/\s+/u', ' ', (string)$n->textContent);
				continue;
			}
			$this->element($n, $tag, $depth);
		}
	}

	private function element(DOMElement $n, $tag, $depth) {
		switch (true) {
			case $tag === 'br':
				$this->buf .= "\n";
				return;
			case $tag === 'hr':
				$this->flush();
				$this->blocks[] = array(str_repeat('-', min($this->width, 40)), false, false);
				return;
			case $tag === 'img':
				$alt = trim(preg_replace('/\s+/u', ' ', (string)$n->getAttribute('alt')));
				if ($alt !== '') $this->buf .= '['.$alt.']';
				return;
			case $tag === 'a':
				$start = mb_strlen($this->buf, 'UTF-8');
				$this->walk($n, $depth + 1);
				$href = trim((string)$n->getAttribute('href'));
				$text = trim(mb_substr($this->buf, $start, null, 'UTF-8'));
				// Only absolute http(s) targets are worth printing; skip when the text already is the URL.
				if (preg_match('!^https?://\S+$!i', $href) && $text !== '' && strcasecmp($text, $href) !== 0) {
					$this->buf .= ' ['.$href.']';
				}
				return;
			case $tag === 'pre':
				$this->flush();
				$this->inPre = true;
				$this->walk($n, $depth + 1);
				$this->flush();
				$this->inPre = false;
				return;
			case $tag === 'blockquote':
				$this->flush();
				$this->quote++;
				$this->walk($n, $depth + 1);
				$this->flush();
				$this->quote--;
				return;
			case $tag === 'ul' || $tag === 'ol':
				$this->flush();
				$this->lists[] = array($tag, 0);
				$this->walk($n, $depth + 1);
				$this->flush();
				array_pop($this->lists);
				// a finished top-level list is followed by a blank line
				if (!$this->lists && $this->blocks) $this->blocks[count($this->blocks) - 1][2] = true;
				return;
			case $tag === 'li':
				$this->flush();
				$top = count($this->lists) - 1;
				if ($top >= 0) {
					$this->lists[$top][1]++;
					$this->marker = ($this->lists[$top][0] === 'ol') ? $this->lists[$top][1].'. ' : '* ';
				}
				$this->walk($n, $depth + 1);
				$this->flush();
				return;
			case $tag === 'td' || $tag === 'th':
				$this->walk($n, $depth + 1);
				$this->buf .= ' ';
				return;
			case in_array($tag, self::$block, true):
				$this->flush();
				$this->walk($n, $depth + 1);
				$this->flush();
				return;
			default: // inline
				$this->walk($n, $depth + 1);
		}
	}

	/** Turn the inline buffer into a block (wrapped, quoted, list-marked) and reset it. */
	private function flush() {
		$text = $this->buf;
		$this->buf = '';
		$marker = $this->marker;
		$this->marker = null;
		$text = $this->inPre ? rtrim($text, "\r\n") : trim($text, " \n");
		if (trim($text) === '') return;

		$indent = $this->lists ? str_repeat('  ', count($this->lists) - 1) : '';
		$first = $indent.($marker !== null ? $marker : ($this->lists ? '  ' : ''));
		$rest = $indent.($marker !== null ? str_repeat(' ', strlen($marker)) : ($this->lists ? '  ' : ''));
		$quote = str_repeat('> ', $this->quote);

		$lines = array();
		$isFirst = true;
		foreach (explode("\n", $text) as $line) {
			$line = $this->inPre ? $line : trim($line);
			if (!$this->inPre) {
				$room = max(10, $this->width - strlen($quote) - strlen($rest));
				$wrapped = $this->wrap($line, $room);
			} else {
				$wrapped = array($line);
			}
			foreach ($wrapped as $w) {
				$lines[] = $quote.($isFirst ? $first : $rest).$w;
				$isFirst = false;
			}
		}
		// [text, is a list item line, ends a list]
		$this->blocks[] = array(rtrim(implode("\n", $lines)), $marker !== null, false);
	}

	/** Greedy wrap at spaces by character count; over-long words are kept whole. */
	private function wrap($line, $room) {
		if ($line === '') return array('');
		$out = array();
		$cur = '';
		foreach (explode(' ', $line) as $word) {
			if ($word === '') continue;
			if ($cur === '') {
				$cur = $word;
			} elseif (mb_strlen($cur, 'UTF-8') + 1 + mb_strlen($word, 'UTF-8') <= $room) {
				$cur .= ' '.$word;
			} else {
				$out[] = $cur;
				$cur = $word;
			}
		}
		if ($cur !== '') $out[] = $cur;
		return $out ?: array('');
	}
}
