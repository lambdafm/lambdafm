<?php
    /* Credentials file template:
     * 
     * <?php
     *     $login = 'VasyaPupkin';
     *     $password = 'MashaPupkinaOneLove!';
     * 
     */
    require_once('authcredentials.php');
    
    /*
     * Config
     */ 
    define('BASIC_URL', 'https://point.im/api');
    define('HEADER_AUTH', 'Authorization');
    define('HEADER_CSRF', 'X-CSRF');
    define('LATEST_TIMESTAMP_FILE_SUFFIX', '.latest_timestamp');
    define('TAG_SUFFIX', '@lambdafm');          // You may wish to customize this
            
    /*
     * Routines
     */
     
    /**
     * Performs basic init or cURL session.
     */ 
    function basicInit($ch) {
        curl_reset($ch);
        
        curl_setopt_array($ch, array(
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) LambdaFM aggregation robot',
        ));
    }
    
    /**
     * Returns Point.IM tokens
     */ 
    function auth($ch, $login, $password) {
        basicInit($ch);
        
        $loginField = 'login';
        $passwordField = 'password';
        $urlSuffix = '/login';
        
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => array(
                $loginField => $login,
                $passwordField => $password,
            ),
            CURLOPT_URL => BASIC_URL . $urlSuffix,
        ));
        
        $jsonResponse = curl_exec($ch);
        $response = json_decode($jsonResponse, true);
        
        return $response;
    }
    
    /**
     * Extracts AUTH token out of auth() return
     */
    function getAuthToken($tokens) {
        $field = 'token';
        
        return $tokens[$field];
    }
    
    /**
     * Extracts CSRF token out of auth() return
     */ 
    function getCsrfToken($tokens) {
        $field = 'csrf_token';
        
        return $tokens[$field];
    }
    
    /**
     * Builds HTTP header
     */
    function buildHeader($key, $value) {
        return $key . ': ' . $value;
    }
    
    /**
     * Builds POST data for post sending
     * out of text and tags' array
     */
    function buildTextTagsPostQuery($text, $tags) {
        $textField = 'text';
        $tagsField = 'tag';

        $buffer = '';
        
        if ($text) {
            $buffer .= $textField . '=' . trim(urlencode($text)) . '&';
        }
        
        foreach ($tags as $tag) {
            $buffer .= $tagsField . '=' . trim(urlencode($tag)) . '&';
        }
        
        $buffer = rtrim($buffer, '&');
        
        print_r($buffer);
        
        return $buffer;
    }
    
    /**
     * Posts to Point.IM
     */
    function post($ch, $token, $csrf_token, $text, $tags) {
        basicInit($ch);
        
        $urlSuffix = '/post';
        
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => buildTextTagsPostQuery(
                $text,
                $tags
            ),
            CURLOPT_HTTPHEADER => array(
                buildHeader(HEADER_AUTH, $token),
                buildHeader(HEADER_CSRF, $csrf_token),
            ),
            CURLOPT_URL => BASIC_URL . $urlSuffix,
        ));
        
        return curl_exec($ch);
    }
    
    /**
     * Writes the timestamp of the latest processed entry
     * into the persistent storage with given ID
     */
    function setLatestProcessedTimestamp($id, $timestamp) {
        $fileName = $id . LATEST_TIMESTAMP_FILE_SUFFIX;

        file_put_contents($fileName, $timestamp);
    }
    
    /**
     * Accepts array of RSS sources with the following format 
     * of single entry:
     * 
     * array(
     *      'rssUrl' => <URL of RSS feed>,
     *      'sourceId' => <Unique and human-friendly ID of source, e.g. domain name>,
     *      'antibanSearch' => <Search regexp>,
     *      'antibanReplace' => <Replacement regexp>
     * )
     * 
     * 'antiban*' fields define rules of transformating the direct link
     * into the web-proxied one to break through censorship.
     * 
     * ====================================
     * 
     * Returns chronologically-sorted array of data ready for posting
     * with the following format of single entry:
     * 
     * array(
     *     'readyPost' => <Post body>,
     *     'readyTags' => <Array of post tags>,
     * )
     * 
     */
    function queryAndConsolidate($ch, $data) {
        $consolidatedData = array();    
        for ($x = 0 ; $x < count($data) ; $x++) {
            $entries = getRss($ch, $data[$x]['rssUrl']);
            
            if (!$entries) {
                continue;
            }
            
            $sourceId = $data[$x]['sourceId'];
            $antibanSearch = $data[$x]['antibanSearch'];
            $antibanReplace = $data[$x]['antibanReplace'];
                
            for ($i = count($entries) - 1; $i > -1 ; $i--) {
                $entry = $entries[$i];
                $latestTimestamp = getLatestProcessedTimestamp($sourceId);
                $currentTimestamp = strtotime($entry->pubDate);
                
                if ($currentTimestamp === false || $currentTimestamp <= $latestTimestamp) {
                    continue;
                }
                
                setLatestProcessedTimestamp($sourceId, $currentTimestamp);
                
                $readyText = $entry->description;
                $readyText = strip_tags($readyText);
                $readyText = trim($readyText);
                
                $readyTags = array($sourceId . TAG_SUFFIX);
                
                $tags = 0;
                $maxTags = 5;   
                foreach ($entry->category as $tag) {
                    if ($tags >= $maxTags) {
                        break;
                    }
                    
                    $readyTag = $tag . TAG_SUFFIX;
                    $readyTags[] = $readyTag;
                    
                    $tags++;
                }
                
                $link = $entry->link;
                $safeLink = preg_replace($antibanSearch, $antibanReplace, $link);
                
                $readyPost = $readyText . "\r\n\r\n"
                        . "Прямая ссылка: " . $link . "\r\n"
                        . "Банобойная ссылка: " . $safeLink . "\r\n";
                
                $consolidatedData[$currentTimestamp . '_' . $sourceId] = array(
                    'readyPost' => $readyPost,
                    'readyTags' => $readyTags,
                );
            }
        }
        
        ksort($consolidatedData, SORT_STRING);
        
        return $consolidatedData;
    }
    
    /**
     * Reads the timestamp of the latest processed entry
     * from the persistent storage with given ID.
     * 
     * If the storage for given ID not initialized yet
     * it will be init'd and zero returned.
     */
    function getLatestProcessedTimestamp($id) {
        $fileName = $id . LATEST_TIMESTAMP_FILE_SUFFIX;
        
        if (file_exists($fileName)) {
            return file_get_contents($fileName);
        } else {
            setLatestProcessedTimestamp($id, '0');
            return '0';
        }
    }
    
    /**
     * Returns a SimpleXML object containing entries of given RSS
     */
    function getRss($ch, $rssUrl) {
        
        echo "Retrieving RSS for: " . $rssUrl . "\r\n";
        
        basicInit($ch);
        
        curl_setopt_array($ch, array(
            CURLOPT_PROTOCOLS => CURLPROTO_ALL,
            CURLOPT_URL => $rssUrl,
            CURLOPT_HTTPGET => true,
        ));
        
        $xml = curl_exec($ch);
        $simpleXml = simplexml_load_string($xml);
                
        return $simpleXml->channel->item;
    }
    
    /*
     * main()
     */

    // Init cURL
    $ch = curl_init();  
        
    // List of data sources to be queried
    // antiban* fields are for building a ban-proof link
    // via cameleo.ru web proxy
    $data = array(
        array(
            'rssUrl' => 'https://meduza.io/rss/news',
            'sourceId' => 'meduza.io',
            'antibanSearch' => '|^https?://meduza.io|i',
            'antibanReplace' => 'http://0s.nvswi5l2mexgs3y.cmle.ru'
        ),
        array(
            'rssUrl' => 'http://grani.ru/export/articles-rss2.xml',
            'sourceId' => 'grani.ru',
            'antibanSearch' => '|^https?://grani.ru|i',
            'antibanReplace' => 'http://m5zgc3tjfzzhk.cmle.ru',
        ),
    );
        
    $consolidatedData = queryAndConsolidate($ch, $data);
    
    // Dry run. Uncomment to auth against Point.IM for real.
/*
    $tokens = auth($ch, $login, $password);
    $authToken = getAuthToken($tokens);
    $csrfToken = getCsrfToken($tokens);
*/  

    foreach ($consolidatedData as $entry) {
        // Log
        echo "Posting:\r\n" .
             "----------------------------------------\r\n";
        foreach ($entry['readyTags'] as $tag) {
            echo '*' . $tag . ' ';
        }
        echo "\r\n" .   
             $entry['readyPost'] . "\r\n" .
             "----------------------------------------\r\n";

        // Dry run. Uncomment to post to Point.IM for real.
    /* 
        $serverResponse = post($ch, $authToken, $csrfToken, $entry['readyPost'], $entry['readyTags']);  
        echo "Server said: " . $serverResponse . "\r\n";
        sleep(30);
    */
    }
        
    // Close cURL
    curl_close($ch);
?>
