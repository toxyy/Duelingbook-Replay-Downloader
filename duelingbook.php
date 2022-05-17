<?php
header('Content-Encoding: none;');
// to retrieve selected html data, try these DomXPath examples:
global $player;
$player = "toxyy";
$file = "duelingbook.html";
$doc = new DOMDocument();
$doc->loadHTML(file_get_contents($file));

$xpath = new DOMXpath($doc);

// when cleaning the html, make sure duel_record is the only class name in those parent divs
$elements = $xpath->query("//div/div[@class='duel_record']");

function doFlush()
{
    if (!headers_sent()) {
        // Disable gzip in PHP.
        ini_set('zlib.output_compression', 0);

        // Force disable compression in a header.
        // Required for flush in some cases (Apache + mod_proxy, nginx, php-fpm).
        header('Content-Encoding: none');
    }

    // Fill-up 4 kB buffer (should be enough in most cases).
    echo str_pad('', 4 * 1024);

    // Flush all buffers.
    do {
        $flushed = @ob_end_flush();
    } while ($flushed);

    @ob_flush();
    flush();
}

function getURLData($url_array)
{
    global $player;
    $output = [];

    $mh = curl_multi_init(); // init the curl Multi

    $aCurlHandles = array(); // create an array for the individual curl handles

    foreach ($url_array as $url) { //add the handles for each url
        $ch = curl_init(); // init curl, and then setup your options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // returns the result - very important
        curl_setopt($ch, CURLOPT_HEADER, 0); // no headers in the output
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        $aCurlHandles[$url] = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    $active = null;
    //execute the handles
    $retry = 0;
    do {
        curl_multi_exec($mh, $running);
        while (curl_multi_errno($mh) == 28 && $retry < 2) {
            curl_multi_remove_handle($mh, $mh);
            curl_multi_add_handle($mh, $mh);
            curl_multi_exec($mh, $running);
            $retry++;
        }
        curl_multi_select($mh);
    } while ($running > 0);

    /* This is the relevant bit */
    // iterate through the handles and get your content
    foreach ($aCurlHandles as $ch) {
        $html = curl_multi_getcontent($ch); // get the content
        $json_decode = json_decode($html, TRUE);
        $count = 0;
        $rps_output = "";
        foreach ($json_decode['plays'] as $play) {
            if ($play['play'] === "RPS") {
                $p1 = $json_decode['plays'][$count]['player1'];
                $my_choice = ($p1 == $player) ? $json_decode['plays'][$count]['player1_choice'] : $json_decode['plays'][$count]['player2_choice'];
                $their_choice = ($p1 == $player) ? $json_decode['plays'][$count]['player2_choice'] : $json_decode['plays'][$count]['player1_choice'];
                $rps_output .= str_replace(' ', '', strval($my_choice)) . " " . str_replace(' ', '', strval($their_choice)) . " ";
            } else if ($count === 10) {
                break;
            }
            $count++;
        }
        // do what you want with the HTML
        $output[] = $rps_output;
        curl_multi_remove_handle($mh, $ch); // remove the handle (assuming  you are done with it);
    }
    /* End of the relevant bit */

    curl_multi_close($mh); // close the curl multi handler
    return $output;
}

if (!is_null($elements)) {
    ob_start();
    @ini_set('output_buffering', 'Off');
    @ini_set('zlib.output_compression', 0);
    @ini_set('implicit_flush', 1);
    @ob_end_clean();
    set_time_limit(0);
    doFlush();
    $output_array = [];
    $url_array = [];
    $row_count = 0;
    foreach ($elements as $element) {
        $count = 0;
        $this_output = "";

        $nodes = $element->childNodes;
        foreach ($nodes as $node) {
            // leaving this here because for some reason they changed when updating the html
            //echo " # " . $count . $node->nodeValue . " $ ";
            // 3 name, 4 their rating, 6 winlose, 7 change, 8 newrating, 9 time, 10 replay
            if (in_array($count++, [3, 4, 6, 7, 8, 10])) {
                if ($count !== 11) {
                    $this_output .= str_replace(' ', '', strval($node->nodeValue)) . " ";
                } else {
                    foreach ($node->getElementsByTagName('a') as $child) {
                        $url_array[] = str_replace("replay", "view-replay", $child->getAttribute("href"));
                    }
                    break;
                }
            }
        }
        $output_array[] = $this_output;
        $output_limit = 1;
        if (sizeof($output_array) == $output_limit) {
            $url_data = getURLData($url_array);
            for ($x = 0; $x < $output_limit; $x++) {
                echo $output_array[$x] . $url_data[$x] . "</br>";
            }
            doFlush();
            $output_array = $url_array = [];
        }
        sleep(3);
    }
    doFlush();
}
