<?php
// Deterministic local fixture server for integration tests (PHP built-in server).
// Run: php -S 127.0.0.1:8082 -t tests/fixtures tests/fixtures/router.php
// Serves only the routes below; nothing is fetched from the internet.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($path) {
    case '/article.html':
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/article.html');
        return true;

    case '/escaped.html':
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/escaped.html');
        return true;

    case '/jsonld.html':
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/jsonld.html');
        return true;

    case '/jsonld-feed.xml':
        // First item has JSON-LD (author, date); second has none. Used to check nothing carries over.
        $port = $_SERVER['SERVER_PORT'] ?? 8082;
        $base = "http://127.0.0.1:$port";
        header('Content-Type: application/rss+xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>LD</title><link>' . $base . '/</link><description>d</description>'
            . '<item><title>Feed title one</title><link>' . $base . '/jsonld.html</link><guid>ld1</guid><description>short</description></item>'
            . '<item><title>Feed title two</title><link>' . $base . '/features.html</link><guid>ld2</guid><description>short</description></item>'
            . '</channel></rss>';
        return true;

    case '/features.html':
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/features.html');
        return true;

    case '/feed.xml':
        header('Content-Type: application/rss+xml; charset=utf-8');
        readfile(__DIR__ . '/feed.xml');
        return true;

    case '/nasty.html':
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/nasty.html');
        return true;

    case '/nasty-feed.xml':
        header('Content-Type: application/rss+xml; charset=utf-8');
        readfile(__DIR__ . '/nasty-feed.xml');
        return true;

    case '/gone.html':
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><body>gone</body></html>';
        return true;

    case '/identifier-feed.xml':
        header('Content-Type: application/rss+xml; charset=utf-8');
        readfile(__DIR__ . '/identifier-feed.xml');
        return true;

    case '/big.bin':
        // 20 MiB body: must be cut off by the response size cap.
        header('Content-Type: text/html; charset=utf-8');
        $chunk = str_repeat('A', 1048576);
        for ($i = 0; $i < 20; $i++) {
            echo $chunk;
            flush();
        }
        return true;

    case '/redirect-internal':
        // Redirects to a loopback address that is not on the test allow-list.
        header('Location: http://127.0.0.1:9/secret', true, 302);
        return true;

    case '/redirect-file':
        header('Location: file:///etc/passwd', true, 302);
        return true;

    case '/redirect-ok':
        header('Location: /article.html', true, 301);
        return true;

    case '/redirect-loop':
        $hop = isset($_GET['h']) ? ((int)$_GET['h'] + 1) : 1;
        header('Location: /redirect-loop?h=' . $hop, true, 302);
        return true;

    case '/slow':
        // Holds the connection longer than the fetch timeout used by the tests.
        sleep(5);
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><body>slow</body></html>';
        return true;

    case '/broken-feed.xml':
        header('Content-Type: application/rss+xml; charset=utf-8');
        echo '<rss><channel><title>Unclosed';
        return true;

    case '/binary.pdf':
        header('Content-Type: application/pdf');
        echo '%PDF-1.4 fixture';
        return true;

    case '/gzip-ok.html':
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Encoding: gzip');
        echo gzencode(file_get_contents(__DIR__ . '/article.html'), 6);
        return true;

    case '/gzip-bomb.html':
        // ~15 KiB compressed expanding to 15 MiB (> 10 MiB MAX_RESPONSE_BYTES cap).
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Encoding: gzip');
        echo gzencode(str_repeat('A', 15 * 1024 * 1024), 9);
        return true;

    case '/gzip-corrupt.html':
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Encoding: gzip');
        echo "not-valid-gzip-stream";
        return true;

    case '/meta-refresh-internal.html':
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><head><meta http-equiv="refresh" content="0; url=http://127.0.0.1:9/pwn"></head><body>redirect</body></html>';
        return true;

    case '/meta-refresh-file.html':
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><head><meta http-equiv="refresh" content="0; url=file:///etc/passwd"></head><body>redirect</body></html>';
        return true;

    case '/hostile-article.html':
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><title>&lt;img src=x onerror=alert(4)&gt;Hostile Extracted Title</title></head><body>'
            . '<article><h1 class="instapaper_title">&lt;img src=x onerror=alert(4)&gt;Hostile Extracted Title</h1>'
            . '<p>First paragraph of a long enough article body to be extracted by readability heuristics, with commas, sentences, and safe prose.</p>'
            . '<p>Second paragraph, also long enough, with more words, more commas, and more content so scoring selects this article cleanly.</p>'
            . '<p><a href="javascript:alert(5)">bad link</a> and <a href="/article.html">good link</a></p>'
            . '</article></body></html>';
        return true;

    case '/hostile-feed.xml':
        $port = $_SERVER['SERVER_PORT'] ?? 8082;
        $base = "http://127.0.0.1:$port";
        header('Content-Type: application/rss+xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rss version="2.0"><channel>'
            . '<title>Feed &lt;script&gt;alert(1)&lt;/script&gt; Title</title>'
            . '<link>javascript:alert(1)</link>'
            . '<description>Channel &lt;b&gt;desc&lt;/b&gt;</description>'
            . '<item>'
            . '<title>&lt;img src=x onerror=alert(2)&gt;JsLink Item</title>'
            . '<link>javascript:alert(2)</link>'
            . '<description>Fallback body with &lt;script&gt;alert(3)&lt;/script&gt; text.</description>'
            . '</item>'
            . '<item>'
            . '<title>Feed Item Two</title>'
            . '<link>' . $base . '/hostile-article.html</link>'
            . '<guid>h2</guid>'
            . '<description>short</description>'
            . '</item>'
            . '</channel></rss>';
        return true;

    case '/untitled-fail-feed.xml':
        $port = $_SERVER['SERVER_PORT'] ?? 8082;
        $base = "http://127.0.0.1:$port";
        header('Content-Type: application/rss+xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rss version="2.0"><channel>'
            . '<title></title>'
            . '<link></link>'
            . '<description></description>'
            . '<item>'
            . '<title></title>'
            . '<link>' . $base . '/gone.html</link>'
            . '<guid>u1</guid>'
            . '<description></description>'
            . '</item>'
            . '</channel></rss>';
        return true;

    case '/page-loop':
        $p = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
        $next = ($p < 40) ? ('/page-loop?p=' . ($p + 1)) : '/page-loop?p=1';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><title>Paged Article</title></head><body>'
            . '<article><h1>Paged Article</h1>'
            . '<p>PageMarker-' . $p . ': first paragraph of a long enough article body to be extracted by readability heuristics, with commas, sentences, and enough words.</p>'
            . '<p>Second paragraph on page ' . $p . ', also long enough, with more words, more commas, and more content so scoring prefers this container.</p>'
            . '</article>'
            . '<div class="pagination"><a rel="next" href="' . htmlspecialchars($next, ENT_QUOTES, 'UTF-8') . '">Next page</a></div>'
            . '</body></html>';
        return true;

    case '/many-feed.xml':
        $port = $_SERVER['SERVER_PORT'] ?? 8082;
        $base = "http://127.0.0.1:$port";
        header('Content-Type: application/rss+xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Many</title><link>' . $base . '/</link><description>Many items</description>';
        for ($i = 1; $i <= 40; $i++) {
            echo '<item><title>Item ' . $i . '</title><link>' . $base . '/article.html?i=' . $i . '</link><guid>m' . $i . '</guid><description>d</description></item>';
        }
        echo '</channel></rss>';
        return true;

    default:
        http_response_code(404);
        echo 'not found';
        return true;
}
