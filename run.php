<?php

include "config.php";

$existing = glob("*.sql");

function is_file_outdated($filename) {
    global $DAYS_FOREVER, $DAYS_KEEP;

    $parts = explode("-", $filename);

    if(in_array($parts[2], $DAYS_FOREVER)) return false;

    if(date("Y-m-d", strtotime($parts[0]."-".$parts[1]."-".$parts[2])) >= date("Y-m-d", time() - (86400 * $DAYS_KEEP))) return false;

    return true;
}

function do_backup($db, $server, $address, $dbuser, $dbpass) {
    echo "Starting backup > ".date("Y-m-d")."-$db.sql\n";
    exec("mysqldump --single-transaction -h $address -u $dbuser -p$dbpass $db > ".date("Y-m-d")."-$server-$db.sql");
}

foreach($SERVERS as $name => $server) {
    foreach($server["DATABASES"] as $db) {
        if(!isset($server["ADDRESS"])) {
            echo "Server address is not set\n"; continue;
        }
        if(!isset($server["DBUSER"])) {
            echo "Database user is not set\n"; continue;
        }
        if(!isset($server["DBPASS"])) {
            echo "Database password is not set\n"; continue;
        }
        do_backup($db, $name, $server["ADDRESS"], $server["DBUSER"], $server["DBPASS"]);
    }
}

foreach($existing as $filename) {
    if(is_file_outdated($filename)) {
        unlink($filename);
    }
}