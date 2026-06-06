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
    // Check if a specific channel ID is requested via URL parameter (e.g., playlist.php?id=1104)
    $requestedId = isset($_GET['id']) ? $_GET['id'] : null;

    // 1. Pastefy se list fetch karein
    $ch = curl_init($pastefyUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $jsonRaw = curl_exec($ch);

    $channels = json_decode($jsonRaw, true);
    if (!$channels) {
        die("#EXTM3U\n# Error: Could not parse JSON from Pastefy");
    }

    // 2. Parallel fetch ke liye links to prepare karein
    $linksToFetch = [];
    foreach ($channels as $index => $chInfo) {
        // Agar single channel requested hai, toh baaki channels ka page web-scrape mat kijiye (Optimize performance)
        if ($requestedId !== null && $chInfo['id'] != $requestedId) {
            continue;
        }
        $linksToFetch[$index] = $chInfo['link'];
    }

    // 3. Ek saath selected channel pages fetch karein (Single handle or multi-handle based on filter)
    $pageContents = fetchAllChannels($linksToFetch);

    echo "#EXTM3U\n\n";

    // Standard Browser User-Agent definition to bypass CDN security blocks
    $userAgentString = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";

    // 4. Data Extract aur M3U Format generate karein
    foreach ($channels as $index => $chInfo) {
        
        // Skip condition agar target single channel requested ID se match nahi karta
        if ($requestedId !== null && $chInfo['id'] != $requestedId) {
            continue;
        }

        // Ensure safety if content key index exists
        if (!isset($pageContents[$index])) {
            continue;
        }

        $html = $pageContents[$index];

        // SERVER_CONFIG nikalne ke liye regex
        if (preg_match('/const SERVER_CONFIG\s*=\s*({.*?});/s', $html, $matches)) {
            $config = json_decode($matches[1], true);

            if ($config && isset($config['streamUrls'][0])) {
                $streamUrl = $config['streamUrls'][0];
                $cookie    = $config['primaryCookie'];
                $keyId     = $config['keyId'];
                $key       = $config['key'];

                echo "#EXTINF:-1 tvg-id=\"{$chInfo['id']}\" tvg-name=\"{$chInfo['name']}\" tvg-logo=\"{$chInfo['logo']}\" group-title=\"{$chInfo['group']}\",{$chInfo['name']}\n";
                echo "#KODIPROP:inputstream.adaptive.license_type=clearkey\n";
                echo "#KODIPROP:inputstream.adaptive.license_key={$keyId}:{$key}\n";
                
                // Kodi / ExoPlayer engine compatible JSON headers format
                echo "#EXTHTTP:{\"Cookie\":\"{$cookie}\",\"User-Agent\":\"{$userAgentString}\"}\n";
                
                // Stream URL line with fallback standard query pipe separation
                echo "{$streamUrl}|User-Agent={$userAgentString}\n\n";
                
                // Single execution match hone par processing end karein
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
