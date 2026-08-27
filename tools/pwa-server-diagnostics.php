<?php
/**
 * Read-only production diagnostics for service-worker/PWA fetch failures.
 *
 * Run from the command line only:
 *   php wp-content/plugins/olama-transportation/tools/pwa-server-diagnostics.php
 *   php .../pwa-server-diagnostics.php --url=https://olama.online --output=/tmp/olama-pwa-report.json
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

function olama_pwa_arg($name, $default = null)
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ((array) $argv as $argument) {
        if (strpos($argument, $prefix) === 0) {
            return substr($argument, strlen($prefix));
        }
    }
    return $default;
}

function olama_pwa_find_wp_load()
{
    $explicit = olama_pwa_arg('wp-root');
    if ($explicit) {
        $candidate = rtrim($explicit, '/\\') . DIRECTORY_SEPARATOR . 'wp-load.php';
        return is_file($candidate) ? $candidate : null;
    }

    $directory = __DIR__;
    for ($depth = 0; $depth < 8; $depth++) {
        $candidate = $directory . DIRECTORY_SEPARATOR . 'wp-load.php';
        if (is_file($candidate)) {
            return $candidate;
        }
        $parent = dirname($directory);
        if ($parent === $directory) {
            break;
        }
        $directory = $parent;
    }
    return null;
}

function olama_pwa_header_subset($headers)
{
    $wanted = array(
        'content-type', 'cache-control', 'expires', 'age', 'vary', 'location',
        'service-worker-allowed', 'server', 'via', 'cf-cache-status', 'x-cache',
        'x-cache-status', 'x-litespeed-cache', 'x-proxy-cache', 'x-redirect-by',
    );
    $result = array();
    foreach ($wanted as $name) {
        $value = wp_remote_retrieve_header($headers, $name);
        if ($value !== '') {
            $result[$name] = (string) $value;
        }
    }
    return $result;
}

function olama_pwa_probe($url)
{
    $started = microtime(true);
    $response = wp_remote_get($url, array(
        'timeout' => 20,
        'redirection' => 5,
        'sslverify' => true,
        'headers' => array(
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'User-Agent' => 'Olama-PWA-Diagnostics/1.0',
        ),
    ));
    $elapsed = round((microtime(true) - $started) * 1000, 1);
    if (is_wp_error($response)) {
        return array(
            'url' => $url,
            'ok' => false,
            'elapsed_ms' => $elapsed,
            'error_code' => $response->get_error_code(),
            'error' => $response->get_error_message(),
        );
    }

    $body = (string) wp_remote_retrieve_body($response);
    $status = (int) wp_remote_retrieve_response_code($response);
    return array(
        'url' => $url,
        'ok' => $status >= 200 && $status < 400,
        'status' => $status,
        'elapsed_ms' => $elapsed,
        'headers' => olama_pwa_header_subset($response),
        'bytes' => strlen($body),
        'sha256' => hash('sha256', $body),
        'body_prefix' => preg_replace('/\s+/', ' ', substr(wp_strip_all_tags($body), 0, 180)),
        '_body' => $body,
    );
}

function olama_pwa_extract_urls($html, $base_url)
{
    $found = array();
    $allowed_host = strtolower((string) wp_parse_url($base_url, PHP_URL_HOST));
    $patterns = array(
        '/serviceWorker\s*\.\s*register\s*\(\s*[\'\"]([^\'\"]+)/i' => 'service_worker_registration',
        '/<link[^>]+rel=[\'\"][^\'\"]*manifest[^\'\"]*[\'\"][^>]+href=[\'\"]([^\'\"]+)/i' => 'manifest',
        '/<link[^>]+href=[\'\"]([^\'\"]+)[\'\"][^>]+rel=[\'\"][^\'\"]*manifest/i' => 'manifest',
    );
    foreach ($patterns as $pattern => $type) {
        if (!preg_match_all($pattern, (string) $html, $matches)) {
            continue;
        }
        foreach ($matches[1] as $value) {
            $absolute = preg_match('#^https?://#i', $value) ? $value : home_url('/' . ltrim($value, '/'));
            if (strtolower((string) wp_parse_url($absolute, PHP_URL_HOST)) !== $allowed_host) {
                continue;
            }
            $found[$absolute] = $type;
        }
    }
    return $found;
}

function olama_pwa_scan_code($roots)
{
    $matches = array();
    $needles = array(
        'serviceWorker.register', 'navigator.serviceWorker', 'FetchEvent',
        'respondWith(', 'caches.open(', 'wp_service_worker', 'service_worker',
        'workbox', 'beforeinstallprompt',
    );
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static function ($current) {
                    if ($current->isDir()) {
                        return !in_array(strtolower($current->getFilename()), array(
                            '.git', 'node_modules', 'vendor', 'uploads', 'cache', 'backups',
                        ), true);
                    }
                    return true;
                }
            )
        );
        foreach ($iterator as $file) {
            if (count($matches) >= 250) {
                break 2;
            }
            if (!$file->isFile() || $file->getSize() > 2 * 1024 * 1024) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, array('php', 'js', 'mjs', 'html', 'htm', 'json'), true)) {
                continue;
            }
            $contents = @file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            foreach ($needles as $needle) {
                $position = stripos($contents, $needle);
                if ($position !== false) {
                    $matches[] = array(
                        'file' => str_replace(ABSPATH, '', $file->getPathname()),
                        'needle' => $needle,
                        'line' => substr_count(substr($contents, 0, $position), "\n") + 1,
                    );
                    break;
                }
            }
        }
    }
    return $matches;
}

$wp_load = olama_pwa_find_wp_load();
if (!$wp_load) {
    fwrite(STDERR, "Could not locate wp-load.php. Pass --wp-root=/absolute/path/to/wordpress\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require_once $wp_load;

global $wpdb, $wp_rewrite;
$target_url = olama_pwa_arg('url', home_url('/'));
$target_url = esc_url_raw($target_url);
if (!$target_url || wp_parse_url($target_url, PHP_URL_HOST) !== wp_parse_url(home_url('/'), PHP_URL_HOST)) {
    fwrite(STDERR, "--url must use the same host as WordPress home_url().\n");
    exit(2);
}

$home_probe = olama_pwa_probe($target_url);
$home_body = isset($home_probe['_body']) ? $home_probe['_body'] : '';
unset($home_probe['_body']);
$discovered = olama_pwa_extract_urls($home_body, $target_url);

$common_paths = array(
    '/service-worker.js', '/serviceworker.js', '/sw.js', '/wp-service-worker.js',
    '/pwa-sw.js', '/manifest.json', '/manifest.webmanifest', '/offline/',
);
foreach ($common_paths as $path) {
    $url = home_url($path);
    if (!isset($discovered[$url])) {
        $discovered[$url] = 'common_probe';
    }
}

$endpoint_probes = array();
foreach ($discovered as $url => $source) {
    $probe = olama_pwa_probe($url);
    $body = isset($probe['_body']) ? (string) $probe['_body'] : '';
    $probe['looks_like_service_worker'] = (bool) preg_match(
        '/\b(?:addEventListener\s*\(\s*[\'\"](?:fetch|install|activate)|respondWith\s*\(|workbox|skipWaiting\s*\()/i',
        $body
    );
    $decoded_manifest = json_decode($body, true);
    $probe['looks_like_manifest'] = is_array($decoded_manifest)
        && (isset($decoded_manifest['start_url']) || isset($decoded_manifest['display']) || isset($decoded_manifest['icons']));
    unset($probe['_body']);
    $probe['source'] = $source;
    $endpoint_probes[] = $probe;
}

$active_plugins = (array) get_option('active_plugins', array());
$network_plugins = is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', array())) : array();
$dropins = array();
foreach (array('advanced-cache.php', 'object-cache.php', 'db.php', 'sunrise.php') as $dropin) {
    $path = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . $dropin;
    if (is_file($path)) {
        $dropins[] = array('file' => $dropin, 'sha256' => hash_file('sha256', $path));
    }
}

$option_names = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name REGEXP '(^|_)(pwa|service_worker|workbox|offline|cache)($|_)' ORDER BY option_name LIMIT 250"
);

$rewrite_hits = array();
foreach ((array) get_option('rewrite_rules', array()) as $pattern => $destination) {
    if (preg_match('/service.?worker|(^|\\/)sw\\.js|manifest|offline|pwa/i', $pattern . ' ' . $destination)) {
        $rewrite_hits[$pattern] = $destination;
    }
}

$report = array(
    'generated_at_utc' => gmdate('c'),
    'wordpress' => array(
        'home_url' => home_url('/'),
        'site_url' => site_url('/'),
        'version' => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'https_detected' => is_ssl(),
        'wp_cache' => defined('WP_CACHE') ? (bool) WP_CACHE : false,
        'active_theme' => wp_get_theme()->get_stylesheet(),
        'theme_service_worker_support' => current_theme_supports('service_worker'),
        'active_plugins' => array_values(array_merge($active_plugins, $network_plugins)),
        'cache_dropins' => $dropins,
        'matching_option_names' => $option_names,
        'matching_rewrite_rules' => $rewrite_hits,
    ),
    'request' => array(
        'home' => $home_probe,
        'service_worker_and_manifest_probes' => $endpoint_probes,
    ),
    'code_scan' => olama_pwa_scan_code(array(WP_PLUGIN_DIR, get_theme_root())),
    'interpretation' => array(
        'browser_fact' => 'FetchEvent.respondWith means a service worker controlled the failed navigation.',
        'cache_possible' => 'Yes. A stale worker or a cached invalid response is possible, but a TLS, timeout, DNS, 5xx, redirect, or worker-code failure can produce the same Safari message.',
        'important_limit' => 'The server cannot enumerate a phone browser service-worker registration or Cache Storage. Run the companion browser diagnostic on the affected device.',
    ),
);

$json = wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$output = olama_pwa_arg('output');
if ($output) {
    if (@file_put_contents($output, $json . PHP_EOL) === false) {
        fwrite(STDERR, "Could not write report to: {$output}\n");
        exit(3);
    }
    fwrite(STDOUT, "Wrote read-only diagnostic report to {$output}\n");
} else {
    fwrite(STDOUT, $json . PHP_EOL);
}
