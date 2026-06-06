<?php

// Headers for M3U output
header('Content-Type: text/plain; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Main JSON Source
$pastefyUrl = "https://pastefy.app/ZH3tseJk/raw";

function fetchAllChannels($urls) {
    $multiHandle = curl_multi_init();
    $handles = [];

    foreach ($urls as $id => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => '@cloudplay',
        ]);
        $handles[$id] = $ch;
        curl_multi_add_handle($multiHandle, $ch);
    }

    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running > 0);

    $results = [];
    foreach ($handles as $id => $ch) {
        $results[$id] = curl_multi_getcontent($ch);
        curl_multi_remove_handle($multiHandle, $ch);
    }
    curl_multi_close($multiHandle);
    return $results;
}

try {
    // 1. Fetch channel list from URL parameter
    $requestedId = isset($_GET['id']) ? $_GET['id'] : null;
    
    // Naming change: Check if 'ext' is set to 'm3u8' for raw mode
    $ext = isset($_GET['ext']) ? $_GET['ext'] : 'm3u';
    $isRawMode = ($ext === 'm3u8');

    // 2. Pastefy se list fetch karein
    $ch = curl_init($pastefyUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $jsonRaw = curl_exec($ch);

    $channels = json_decode($jsonRaw, true);
    if (!$channels) {
        die($isRawMode ? "# Error: Could not parse JSON" : "#EXTM3U\n# Error: Could not parse JSON");
    }

    // 3. Parallel fetch ke liye links filter karein
    $linksToFetch = [];
    foreach ($channels as $index => $chInfo) {
        if ($requestedId !== null && $chInfo['id'] != $requestedId) {
            continue;
        }
        $linksToFetch[$index] = $chInfo['link'];
    }

    $pageContents = fetchAllChannels($linksToFetch);

    // Only output playlist header if NOT in raw .m3u8 mode
    if (!$isRawMode) {
        echo "#EXTM3U\n\n";
    }

    $userAgentString = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";

    // 4. Data Extract aur M3U Format generate karein
    foreach ($channels as $index => $chInfo) {
        if ($requestedId !== null && $chInfo['id'] != $requestedId) {
            continue;
        }
        if (!isset($pageContents[$index])) {
            continue;
        }

        $html = $pageContents[$index];

        if (preg_match('/const SERVER_CONFIG\s*=\s*({.*?});/s', $html, $matches)) {
            $config = json_decode($matches[1], true);

            if ($config && isset($config['streamUrls'][0])) {
                $streamUrl = $config['streamUrls'][0];
                $cookie    = $config['primaryCookie'];
                $keyId     = $config['keyId'];
                $key       = $config['key'];

                // Only show channel info row if NOT in raw mode
                if (!$isRawMode) {
                    echo "#EXTINF:-1 tvg-id=\"{$chInfo['id']}\" tvg-name=\"{$chInfo['name']}\" tvg-logo=\"{$chInfo['logo']}\" group-title=\"{$chInfo['group']}\",{$chInfo['name']}\n";
                }
                
                echo "#KODIPROP:inputstream.adaptive.license_type=clearkey\n";
                echo "#KODIPROP:inputstream.adaptive.license_key={$keyId}:{$key}\n";
                echo "#EXTHTTP:{\"Cookie\":\"{$cookie}\",\"User-Agent\":\"{$userAgentString}\"}\n";
                echo "{$streamUrl}|User-Agent={$userAgentString}\n\n";
                
                if ($requestedId !== null) {
                    break;
                }
            }
        }
    }

} catch (Exception $e) {
    echo "# Error: " . $e->getMessage();
}

?>
