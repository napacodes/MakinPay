<?php

/*
|--------------------------------------------------------------------------
| Pre-boot maintenance gate
|--------------------------------------------------------------------------
|
| Copied to storage/framework/maintenance.php by MaintenanceModeService in
| place of the framework's own stub, and required by public/index.php before
| Composer's autoloader — so nothing here may depend on Laravel.
|
| It behaves exactly like vendor/laravel/framework's maintenance-mode.stub
| except for how it derives the request path. The framework's stub compares
| the raw $_SERVER['REQUEST_URI'] against '/'.$secret, which means the
| recovery URL silently stops working whenever the URI is not a bare
| "/secret": a trailing slash, a query string, or an installation in a
| sub-directory (domain.com/app/secret) all fall through to the 503 page and
| lock the administrator out of their own site.
|
| Only the keys DownCommand writes into the "down" payload are read here
| (template, except, secret, redirect, status, retry, refresh).
|
*/

// Check if the application is in maintenance mode...
if (! file_exists($down = __DIR__.'/down')) {
    return;
}

// Decode the "down" file's JSON...
$data = json_decode(file_get_contents($down), true);

// Allow framework to handle request if no prerendered template...
if (! is_array($data) || ! isset($data['template'])) {
    return;
}

// Normalise the request path: drop the query string, decode escapes and
// collapse a trailing slash, so "/secret", "/secret/" and "/secret?x=1"
// are all seen as the same path.
$__uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

if (($__mark = strpos($__uri, '?')) !== false) {
    $__uri = substr($__uri, 0, $__mark);
}

$__uri = '/'.ltrim(rawurldecode($__uri), '/');

if ($__uri !== '/') {
    $__uri = rtrim($__uri, '/');

    if ($__uri === '') {
        $__uri = '/';
    }
}

// The application may live under a sub-directory (domain.com/app). Build the
// candidate install roots from the front controller's location so the checks
// below can compare application-relative paths, the same way the router does.
// Both layouts are covered: a document root pointed at public/ ("/app/index.php")
// and the shipped root .htaccess that rewrites into it ("/app/public/index.php").
$__base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');

$__prefixes = [''];

if ($__base !== '' && $__base !== '/') {
    $__prefixes[] = $__base;
    $__prefixes[] = (string) preg_replace('#/public$#', '', $__base);
}

$__paths = [];

foreach (array_unique($__prefixes) as $__prefix) {
    if ($__prefix === '') {
        $__paths[] = $__uri;

        continue;
    }

    if (strpos($__uri, $__prefix.'/') === 0) {
        $__paths[] = substr($__uri, strlen($__prefix));
    }
}

$__paths = array_values(array_unique($__paths));

// Allow framework to handle request if request URI is in the exclude list...
if (! empty($data['except'])) {
    foreach ($__paths as $__path) {
        $__path = $__path !== '/' ? trim($__path, '/') : $__path;

        foreach ((array) $data['except'] as $__except) {
            $__except = $__except !== '/' ? trim($__except, '/') : $__except;

            if ($__except === $__path) {
                return;
            }

            $__except = str_replace('\*', '.*', preg_quote($__except, '#'));

            if (preg_match('#^'.$__except.'\z#u', $__path) === 1) {
                return;
            }
        }
    }
}

// Allow framework to handle maintenance mode bypass route...
if (isset($data['secret']) && $data['secret'] !== '') {
    foreach ($__paths as $__path) {
        if ($__path === '/'.$data['secret']) {
            return;
        }
    }
}

// Determine if maintenance mode bypass cookie is valid...
if (isset($_COOKIE['laravel_maintenance']) && isset($data['secret'])) {
    $payload = json_decode(base64_decode($_COOKIE['laravel_maintenance']), true);

    if (is_array($payload) && is_numeric($payload['expires_at'] ?? null) && isset($payload['mac']) && hash_equals(hash_hmac('sha256', $payload['expires_at'], $data['secret']), $payload['mac']) && (int) $payload['expires_at'] >= time()) {
        return;
    }
}

// Redirect to the proper path if necessary...
if (isset($data['redirect']) && $__uri !== $data['redirect']) {
    http_response_code(302);
    header('Location: '.$data['redirect']);

    exit;
}

// Output the prerendered template...
http_response_code($data['status'] ?? 503);

if (isset($data['retry'])) {
    header('Retry-After: '.$data['retry']);
}

if (isset($data['refresh'])) {
    header('Refresh: '.$data['refresh']);
}

echo $data['template'];

exit;
