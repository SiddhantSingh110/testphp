<?php
echo "PHP Configuration Details\n";
echo "========================\n";
echo "Loaded Configuration File: " . php_ini_loaded_file() . "\n";
echo "Scanned Config Files: " . php_ini_scanned_files() . "\n";
echo "\nUpload Settings:\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
?>
