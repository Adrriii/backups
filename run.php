<?php

include "config.php";

if ($ERRORS) {
	error_reporting(E_ALL);
	ini_set('display_errors', 1);
}

/**
 * SSHConnection - Encapsulates SSH/SCP operations for remote backups
 */
class SSHConnection {
	private $host;
	private $port;
	private $user;
	private $keyPath;
	private $password;
	
	public function __construct($host, $port, $user, $keyPath = null, $password = null) {
		$this->host = $host;
		$this->port = $port;
		$this->user = $user;
		$this->keyPath = $keyPath;
		$this->password = $password;
	}
	
	/**
	 * Build SSH options string for commands
	 */
	private function buildSSHOptions($includePort = false) {
		$opts = "-o BatchMode=yes -o StrictHostKeyChecking=no";
		
		if ($this->keyPath) {
			$opts .= " -i " . escapeshellarg($this->keyPath);
		}
		
		if ($includePort) {
			$opts .= " -p {$this->port}";
		}
		
		return $opts;
	}
	
	/**
	 * Build SCP options string for file transfers
	 */
	private function buildSCPOptions() {
		$opts = "-o BatchMode=yes -o StrictHostKeyChecking=no";
		
		if ($this->keyPath) {
			$opts .= " -i " . escapeshellarg($this->keyPath);
		}
		
		return $opts;
	}
	
	/**
	 * Get remote path specification for SCP
	 */
	private function getRemotePath($path) {
		return escapeshellarg("{$this->user}@{$this->host}:$path");
	}
	
	/**
	 * Test SSH connectivity
	 */
	public function testConnection() {		
		$ssh_opts = $this->buildSSHOptions(true);
		$remote = escapeshellarg("{$this->user}@{$this->host}");
		$cmd = "ssh $ssh_opts $remote 'echo connected' 2>&1";
		
		exec($cmd, $output, $return_code);
		
		if ($return_code !== 0) {
			echo "ERROR: Could not establish SSH connection\n";
			echo "Command: $cmd\n";
			echo "Output: " . implode("\n", $output) . "\n";
			return false;
		}
		
		return true;
	}
	
	/**
	 * Execute command on remote server
	 */
	public function executeCommand($command, $outputFile = null) {
		$ssh_opts = $this->buildSSHOptions(true);
		$remote = "{$this->user}@{$this->host}";
		$remote_cmd = escapeshellarg($command);
		
		$cmd = "ssh $ssh_opts $remote $remote_cmd";
		if ($outputFile) {
			$cmd .= " > " . escapeshellarg($outputFile);
		}
		$cmd .= " 2>&1";
		
		exec($cmd, $output, $return_code);
		
		return [
			'success' => $return_code === 0,
			'output' => $output,
			'return_code' => $return_code
		];
	}
	
	/**
	 * Download a single file from remote server
	 */
	public function downloadFile($remotePath, $localPath, $silent = false) {
		$localDir = dirname($localPath);
		if (!is_dir($localDir)) {
			mkdir($localDir, 0777, true);
		}
		
		$ssh_opts = "-e 'ssh -p {$this->port} -o BatchMode=yes -o StrictHostKeyChecking=no";
		if ($this->keyPath) {
			$ssh_opts .= " -i " . escapeshellarg($this->keyPath);
		}
		$ssh_opts .= "'";
		
		$remote = "{$this->user}@{$this->host}:$remotePath";
		$local = escapeshellarg($localPath);
		
		$cmd = "rsync -az $ssh_opts $remote $local 2>&1";
		exec($cmd, $output, $return_code);
		
		if ($return_code !== 0) {
			if (!$silent) {
				echo "\nCould not download $remotePath\n";
				echo "Error: " . implode("\n", $output) . "\n";
			}
			return false;
		}
		
		return true;
	}
	
	/**
	 * Download a directory from remote server
	 */
	public function downloadDirectory($remoteDir, $localDestRoot, $silent = false) {
		$dirname = basename($remoteDir);
		
		// Preserve full path structure
		$localPath = $localDestRoot . $remoteDir;
		$localDir = dirname($localPath);
		
		if (!is_dir($localDir)) {
			mkdir($localDir, 0777, true);
		}
		
		$ssh_opts = "-e 'ssh -p {$this->port} -o BatchMode=yes -o StrictHostKeyChecking=no";
		if ($this->keyPath) {
			$ssh_opts .= " -i " . escapeshellarg($this->keyPath);
		}
		$ssh_opts .= "'";
		
		$remote = "{$this->user}@{$this->host}:$remoteDir";
		$local = escapeshellarg($localPath);
		
		$cmd = "rsync -az $ssh_opts $remote $local 2>&1";
		exec($cmd, $output, $return_code);
		
		if ($return_code !== 0) {
			if (!$silent) {
				echo "\nCould not download directory $remoteDir\n";
				echo "Error: " . implode("\n", $output) . "\n";
			}
			return false;
		}
		
		return true;
	}
}

/**
 * Display a progress bar
 */
