<?php

$DAYS_KEEP = 7;
$DAYS_FOREVER = [
    "01",
    "10",
    "20"
];

$existing = glob("*.sql");

function is_file_outdated($filename) {
    global $DAYS_FOREVER, $DAYS_KEEP;

    $parts = explode("-", $filename);

    if(in_array($parts[2], $DAYS_FOREVER)) return false;

    if(date("Y-m-d", strtotime($parts[0]."-".$parts[1]."-".$parts[2])) >= date("Y-m-d", time() - (86400 * $DAYS_KEEP))) return false;

    return true;
}

foreach($existing as $filename) {
    if(is_file_outdated($filename)) {
        unlink($filename);
    }
}