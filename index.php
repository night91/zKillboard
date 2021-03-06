<?php

use cvweiss\redistools\RedisSessionHandler;
use cvweiss\redistools\RedisTtlCounter;

$pageLoadMS = microtime(true);

$uri = @$_SERVER['REQUEST_URI'];
$isApiRequest = substr($uri, 0, 5) == "/api/";


/*if (substr($uri, 0, 12) == "/api/killID/") {
    header("HTTP/1.1 400 Disabling /api/killID/ because of abuse.");
    die();
}*/

if ($uri == "/kill/-1/") {
    header("Location: /keepstar1.html");
    exit();
}
// Some killboards and bots are idiots
if (strpos($uri, "_detail") !== false) {
    header('HTTP/1.1 404 This is not an EDK killboard.');
    exit();
}
// Check to ensure we have a trailing slash, helps with caching
if (substr($uri, -1) != '/' && strpos($uri, 'ccpcallback') === false) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');
    if ($isApiRequest) header("HTTP/1.1 400 Fix your code to include the trailing slash '/'");
    else header("Location: $uri/", true, 301);
    exit();
}

// http requests should already be prevented, but use this just in case
// also prevents sessions from being created without ssl
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] != 'https') {
    header("Location: https://zkillboard.com$uri");
    die();
}

// Include Init
require_once 'init.php';

if ($redis->get("zkb:memused") > 115) {
    header('HTTP/1.1 202 API temporarily disabled because of resource limitations');
    exit();
}

$timer = new Timer();

// Starting Slim Framework
$app = new \Slim\Slim($config);

$ip = IP::get();
$ipE = explode(',', $ip);
$ip = $ipE[0];

// Must rate limit now apparently
if (!in_array($ip, $whiteList)) { 
    $ipttl = new RedisTtlCounter("floodcheck:$ip", 300);
    $ipttl->add(uniqid());
    if ($ipttl->count() > 1200) {
        header('HTTP/1.1 429 Too many requests.');
        die("<html><head><meta http-equiv='refresh' content='1'></head><body>Rate limited.</body></html>");
    }
}
if (in_array($ip, $blackList)) {
    header('HTTP/1.1 403 Blacklisted');
    die();
}

$limit = $isApiRequest ? 10 : 3;
$noLimits = ['/navbar/', '/post/', '/autocomplete/', '/crestmail/', '/comment/'];
$noLimit = false;
foreach ($noLimits as $noLimit) $noLimit |= (substr($uri, 0, strlen($noLimit)) === $noLimit);
$count = $redis->get($ip);
if ($noLimit === false  && $count >= $limit) {
    header('HTTP/1.1 429 Too many requests.');
    die("<html><head><meta http-equiv='refresh' content='1'></head><body>Rate limited.</body></html>");
}

// Some anti-scraping code, far from perfect though
$badBots = ['mechanize', 'python', 'java'];
$userAgent = strtolower(@$_SERVER['HTTP_USER_AGENT']);
if (!$isApiRequest) {
    foreach ($badBots as $badBot) {
        if ($userAgent == "" || $userAgent == "-" || strpos($userAgent, $badBot) !== false) {
            header('HTTP/1.1 403 Not authorized.');
            die("APIs are useful, skill up and use that instead.");
        }
    }
} else if ($isApiRequest && strlen(trim($userAgent)) <= 3) {
    header('HTTP/1.1 403 Please provide proper user agent identification.');
    exit();
}

// Scrape Checker
$ipKey = "ip::$ip";
if (!$isApiRequest && !(substr($uri, 0, 9) == '/related/' || substr($uri, 0, 9) == "/sponsor/" || substr($uri, 0, 11) == '/crestmail/' || substr($uri, 0, 9) == '/account/' || $uri == '/logout/' || substr($uri, 0, 4) == '/ccp' || substr($uri, 0, 5) == '/auto' || substr($uri, 0, 9) == "/comment/")) {
    $redis->incr($ipKey, ($uri == '/navbar/' ? -1 : 1));
    $redis->expire($ipKey, 300);
    $count = $redis->get($ipKey);
    if ($count > 40) {
        $host = gethostbyaddr($ip);
        $host2 = gethostbyname($host);
        $isValidBot = false;
        foreach ($validBots as $bot) {
            $isValidBot |= strpos($host, $bot) !== false;
        }
        if ($ip != $host2 || !$isValidBot) {
            header('HTTP/1.1 403 Not authorized.');
            die("Scraping discouraged. APIs are useful, skill up and use that instead.");
        }
    }
}

if (substr($uri, 0, 9) == "/sponsor/" || substr($uri, 0, 11) == '/crestmail/' || $uri == '/navbar/' || substr($uri, 0, 9) == '/account/' || $uri == '/logout/' || substr($uri, 0, 4) == '/ccp') {
    // Session
    session_set_save_handler(new RedisSessionHandler(), true);
    session_cache_limiter('');
    ini_set('session.gc_maxlifetime', $cookie_time);
    session_set_cookie_params($cookie_time);
    session_start();
}

$request = $isApiRequest ? new RedisTtlCounter('ttlc:apiRequests', 300) : new RedisTtlCounter('ttlc:nonApiRequests', 300);
if ($isApiRequest || $uri == '/navbar/') $request->add(uniqid());
$uvisitors = new RedisTtlCounter('ttlc:unique_visitors', 300);
if ($uri == '/navbar/') $uvisitors->add($ip);

$visitors = new RedisTtlCounter('ttlc:visitors', 300);
$visitors->add($ip);
$requests = new RedisTtlCounter('ttlc:requests', 300);
$requests->add(uniqid());

// Theme
$theme = UserConfig::get('theme', 'cyborg');
$app->config(array('templates.path' => $baseDir.'templates/'));

// Error handling
$app->error(function (\Exception $e) use ($app) { include 'view/error.php'; });

// Load the routes - always keep at the bottom of the require list ;)
include 'routes.php';

// Load twig stuff
include 'twig.php';

include 'analyticsLoad.php';

// Run the thing!
$app->run();