function show_progress($current, $total, $label = '') {
	static $lastLineLength = 0;
	
	$barWidth = 50;
	$percentage = ($total > 0) ? ($current / $total) : 0;
	$filledWidth = (int)($barWidth * $percentage);
	
	$bar = str_repeat('=', $filledWidth) . str_repeat('-', $barWidth - $filledWidth);
	$percentDisplay = sprintf("%3d%%", $percentage * 100);
	
	// Show checkmark when complete
	if ($current >= $total) {
		$line = "[$bar] $percentDisplay $current/$total ✓ Complete";
	} else {
		$line = "[$bar] $percentDisplay $current/$total $label";
	}
	
	$lineLength = strlen($line);
	
	// Pad with spaces to clear previous longer line
	if ($lineLength < $lastLineLength) {
		$line .= str_repeat(' ', $lastLineLength - $lineLength);
	}
	
	echo "\r$line";
	$lastLineLength = $lineLength;
	
	if ($current >= $total) {
		echo "\n";
		$lastLineLength = 0;
	}
	
	flush();
}

/**
 * Terminate script with error message
 */
function fail_with($message) {
	echo "COULD NOT RUN SCRIPT : $message\n";
	die();
}

/**
 * Check if backup file/directory is outdated and should be deleted
 */
function is_file_outdated($filename) {
	global $DAYS_FOREVER, $DAYS_KEEP;
	
	if (!isset($DAYS_FOREVER)) {
		fail_with("DAYS_FOREVER is not set in the config");
	}
	if (!isset($DAYS_KEEP)) {
		fail_with("DAYS_KEEP is not set in the config");
	}

	$parts = explode("-", $filename);
	
	// Keep if day is in DAYS_FOREVER list
	if (in_array($parts[2], $DAYS_FOREVER)) {
		return false;
	}

	// Keep if within DAYS_KEEP threshold
	$fileDate = date("Y-m-d", strtotime($parts[0] . "-" . $parts[1] . "-" . $parts[2]));
	$cutoffDate = date("Y-m-d", time() - (86400 * $DAYS_KEEP));
	
	if ($fileDate >= $cutoffDate) {
		return false;
	}

	return true;
}

/**
 * Backup a database using mysqldump
 */
function do_backup($db, $server, $address, $dbuser, $dbpass, $current, $total) {
	$filename = date("Y-m-d") . "-$server-$db.sql";
	
	if (file_exists($filename)) {
		show_progress($current+1, $total, "$db (skipped - exists)");
		return true;
	}
	
	show_progress($current, $total, "$db (backing up...)");
	
	$cmd = sprintf(
		"mysqldump --single-transaction -h %s -u %s -p%s %s > %s",
		escapeshellarg($address),
		escapeshellarg($dbuser),
		escapeshellarg($dbpass),
		escapeshellarg($db),
		escapeshellarg($filename)
	);
	
	exec($cmd, $output, $return_code);
	
	if ($return_code !== 0) {
		echo "\nWarning: Database backup for $db returned code $return_code\n";
		return false;
	}
	show_progress($current+1, $total, "$db (ok)");
	
	return true;
}

/**
 * Create SSH connection from server configuration
 */
function create_ssh_connection($serverName, $serverConfig) {
	if (!isset($serverConfig["ADDRESS"])) {
		echo "ERROR: Server address is not set for $serverName\n";
		return null;
	}
	
	if (!isset($serverConfig["SFTPUSER"])) {
		echo "ERROR: SFTP user is not set for $serverName\n";
		return null;
	}
	
	$host = $serverConfig["ADDRESS"];
	$port = isset($serverConfig["PORT"]) ? $serverConfig["PORT"] : 22;
	$user = $serverConfig["SFTPUSER"];
	$password = isset($serverConfig["SFTPPASS"]) ? $serverConfig["SFTPPASS"] : null;
	$keyPath = isset($serverConfig["PRIV"]) ? $serverConfig["PRIV"] : null;
	
	// Validate key-based authentication
	if (isset($serverConfig["PUB"]) || isset($serverConfig["PRIV"])) {
		if (!isset($serverConfig["PUB"]) || !isset($serverConfig["PRIV"])) {
			echo "ERROR: Both public and private key files must be specified for $serverName\n";
			return null;
		}
		
		if (!file_exists($keyPath)) {
			echo "ERROR: Private key file not found: $keyPath\n";
			return null;
		}
	}
	
	$connection = new SSHConnection($host, $port, $user, $keyPath, $password);
	
	if (!$connection->testConnection()) {
		return null;
	}
	
	return $connection;
}

/**
 * Backup files from remote server
 */
function backup_files(SSHConnection $ssh, array $files, $destDir) {
	$total = count($files);
	$current = 0;
	
	foreach ($files as $file) {
		$basename = basename($file);
		show_progress($current, $total, $basename);
		$current++;
		
		$localPath = "$destDir$file";
		$ssh->downloadFile($file, $localPath, true);
		show_progress($current, $total, $basename);
	}
}

/**
 * Backup directories from remote server
 */
