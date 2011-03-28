<?php
/**
 * 
 * 
 * Create a dump XML file using:
 * mysqldump --xml --no-data testuser -utestuser -ptest1 > dump.xml
 */
 
define('THIS_PATH', dirname(__FILE__));

include THIS_PATH.'/MySQLDiff.class.php';

define('DB_USER', 'testuser');
define('DB_PASS', 'test1');
define('DB_NAME', 'testuser');
define('DB_HOST', 'localhost');

$params = array(
    'dbuser' => 'testuser',
    'dbpass' => 'test1',
    'dbname' => 'testuser',
    'dbhost' => 'localhost',
    'dumpxml' => THIS_PATH.'/dump.xml',
);

try {
    $diff = new MySQLDiff($params);    
} catch(Exception $e) {
    echo $e->getMessage(); exit;
}

#$diff_lines = $diff->getDiffs();
#var_dump($diff_lines);

try {
    $diff_lines = $diff->getSQLDiffs();
 } catch(Exception $e) {
    echo $e->getMessage(); exit;
}

var_dump($diff_lines);

try { 
    $diff->runSQLDiff();
} catch(Exception $e) {
    echo $e->getMessage(); exit;
}