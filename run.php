<?php

include "config.php";

$existing = glob("*.sql");

function fail_with($message) {
    echo "COULD NOT RUN SCRIPT : $message\n";
    die();
}

function is_file_outdated($filename) {
    global $DAYS_FOREVER, $DAYS_KEEP;
    if(!isset($DAYS_FOREVER)) fail_with("DAYS_FOREVER is not set in the config");
    if(!isset($DAYS_KEEP)) fail_with("DAYS_KEEP is not set in the config");

    $parts = explode("-", $filename);

    if(in_array($parts[2], $DAYS_FOREVER)) return false;

    if(date("Y-m-d", strtotime($parts[0]."-".$parts[1]."-".$parts[2])) >= date("Y-m-d", time() - (86400 * $DAYS_KEEP))) return false;

    return true;
}

function do_backup($db, $server, $address, $dbuser, $dbpass) {
    $filename = date("Y-m-d")."-$server-$db.sql";
    echo "Starting backup for $db > $filename\n";
    exec("mysqldump --single-transaction -h $address -u $dbuser -p$dbpass $db > $filename");
}

foreach($SERVERS as $name => $server) {
    foreach($server["DATABASES"] as $db) {
        if(!isset($server["ADDRESS"])) fail_with("Server address is not set");
        if(!isset($server["DBUSER"])) fail_with("Database user is not set");
        if(!isset($server["DBPASS"])) fail_with("Database password is not set");
        
        do_backup($db, $name, $server["ADDRESS"], $server["DBUSER"], $server["DBPASS"]);
    }
}

foreach($existing as $filename) {
    if(is_file_outdated($filename)) {
        unlink($filename);
    }
}