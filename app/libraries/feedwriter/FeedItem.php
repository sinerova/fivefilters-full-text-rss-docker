<?php
/*
 * Feed item for Full-Text RSS. Stores elements, then builds either an upstream
 * RSS 2.0 item (FeedWriter\Item, see upstream/) or a JSON object.
 *
 * Modified for Full-Text RSS.
 */
class FeedItem
{
    private $elements = array();

    /**
     * Sets an element. Replaces earlier values with the same name.
     */
    public function setElement($name, $content, $attributes = null)
    {
        $this->elements[$name] = array(array('content' => $content, 'attributes' => $attributes));
    }

    /**
     * Adds an element. Repeated names are kept in order (e.g. dc:creator, category).
     */
    public function addElement($name, $content, $attributes = null)
    {
        $this->elements[$name][] = array('content' => $content, 'attributes' => $attributes);
    }

    public function setDescription($description)
    {
        $this->setElement('description', $description);
    }

    public function setTitle($title)
    {
        $this->setElement('title', $title);
    }

    public function setLink($link)
    {
        $safe = FeedWriter::sanitizeHttpUrl($link);
        if ($safe !== '') {
            $this->setElement('link', $safe);
        } else {
            unset($this->elements['link']);
        }
    }

    /**
     * Sets pubDate from a UNIX timestamp or a date string. Unparseable dates are ignored.
     */
    public function setDate($date)
    {
        if (!is_numeric($date)) {
            $date = strtotime((string)$date);
            if ($date === false) {
                return;
            }
        }
        $this->setElement('pubDate', date(DATE_RSS, (int)$date));
    }

    /**
     * Builds an upstream RSS 2.0 item. The og: and twitter: tags are left out of RSS,
     * as before; they appear only in JSON output.
     */
    public function buildRss($feed)
    {
        $item = $feed->createNewItem();
        if (!isset($this->elements['title']) && !isset($this->elements['description'])) {
            $item->addElement('description', '', null, false, true);
        }
        foreach ($this->elements as $name => $values) {
            if (preg_match('/^(twitter|og):/i', $name)) {
                continue;
            }
            foreach ($values as $v) {
                $content = is_array($v['content']) ? $v['content'] : (string)$v['content'];
                $attributes = is_array($v['attributes']) ? array_map('strval', $v['attributes']) : null;
                if ($name === 'guid') {
                    $isPermaLink = isset($attributes['isPermaLink']) && $attributes['isPermaLink'] === 'true';
                    $item->setId((string)$content, $isPermaLink);
                    continue;
                }
                $item->addElement($name, $content, $attributes, false, true);
            }
        }
        return $item;
    }

    /**
     * Builds the item as a JSON object. ":" in names becomes "_".
     * Repeated elements become arrays.
     */
    public function buildJson()
    {
        $out = array();
        foreach ($this->elements as $name => $values) {
            $key = strtr($name, ':', '_');
            $nodes = array();
            foreach ($values as $v) {
                $nodes[] = $this->jsonNode($v['content'], $v['attributes']);
            }
            $out[$key] = count($nodes) > 1 ? $nodes : $nodes[0];
        }
        return (object)$out;
    }

    private function jsonNode($content, $attributes)
    {
        if (is_array($attributes) && count($attributes) && ($content === '' || $content === null)) {
            return array('@attributes' => $attributes);
        }
        return $content;
    }
}
