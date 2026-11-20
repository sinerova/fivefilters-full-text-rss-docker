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
    default:
        http_response_code(404);
        echo 'not found';
        return true;
}
