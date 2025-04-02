<?php
// Database configurations
$local_db = new mysqli("localhost", "local_user", "local_password", "local_database");
$cloud_db = new mysqli("cloud_host", "cloud_user", "cloud_password", "cloud_database");

// Check connection
if ($local_db->connect_error || $cloud_db->connect_error) {
    die("Connection failed: " . $local_db->connect_error . " | " . $cloud_db->connect_error);
}

// Create log table if it doesn't exist
$local_db->query("CREATE TABLE IF NOT EXISTS sync_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sync_start DATETIME,
    sync_end DATETIME,
    total_tables INT,
    errors TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Create log file
$log_file = __DIR__ . "/sync.log";
file_put_contents($log_file, "Sync started at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

// Start sync log
$sync_start = date('Y-m-d H:i:s');
$total_tables = 0;
$error_logs = [];

function syncTable($table, $local_db, $cloud_db, &$error_logs) {
    global $total_tables, $log_file;
    echo "Syncing table: $table\n";
    file_put_contents($log_file, "Syncing table: $table\n", FILE_APPEND);
    $total_tables++;

    try {
        // Ensure table structure is synced
        $create_table_query = $local_db->query("SHOW CREATE TABLE `$table`")->fetch_assoc()['Create Table'];
        $cloud_db->query("DROP TABLE IF EXISTS `$table`");
        $cloud_db->query($create_table_query);

        // Check if table has a timestamp column
        $has_timestamp = $local_db->query("SHOW COLUMNS FROM `$table` LIKE 'updated_at'")->num_rows > 0;

        // Get latest sync time
        $last_sync_time = $cloud_db->query("SELECT MAX(updated_at) FROM `$table`")->fetch_row()[0] ?? "1970-01-01 00:00:00";

        // Fetch only new/updated rows if possible
        $where_clause = $has_timestamp ? "WHERE updated_at > '$last_sync_time'" : "";
        $local_data = $local_db->query("SELECT * FROM `$table` $where_clause");

        // Get column list
        $columns = [];
        while ($field = $local_data->fetch_field()) {
            $columns[] = "`" . $field->name . "`";
        }
        $column_list = implode(",", $columns);

        // Batch insert with UPSERT (INSERT ON DUPLICATE KEY UPDATE)
        $batch_size = 500;
        $values = [];
        while ($row = $local_data->fetch_assoc()) {
            $row_values = array_map(fn($value) => "'" . $cloud_db->real_escape_string($value) . "'", $row);
            $values[] = "(" . implode(",", $row_values) . ")";

            if (count($values) >= $batch_size) {
                $query = "INSERT INTO `$table` ($column_list) VALUES " . implode(",", $values) . " 
                          ON DUPLICATE KEY UPDATE " . implode(", ", array_map(fn($col) => "$col=VALUES($col)", $columns));

                if (!$cloud_db->query($query)) {
                    $error_logs[] = "Table: $table - Error: " . $cloud_db->error;
                    file_put_contents($log_file, "Error syncing $table: " . $cloud_db->error . "\n", FILE_APPEND);
                }
                $values = [];
            }
        }

        // Insert remaining records
        if (!empty($values)) {
            $query = "INSERT INTO `$table` ($column_list) VALUES " . implode(",", $values) . " 
                      ON DUPLICATE KEY UPDATE " . implode(", ", array_map(fn($col) => "$col=VALUES($col)", $columns));

            if (!$cloud_db->query($query)) {
                $error_logs[] = "Table: $table - Error: " . $cloud_db->error;
                file_put_contents($log_file, "Error syncing $table: " . $cloud_db->error . "\n", FILE_APPEND);
            }
        }

        // Handle deleted rows
        if ($has_timestamp) {
            $cloud_db->query("DELETE FROM `$table` WHERE updated_at < '$last_sync_time'");
        }

    } catch (Exception $e) {
        $error_logs[] = "Table: $table - Error: " . $e->getMessage();
        file_put_contents($log_file, "Exception in $table: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// Get list of tables
$tables_res = $local_db->query("SHOW TABLES");
while ($table = $tables_res->fetch_array()[0]) {
    syncTable($table, $local_db, $cloud_db, $error_logs);
}

// Record sync completion time
$sync_end = date('Y-m-d H:i:s');
$error_text = empty($error_logs) ? 'No Errors' : implode(" | ", $error_logs);

// Insert sync log entry
$local_db->query("INSERT INTO sync_logs (sync_start, sync_end, total_tables, errors) 
                  VALUES ('$sync_start', '$sync_end', $total_tables, '" . $local_db->real_escape_string($error_text) . "')");

// Keep only the latest 100 logs
$local_db->query("DELETE FROM sync_logs WHERE id NOT IN (SELECT id FROM (SELECT id FROM sync_logs ORDER BY id DESC LIMIT 100) AS temp)");

// Close connections
$local_db->close();
$cloud_db->close();

file_put_contents($log_file, "Database sync completed at $sync_end\n", FILE_APPEND);
echo "Database sync completed!";
?>