function backup_directories(SSHConnection $ssh, array $dirs, $destDir) {
	$total = count($dirs);
	$current = 0;
	
	foreach ($dirs as $dir) {
		$dirname = basename($dir);
		show_progress($current, $total, $dirname);
		$current++;
		
		$ssh->downloadDirectory($dir, $destDir, true);
		show_progress($current, $total, $dirname);
	}
}

/**
 * Backup Docker containers from remote server
 */
function backup_containers(SSHConnection $ssh, array $containers, $serverName) {
    $datePrefix = date("Y-m-d");
    $total = count($containers);
    $current = 0;

    foreach ($containers as $container) {
        show_progress($current, $total, $container . ' : committing...');

        // Commit the running container to an image
        $imageName = "{$container}_backup:latest";
        $commitResult = $ssh->executeCommand("docker commit $container $imageName");
        if (!$commitResult['success']) {
            echo "\nCould not commit container $container\n";
            echo "Error: " . implode("\n", $commitResult['output']) . "\n";
            continue;
        }

        show_progress($current, $total, $container . ' : saving...');
        // Save the committed image to a tarball on remote server
        $remoteFile = "/tmp/$datePrefix-$serverName-$container.docker.tar";
        $saveResult = $ssh->executeCommand("docker save -o $remoteFile $imageName");

        if (!$saveResult['success']) {
            echo "\nCould not save container $container to tar\n";
            echo "Error: " . implode("\n", $saveResult['output']) . "\n";
            continue;
        }

        show_progress($current, $total, $container . ' : downloading...');
        // Download the tar file
        $localFile = "$datePrefix-$serverName-$container.docker.tar";
        $downloadSuccess = $ssh->downloadFile($remoteFile, $localFile, true);

        show_progress($current, $total, $container . ' : cleaning up...');
        // Clean up remote file and temporary image
        $ssh->executeCommand("rm -f $remoteFile");
        $ssh->executeCommand("docker rmi -f $imageName");

        if (!$downloadSuccess) {
            echo "\nCould not download container backup for $container\n";
        }

        show_progress($current, $total, $container);
        $current++;
    }
}

// Main execution
echo "=== Starting Backup Process ===\n\n";

foreach ($SERVERS as $name => $server) {
	echo "Processing server: $name\n";
	echo str_repeat("-", 50) . "\n";
	
	// Backup databases
	if (!empty($server["DATABASES"])) {
		echo "Databases:\n";
		
		if (!isset($server["ADDRESS"])) {
			fail_with("Server address is not set for $name");
		}
		if (!isset($server["DBUSER"])) {
			fail_with("Database user is not set for $name");
		}
		if (!isset($server["DBPASS"])) {
			fail_with("Database password is not set for $name");
		}
		
		$total = count($server["DATABASES"]);
		$current = 0;
		
		foreach ($server["DATABASES"] as $db) {
			do_backup($db, $name, $server["ADDRESS"], $server["DBUSER"], $server["DBPASS"], $current, $total);
			$current++;
		}
	}
	
	// Setup SSH connection for file/directory/container backups
	$needsSSH = !empty($server["FILES"]) || !empty($server["DIRS"]) || !empty($server["CONTAINERS"]);
	
	if (!$needsSSH) {
		echo "No SSH backups needed for $name\n\n";
		continue;
	}
	
	$ssh = create_ssh_connection($name, $server);
	if (!$ssh) {
		echo "Skipping SSH backups for $name due to connection failure\n\n";
		continue;
	}
	
	// Prepare destination directory
	$datePrefix = date("Y-m-d");
	$destDir = "$datePrefix-$name.d";
	
	if (!is_dir($destDir)) {
		mkdir($destDir);
	}
	
	// Backup files
	if (!empty($server["FILES"])) {
		echo "Files:\n";
		backup_files($ssh, $server["FILES"], $destDir);
	}
	
	// Backup directories
	if (!empty($server["DIRS"])) {
		echo "Directories:\n";
		backup_directories($ssh, $server["DIRS"], $destDir);
	}
	
	// Backup containers
	if (!empty($server["CONTAINERS"])) {
		echo "Containers:\n";
		backup_containers($ssh, $server["CONTAINERS"], $name);
	}
	
	echo "\n";
}

// Cleanup old backups
echo "=== Cleaning Up Old Backups ===\n";

$sqlFiles = glob("*.sql");
$backupDirs = glob("*.d");
$allItems = array_merge($sqlFiles, $backupDirs);
$outdatedItems = [];

foreach ($allItems as $item) {
	if (is_file_outdated($item)) {
		$outdatedItems[] = $item;
	}
}

if (empty($outdatedItems)) {
	echo "No old backups to delete\n";
} else {
	$total = count($outdatedItems);
	$current = 0;
	
	foreach ($outdatedItems as $item) {
		show_progress($current, $total, basename($item));
		$current++;
		
		if (is_dir($item)) {
			exec("rm -rf " . escapeshellarg($item));
		} else {
			unlink($item);
		}
		show_progress($current, $total, basename($item));
	}
}

echo "\n=== Backup Process Complete ===\n";