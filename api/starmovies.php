<?php

// Default header initialization
header('Access-Control-Allow-Origin: *');

// Main JSON Source containing your channel map coordinates
$pastefyUrl = "https://pastefy.app/ZH3tseJk/raw";
$targetId = "1104"; // Hardcoded strictly for Star Movies HD

function fetchTargetChannel($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => '@cloudplay',
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

try {
    // 1. Fetch channel index list from Pastefy source
    $ch = curl_init($pastefyUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $jsonRaw = curl_exec($ch);
    curl_close($ch);

    $channels = json_decode($jsonRaw, true);
    if (!$channels) {
        header('Content-Type: text/plain; charset=utf-8');
        die("# Error: Could not parse database payload");
    }

    // 2. Locate the specific entry for Star Movies HD
    $targetChannel = null;
    foreach ($channels as $chInfo) {
        if ($chInfo['id'] == $targetId) {
            $targetChannel = $chInfo;
            break;
        }
    }

    if (!$targetChannel) {
        header('Content-Type: text/plain; charset=utf-8');
        die("# Error: Channel ID {$targetId} not found in source list");
    }

    // 3. Scrape the built-in web player configuration parameters
    $html = fetchTargetChannel($targetChannel['link']);
    $userAgentString = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";

    if (preg_match('/const SERVER_CONFIG\s*=\s*({.*?});/s', $html, $matches)) {
        $config = json_decode($matches[1], true);

        if ($config && isset($config['streamUrls'][0])) {
            $streamUrl = $config['streamUrls'][0];
            $cookie    = $config['primaryCookie'];
            $keyId     = $config['keyId'];
            $key       = $config['key'];

            // Read the extension format parameter
            $ext = isset($_GET['ext']) ? $_GET['ext'] : 'm3u';

            // =======================================================================
            // CASE A: RAW PIPELINE MODE (Used inside your manual 'perfect movies.txt')
            // =======================================================================
            if ($ext === 'm3u8') {
                // FIX: Force streaming application content header to satisfy the ExoPlayer extractor layout
                header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
                
                // Strips all outer structures so the player handles it as a direct stream line
                echo "#KODIPROP:inputstream.adaptive.license_type=clearkey\n";
                echo "#KODIPROP:inputstream.adaptive.license_key={$keyId}:{$key}\n";
                echo "#EXTHTTP:{\"Cookie\":\"{$cookie}\",\"User-Agent\":\"{$userAgentString}\"}\n";
                echo "{$streamUrl}|User-Agent={$userAgentString}\n";
                exit;
            }

            // =======================================================================
            // CASE B: STANDALONE PLAYLIST MODE (Default standalone playback fallback)
            // =======================================================================
            header('Content-Type: text/plain; charset=utf-8');
            echo "#EXTM3U\n";
            echo "#EXT-X-VERSION:3\n";
            echo "#EXTINF:-1 tvg-id=\"{$targetChannel['id']}\" tvg-name=\"{$targetChannel['name']}\" tvg-logo=\"{$targetChannel['logo']}\" group-title=\"{$targetChannel['group']}\",{$targetChannel['name']}\n";
            echo "#KODIPROP:inputstream.adaptive.license_type=clearkey\n";
            echo "#KODIPROP:inputstream.adaptive.license_key={$keyId}:{$key}\n";
            echo "#EXTHTTP:{\"Cookie\":\"{$cookie}\",\"User-Agent\":\"{$userAgentString}\"}\n";
            echo "{$streamUrl}|User-Agent={$userAgentString}\n";

        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "# Error: Configuration parameters are empty or expired";
        }
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "# Error: Regex parsing failed to discover SERVER_CONFIG";
    }

} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "# Error: " . $e->getMessage();
}
?>
