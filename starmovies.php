<?php

// Headers for direct stream response
header('Content-Type: text/plain; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Main JSON Source containing your channel map coordinates
$pastefyUrl = "https://pastefy.app/ZH3tseJk/raw";
$targetId = "1104"; // Hardcoded for Star Movies HD

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
    // 1. Fetch channel index list from Pastefy
    $ch = curl_init($pastefyUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $jsonRaw = curl_exec($ch);
    curl_close($ch);

    $channels = json_decode($jsonRaw, true);
    if (!$channels) {
        die("# Error: Could not parse database payload");
    }

    // 2. Find the entry for Star Movies HD
    $targetChannel = null;
    foreach ($channels as $chInfo) {
        if ($chInfo['id'] == $targetId) {
            $targetChannel = $chInfo;
            break;
        }
    }

    if (!$targetChannel) {
        die("# Error: Channel ID {$targetId} not found in source list");
    }

    // 3. Scrape the built-in web player configurations
    $html = fetchTargetChannel($targetChannel['link']);
    $userAgentString = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";

    if (preg_match('/const SERVER_CONFIG\s*=\s*({.*?});/s', $html, $matches)) {
        $config = json_decode($matches[1], true);

        if ($config && isset($config['streamUrls'][0])) {
            $streamUrl = $config['streamUrls'][0];
            $cookie    = $config['primaryCookie'];
            $keyId     = $config['keyId'];
            $key       = $config['key'];

            $ext = isset($_GET['ext']) ? $_GET['ext'] : 'm3u';

            // CASE A: RAW STREAM ONLY (?ext=m3u8)
            // Strips all outer structures and returns only the working pipeline parameters
            if ($ext === 'm3u8') {
                echo "#KODIPROP:inputstream.adaptive.license_type=clearkey\n";
                echo "#KODIPROP:inputstream.adaptive.license_key={$keyId}:{$key}\n";
                echo "#EXTHTTP:{\"Cookie\":\"{$cookie}\",\"User-Agent\":\"{$userAgentString}\"}\n";
                echo "{$streamUrl}|User-Agent={$userAgentString}\n";
                exit;
            }

            // CASE B: NESTED STRUCTURE LOOK-ALIKE (Default)
            // Mimics a single-channel sub-playlist configuration block cleanly
            echo "#EXTM3U\n";
            echo "#EXT-X-VERSION:3\n";
            echo "#EXTINF:-1 tvg-id=\"{$targetChannel['id']}\" tvg-name=\"{$targetChannel['name']}\",{$targetChannel['name']}\n";
            echo "#KODIPROP:inputstream.adaptive.license_type=clearkey\n";
            echo "#KODIPROP:inputstream.adaptive.license_key={$keyId}:{$key}\n";
            echo "#EXTHTTP:{\"Cookie\":\"{$cookie}\",\"User-Agent\":\"{$userAgentString}\"}\n";
            echo "{$streamUrl}|User-Agent={$userAgentString}\n";
        } else {
            echo "# Error: Configuration parameters are empty or expired";
        }
    } else {
        echo "# Error: Regex parsing failed to discover SERVER_CONFIG";
    }

} catch (Exception $e) {
    echo "# Error: " . $e->getMessage();
}
?>
