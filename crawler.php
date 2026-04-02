<?php
set_time_limit(0);

$baseUrl = 'https://kmlkclb.edgeone.dev'; // <-- your site
$saveDir = __DIR__ . '/../../public_html';

// ── Helper ────────────────────────────────────────────────────────────────────

function fetchAndSave(string $url, string $localPath): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
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

    // <script src="...">
    preg_match_all('/<script[^>]+src=["\']([^"\']+)["\']/', $html, $m);
    $assets = array_merge($assets, $m[1]);

    // <link href="...">
    preg_match_all('/<link[^>]+href=["\']([^"\']+)["\']/', $html, $m);
    $assets = array_merge($assets, $m[1]);

    // Filter to only asset paths (css/js/images/fonts/manifest)
    return array_filter($assets, fn($a) => preg_match('/\.(js|css|png|jpg|jpeg|gif|svg|webp|ico|woff2?|ttf|json)$/i', $a));
}

// ── Step 1: Fetch index.html ───────────────────────────────────────────────────

echo "<pre style='font-family:monospace;font-size:13px;'>\n";
echo "🌐 Base URL : $baseUrl\n";
echo "📁 Save dir : $saveDir\n\n";

$indexUrl = rtrim($baseUrl, '/') . '/index.html';
$indexPath = $saveDir . '/index.html';

echo "⬇  Fetching index.html...\n";
flush();

$ch = curl_init($indexUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0',
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$indexHtml = curl_exec($ch);
curl_close($ch);

if (!$indexHtml) {
    die("❌ Could not fetch index.html\n</pre>");
}

if (!is_dir($saveDir))
    mkdir($saveDir, 0755, true);
file_put_contents($indexPath, $indexHtml);
echo "✅ Saved index.html\n\n";

// ── Step 2: Parse & download all linked assets ───────────────────────────────

$assets = extractViteAssets($indexHtml);

// Always try manifest.json too
$assets[] = '/manifest.json';

echo "📦 Found " . count($assets) . " assets in index.html:\n\n";

foreach ($assets as $asset) {
    $asset = ltrim($asset, '/');
    $url = rtrim($baseUrl, '/') . '/' . $asset;
    $savePath = $saveDir . '/' . $asset;

    echo "⬇  $asset ... ";
    $ok = fetchAndSave($url, $savePath);
    echo $ok ? "✅\n" : "❌ (404 or error)\n";
    flush();
}

// ── Step 3: Scan CSS for extra assets (fonts, bg images) ─────────────────────

echo "\n🔍 Scanning CSS for extra assets (fonts, background images)...\n\n";

$cssFiles = glob($saveDir . '/assets/*.css');
foreach ($cssFiles as $cssFile) {
    $css = file_get_contents($cssFile);

    preg_match_all('/url\(["\']?([^"\')\s]+)["\']?\)/i', $css, $m);

    foreach ($m[1] as $ref) {
        if (preg_match('/^(data:|https?:)/i', $ref))
            continue; // skip data URIs & external

        // Resolve relative to /assets/
        $resolved = 'assets/' . ltrim($ref, './');
        $url = rtrim($baseUrl, '/') . '/' . $resolved;
        $savePath = $saveDir . '/' . $resolved;

        if (!file_exists($savePath)) {
            echo "⬇  $resolved ... ";
            $ok = fetchAndSave($url, $savePath);
            echo $ok ? "✅\n" : "❌\n";
            flush();
        }
    }
}

echo "\n✅ All done! Files saved to: $saveDir\n";
echo "</pre>";