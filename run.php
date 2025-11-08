<?php

include "config.php";

if ($ERRORS) {
	error_reporting(E_ALL);
	ini_set('display_errors', 1);
}

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
	if(file_exists($filename)) {
		echo "Backup for $db already exists\n";
		return;
	}
	echo "Starting backup for $db > $filename\n";
	exec("mysqldump --single-transaction -h $address -u $dbuser -p$dbpass $db > $filename");
}

function establish_sftp($server_name) {
	global $SERVERS;

	$host = $SERVERS["$server_name"]["ADDRESS"];
	$port = isset($SERVERS["$server_name"]["PORT"]) ? $SERVERS["$server_name"]["PORT"] : 22;
	$login = $SERVERS["$server_name"]["SFTPUSER"];
	$pass = isset($SERVERS["$server_name"]["SFTPPASS"]) ? $SERVERS["$server_name"]["SFTPPASS"] : null;

	$pub = isset($SERVERS["$server_name"]["PUB"]) ? $SERVERS["$server_name"]["PUB"] : null;
	$priv = isset($SERVERS["$server_name"]["PRIV"]) ? $SERVERS["$server_name"]["PRIV"] : null;

	if ($pub || $priv) {
		if (!$pub || !$priv) {
			echo "ERROR: Both public and private key files must be specified for key-based authentication\n";
			return null;
		}

		if (!file_exists($pub)) {
			echo "ERROR: Public key file not found : $pub\n";
			return null;
		}

		if (!file_exists($priv)) {
			echo "ERROR: Private key file not found : $priv\n";
			return null;
		}
	}

	if (!extension_loaded('ssh2')) {
		echo "ERROR: SSH2 extension is not loaded.\nYou can install it with: sudo apt-get install php-ssh2\n";
		return null;
	}

	try {
		echo "Attempting to connect to $host:$port...\n";
		$connection = ssh2_connect($host, $port);

		if (!$connection) {
			echo "Could not connect to $host using ssh2_connect\n";
			echo "Possible reasons:\n";
			echo "\t- SSH server is not running on $host\n";
			echo "\t- Firewall is blocking port $port\n";
			echo "\t- Host is unreachable\n";
			echo "\n";

			$check = readline("Do you want to send a test ping to the host? (y/N) ");
			if (strtolower($check) === 'y') {
				exec("ping -c 4 $host");
			}

			return null;
		}
		
		echo "Successfully connected to $host\n";

		if ($pass && !ssh2_auth_password($connection, $login, $pass)) {
			echo "ERROR: Password authentication failed for user $login\n";
			return false;
		}

		if ($pub && $priv && !ssh2_auth_pubkey_file($connection, $login, $pub, $priv)) {
			echo "ERROR: Public key authentication failed for user $login\n";
			echo "  - Public key: $pub\n";
			echo "  - Private key: $priv\n";
			echo "  - Check that key files exist and have correct permissions\n";
			return false;
		}

		if (!$sftp = ssh2_sftp($connection)) {
			echo "ERROR: Could not initialize SFTP connection\n";
			return false;
		}
	} catch (Exception $e) {
		return null;
	}

	return [$connection,$sftp];
}

function save_dir($connection, $sftp, $dir, $dest_root) {
	$handle = opendir("ssh2.sftp://{$sftp}$dir");
	while($handle && ($file = readdir($handle)) !== false) {
		if($file == "." || $file == ".." || $file == ".git" || !$file) continue;
		$name = "$dir/$file";
		$parent = "$dest_root/$dir";
		$local = "$dest_root/$name";
		if (!file_exists($parent)) mkdir($parent, 0777, true);
		echo "Saving $name\n";
		if(!ssh2_scp_recv($connection, "$name", "$local")) {
			save_dir($connection, $sftp, $name, $dest_root);
		}
	}
	if($handle) closedir($handle);
}

function save_file($connection, $sftp, $path, $dest_root) {
	$items = explode('/',$path);
	array_pop($items);
	mkdir($dest_root."/".join('/',$items), 0777, true);
	echo "Saving $path\n";
	if(!ssh2_scp_recv($connection, "$path", "$dest_root/$path")) {
		echo "Could not download $path\n";
	}
}

function rrmdir($dir) { 
	if (is_dir($dir)) { 
		$objects = scandir($dir);
		foreach ($objects as $object) { 
			if ($object != "." && $object != "..") { 
				if (is_dir($dir. DIRECTORY_SEPARATOR .$object) && !is_link($dir."/".$object))
				rrmdir($dir. DIRECTORY_SEPARATOR .$object);
			else
				unlink($dir. DIRECTORY_SEPARATOR .$object); 
			} 
		}
		rmdir($dir); 
	} 
}

foreach($SERVERS as $name => $server) {
	foreach($server["DATABASES"] as $db) {
		if(!isset($server["ADDRESS"])) fail_with("Server address is not set");
		if(!isset($server["DBUSER"])) fail_with("Database user is not set");
		if(!isset($server["DBPASS"])) fail_with("Database password is not set");
		
		do_backup($db, $name, $server["ADDRESS"], $server["DBUSER"], $server["DBPASS"]);
	}

	$sftp_info = establish_sftp($name);
	if (!$sftp_info) {
		continue;
	}
	$dest_root = date("Y-m-d")."-$name.d";

	mkdir($dest_root);
	foreach($server["FILES"] as $file) {
		save_file($sftp_info[0], $sftp_info[1], $file, $dest_root);
	}
	foreach($server["DIRS"] as $dir) {
		save_dir($sftp_info[0], $sftp_info[1], $dir, $dest_root);
	}
}

foreach(glob("*.sql") as $filename) {
	if(is_file_outdated($filename)) {
		unlink($filename);
	}
}

foreach(glob("*.d") as $dir) {
	if(is_file_outdated($dir)) {
		rrmdir($dir);
	}
}