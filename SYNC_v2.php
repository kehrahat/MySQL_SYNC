<?php
// === CONFIG ===
$local_db = new mysqli("localhost", "local_user", "local_password", "local_database");
$cloud_db = new mysqli("cloud_host", "cloud_user", "cloud_password", "cloud_database");

date_default_timezone_set("Asia/Dhaka");

// === CONNECTION CHECK ===
if ($local_db->connect_error || $cloud_db->connect_error) {
    die("Connection failed: " . $local_db->connect_error . " | " . $cloud_db->connect_error);
}

// === LOCK FILE TO PREVENT OVERLAP ===
$lock_file = __DIR__ . '/sync.lock';
if (file_exists($lock_file)) {
    exit("Sync already running.\n");
}
file_put_contents($lock_file, time());
register_shutdown_function(fn() => unlink($lock_file));

// === LOG SETUP ===
$log_file = __DIR__ . "/sync.log";
file_put_contents($log_file, "Bi-directional sync started at " . date('Y-m-d H:i:s') . "\n");

$local_db->query("CREATE TABLE IF NOT EXISTS sync_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sync_start DATETIME,
    sync_end DATETIME,
    total_tables INT,
    errors TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$sync_start = date('Y-m-d H:i:s');
$total_tables = 0;
$error_logs = [];
$excluded_tables = ['sync_logs', 'audit_logs', 'log_entries','deleted_rows', 'sync_lock']; // Add more if needed

function addTimestampsIfMissing($table, $db, &$error_logs) {
    $columns = [];
    $res = $db->query("SHOW COLUMNS FROM `$table`");
    while ($row = $res->fetch_assoc()) {
        $columns[] = $row['Field'];
    }

    $alter = [];
    if (!in_array('created_at', $columns)) {
        $alter[] = "ADD `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";
    }
    if (!in_array('updated_at', $columns)) {
        $alter[] = "ADD `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
    }

    if ($alter) {
        if (!$db->query("ALTER TABLE `$table` " . implode(", ", $alter))) {
            $error_logs[] = "Failed to add timestamp columns to $table: " . $db->error;
        }
    }

    // Index Check
    $indexes = [];
    $idx = $db->query("SHOW INDEX FROM `$table`");
    while ($row = $idx->fetch_assoc()) {
        $indexes[] = $row['Column_name'];
    }

    foreach (['created_at', 'updated_at'] as $col) {
        if (in_array($col, $columns) && !in_array($col, $indexes)) {
            $db->query("ALTER TABLE `$table` ADD INDEX (`$col`)");
        }
    }

    $db->query("UPDATE `$table` SET created_at = NOW() WHERE created_at IS NULL");
}

function getLastUpdated($table, $db) {
    $res = $db->query("SELECT MAX(updated_at) FROM `$table`");
    return $res ? ($res->fetch_row()[0] ?? "1970-01-01 00:00:00") : "1970-01-01 00:00:00";
}

function syncDirection($source_db, $target_db, $table, $since, &$error_logs, $log_file, $label = '') {
    file_put_contents($log_file, "Checking $table ($label) since $since...\n", FILE_APPEND);

    $res = $source_db->query("SELECT * FROM `$table` WHERE updated_at > '$since'");
    if (!$res) {
        $error_logs[] = "Fetch failed for $table ($label): " . $source_db->error;
        return;
    }

    $columns = [];
    while ($field = $res->fetch_field()) {
        $columns[] = "`{$field->name}`";
    }
    $column_list = implode(",", $columns);
    $updates = implode(", ", array_map(fn($col) => "$col=VALUES($col)", $columns));

    $batch = [];
    $count = 0;

    while ($row = $res->fetch_assoc()) {
        $row_values = array_map(function ($v) use ($target_db) {
            return is_null($v) ? "NULL" : "'" . $target_db->real_escape_string($v) . "'";
        }, $row);
        $batch[] = "(" . implode(",", $row_values) . ")";
        $count++;

        if (count($batch) >= 500) {
            $query = "INSERT INTO `$table` ($column_list) VALUES " . implode(",", $batch)
                . " ON DUPLICATE KEY UPDATE $updates";
            if (!$target_db->query($query)) {
                $error_logs[] = "Insert failed on $table ($label): " . $target_db->error;
            }
            $batch = [];
        }
    }

    if (!empty($batch)) {
        $query = "INSERT INTO `$table` ($column_list) VALUES " . implode(",", $batch)
            . " ON DUPLICATE KEY UPDATE $updates";
        if (!$target_db->query($query)) {
            $error_logs[] = "Final insert failed on $table ($label): " . $target_db->error;
        }
    }

    file_put_contents($log_file, "Synced $count rows for $table ($label)\n", FILE_APPEND);
}

function syncTable($table, $local_db, $cloud_db, &$error_logs, $log_file) {
    global $total_tables;
    file_put_contents($log_file, "Syncing table: $table\n", FILE_APPEND);
    $total_tables++;

    try {
        // Create on cloud if missing
        if (!$cloud_db->query("SHOW TABLES LIKE '$table'")->num_rows) {
            $create = $local_db->query("SHOW CREATE TABLE `$table`")->fetch_assoc()['Create Table'];
            $cloud_db->query($create);
        }

        addTimestampsIfMissing($table, $local_db, $error_logs);
        addTimestampsIfMissing($table, $cloud_db, $error_logs);

        $local_last = getLastUpdated($table, $cloud_db);
        $cloud_last = getLastUpdated($table, $local_db);

        syncDirection($local_db, $cloud_db, $table, $local_last, $error_logs, $log_file, "Local→Cloud");
        syncDirection($cloud_db, $local_db, $table, $cloud_last, $error_logs, $log_file, "Cloud→Local");

    } catch (Throwable $e) {
        $error_logs[] = "Error syncing $table: " . $e->getMessage();
    }
}

// === MAIN SYNC LOOP ===
$tables = $local_db->query("SHOW TABLES");
while ($row = $tables->fetch_array()) {
    $table = $row[0];
    if (!in_array($table, $excluded_tables)) {
        syncTable($table, $local_db, $cloud_db, $error_logs, $log_file);
    }
}
// === DELETE SYNC LOOP ===
$_local_Delete = $cloud_db->query("SELECT * FROM deleted_rows");
if($_local_Delete){
	foreach($_local_Delete as $row){
		if($local_db->query("DELETE FROM " . $row['table_name'] . " WHERE id = '" . $row['primary_key'] . "'")){
			$cloud_db->query("DELETE FROM deleted_rows WHERE id = '" . $row['id'] . "'");
		}
	}
}
$_cloud_Delete = $local_db->query("SELECT * FROM deleted_rows");
if($_cloud_Delete){
	foreach($_cloud_Delete as $row){
		if($cloud_db->query("DELETE FROM " . $row['table_name'] . " WHERE id = '" . $row['primary_key'] . "'")){
			$local_db->query("DELETE FROM deleted_rows WHERE id = '" . $row['id'] . "'");
		}
	}
}

$sync_end = date('Y-m-d H:i:s');
$error_text = empty($error_logs) ? 'No Errors' : implode(" | ", $error_logs);

$local_db->query("INSERT INTO sync_logs (sync_start, sync_end, total_tables, errors) 
    VALUES ('$sync_start', '$sync_end', $total_tables, '" . $local_db->real_escape_string($error_text) . "')");

$local_db->query("DELETE FROM sync_logs WHERE id NOT IN 
    (SELECT id FROM (SELECT id FROM sync_logs ORDER BY id DESC LIMIT 100) temp)");

$local_db->close();
$cloud_db->close();

file_put_contents($log_file, "Sync completed at $sync_end\n", FILE_APPEND);
echo "✅ Bi-directional sync completed.\n";
?>