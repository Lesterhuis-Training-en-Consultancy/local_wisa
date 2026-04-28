<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Get command line options
list($options, $unrecognized) = cli_get_params(
    array('help' => false),
    array('h' => 'help')
);

if ($options['help']) {
    echo "WISA Sync Test Script\n";
    echo "Usage: php local/wisa/cli/test_sync.php\n";
    exit(0);
}

echo "Starting WISA Sync Test...\n";

try {
    $manager = new \local_wisa\sync_manager();
    $manager->run_full_sync();
    echo "Sync completed successfully.\n";
} catch (Exception $e) {
    echo "Sync failed: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
