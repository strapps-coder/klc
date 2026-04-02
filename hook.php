<?php
set_time_limit(0);

// ── Config ────────────────────────────────────────────────────────────────────

$secret = '48eff3dt44qfvmq1bqy1l8nagii1a89v6kgsyeqzsqethnm8mj8wnwogs6x6oj0ufz8zm8zri7zsb0d13gzux9w0jy4f420l1sod';
$baseUrl = 'https://kmlkclb.edgeone.dev';
$saveDir = __DIR__ . '/../../public_html';

// ── Cloudflare: get real IP ───────────────────────────────────────────────────
// Cloudflare forwards the real visitor IP in CF-Connecting-IP
// We use this to verify the request actually came from GitHub's servers

function getRealIp(): string
{
    return $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';
}

// GitHub's official webhook IP ranges (keep updated via api.github.com/meta)
function isGithubIp(string $ip): bool
{
    // IPv4 ranges from GitHub's hooks list
    $ipv4Ranges = [
        '192.30.252.0/22',
        '185.199.108.0/22',
        '140.82.112.0/20',
        '143.55.64.0/20',
    ];

    // IPv6 ranges from GitHub's hooks list
    $ipv6Ranges = [
        '2a0a:a440::/29',
        '2606:50c0::/32',
    ];

    // Check IPv4
    if (str_contains($ip, '.')) {
        $ipLong = ip2long($ip);
        if ($ipLong === false)
            return false;

        foreach ($ipv4Ranges as $cidr) {
            [$range, $mask] = explode('/', $cidr);
            $bits = ~((1 << (32 - (int) $mask)) - 1);
            if ((ip2long($range) & $bits) === ($ipLong & $bits))
                return true;
        }
        return false;
    }

    // Check IPv6
    if (str_contains($ip, ':')) {
        foreach ($ipv6Ranges as $cidr) {
            if (ipv6InRange($ip, $cidr))
                return true;
        }
        return false;
    }

    return false;
}

function ipv6InRange(string $ip, string $cidr): bool
{
    [$range, $prefix] = explode('/', $cidr);
    $prefix = (int) $prefix;

    $ipBin = inet_pton($ip);
    $rangeBin = inet_pton($range);

    if ($ipBin === false || $rangeBin === false)
        return false;

    $fullBytes = intdiv($prefix, 8);
    $extraBits = $prefix % 8;

    // Compare full bytes
    if (substr($ipBin, 0, $fullBytes) !== substr($rangeBin, 0, $fullBytes)) {
        return false;
    }

    // Compare remaining bits if any
    if ($extraBits > 0) {
        $mask = 0xFF & (0xFF << (8 - $extraBits));
        if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($rangeBin[$fullBytes]) & $mask)) {
            return false;
        }
    }

    return true;
}

// ── Only allow POST ───────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

// ── Cloudflare: read raw body BEFORE anything else ───────────────────────────
// Cloudflare does NOT modify the raw body, so HMAC will still match.
// But body must be read before any framework/library touches it.

$rawBody = file_get_contents('php://input');

// ── Verify GitHub HMAC signature ──────────────────────────────────────────────

$sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (empty($sigHeader)) {
    http_response_code(401);
    die('Missing signature');
}

$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

if (!hash_equals($expected, $sigHeader)) {
    http_response_code(401);
    die('Invalid signature');
}

// ── Verify GitHub IP (secondary check) ───────────────────────────────────────

$realIp = getRealIp();
if (!isGithubIp($realIp)) {
    // Log but don't hard-block — IPv6 GitHub IPs won't match ranges
    logMsg('WARNING: Request from non-GitHub IP: ' . $realIp);
}

// ── Only act on push events ───────────────────────────────────────────────────

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

if ($event !== 'push') {
    http_response_code(200);
    die('Ignored event: ' . $event);
}

// ── Log helper ────────────────────────────────────────────────────────────────

$logFile = __DIR__ . '/webhook.log';

function logMsg(string $msg): void
{
    global $logFile;
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

// ── cURL helper (Cloudflare-aware) ────────────────────────────────────────────
// When fetching YOUR site, requests go through Cloudflare.
// We send a bypass header so CF doesn't cache-block fresh asset fetches.

function fetchAndSave(string $url, string $localPath, string $cfBypassSecret = ''): bool
{
    $headers = ['Cache-Control: no-cache'];
    if ($cfBypassSecret) {
        $headers[] = 'X-CF-Bypass: ' . $cfBypassSecret; // optional CF bypass header
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,   // keep true — Cloudflare has valid certs
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status !== 200)
        return false;

    $dir = dirname($localPath);
    if (!is_dir($dir))
        mkdir($dir, 0755, true);
    file_put_contents($localPath, $body);
    return true;
}

function extractViteAssets(string $html): array
{
    $assets = [];

    preg_match_all('/<script[^>]+src=["\']([^"\']+)["\']/', $html, $m);
    $assets = array_merge($assets, $m[1]);

    preg_match_all('/<link[^>]+href=["\']([^"\']+)["\']/', $html, $m);
    $assets = array_merge($assets, $m[1]);

    return array_filter(
        $assets,
        fn($a) => preg_match('/\.(js|css|png|jpg|jpeg|gif|svg|webp|ico|woff2?|ttf|json)$/i', $a)
    );
}

// ── Mirror ────────────────────────────────────────────────────────────────────

logMsg('Push received from IP: ' . getRealIp() . '. Starting mirror...');

// Step 1: Fetch index.html
$indexUrl = rtrim($baseUrl, '/') . '/index.html';

$ch = curl_init($indexUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0',
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER => ['Cache-Control: no-cache'],
]);
$indexHtml = curl_exec($ch);
curl_close($ch);

if (!$indexHtml) {
    logMsg('ERROR: Could not fetch index.html');
    http_response_code(500);
    die('Failed');
}

if (!is_dir($saveDir))
    mkdir($saveDir, 0755, true);
file_put_contents($saveDir . '/index.html', $indexHtml);
logMsg('Saved: index.html');

// Step 2: Download all linked assets
$assets = extractViteAssets($indexHtml);
$assets[] = '/manifest.json';

foreach ($assets as $asset) {
    $asset = ltrim($asset, '/');
    $url = rtrim($baseUrl, '/') . '/' . $asset;
    $savePath = $saveDir . '/' . $asset;

    $ok = fetchAndSave($url, $savePath);
    logMsg(($ok ? 'Saved' : 'FAILED') . ': ' . $asset);
}

// Step 3: Scan CSS for fonts / background images
$cssFiles = glob($saveDir . '/assets/*.css') ?: [];
foreach ($cssFiles as $cssFile) {
    $css = file_get_contents($cssFile);
    preg_match_all('/url\(["\']?([^"\')\s]+)["\']?\)/i', $css, $m);

    foreach ($m[1] as $ref) {
        if (preg_match('/^(data:|https?:)/i', $ref))
            continue;

        $resolved = 'assets/' . ltrim($ref, './');
        $url = rtrim($baseUrl, '/') . '/' . $resolved;
        $savePath = $saveDir . '/' . $resolved;

        if (!file_exists($savePath)) {
            $ok = fetchAndSave($url, $savePath);
            logMsg(($ok ? 'Saved' : 'FAILED') . ': ' . $resolved);
        }
    }
}

logMsg('Mirror complete.');
http_response_code(200);
echo 'OK';