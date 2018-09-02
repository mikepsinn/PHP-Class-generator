<?php
require_once(__DIR__ . '/../vendor/autoload.php');
(new \Dotenv\Dotenv(__DIR__.'/../../../'))->load();
// Settings
ini_set('error_reporting', E_ALL ^ E_NOTICE);
ini_set('display_errors', 'on');
date_default_timezone_set('Europe/Paris');
// Constants
define('AUTHOR', 'Jean-Charles Riquet');
define('TAB', chr(9));
define('RET', ' ' . chr(10) . chr(13));
define('NL', chr(13));
define('DOCUMENT_ROOT', dirname(__FILE__) . '/../');
define('CLASSGENERATOR_DIR', DOCUMENT_ROOT . '_class/');
$url = getenv('CLEARDB_DATABASE_URL');
$urlComponents = parse_url($url);
$dbPort = isset($urlComponents['port']) ? $urlComponents['port'] : null;
$dbName = substr($urlComponents['path'], 1);
$dbPassword = isset($urlComponents['pass']) ? $urlComponents['pass'] : '';
define('dbhostname', $urlComponents['host']);
define('dbdatabase', $dbName);
define('dbusername', $urlComponents['user']);
define('dbpassword', $dbPassword);
define('dbtype', 'mysql');
include '_database.class.php';
include '_generator.class.php';