<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CLI script to run a WISA sync for testing.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Get command line options
list($options, $unrecognized) = cli_get_params(
    array('help' => false, 'preview' => false, 'approve' => false),
    array('h' => 'help')
);

if ($options['help']) {
    echo "WISA sync CLI\n";
    echo "Usage: php local/wisa/cli/test_sync.php [--preview|--approve]\n";
    echo "  (no option)  Run a sync now using the configured test/live mode.\n";
    echo "  --preview    Count what the next full load would fetch; writes nothing.\n";
    echo "  --approve    Go live: switch off test mode, open the gate and run the first full load.\n";
    exit(0);
}

$manager = new \local_wisa\sync_manager();

if ($options['preview']) {
    $p = $manager->preview();
    echo "Preview (school year window: {$p['window']})\n";
    foreach ($p['parts'] as $part => $c) {
        if (!empty($c['error'])) {
            echo "  $part: FETCH ERROR\n";
            continue;
        }
        $line = "  $part: fetched={$c['fetched']}";
        if (isset($c['in_scope'])) {
            $line .= " in_scope={$c['in_scope']}";
        }
        if (isset($c['new'])) {
            $line .= " new={$c['new']}";
        }
        echo $line . "\n";
    }
    exit(0);
}

try {
    if ($options['approve']) {
        echo "Approving first full load: switching off test mode and loading everything...\n";
        set_config('dry_run', 0, 'local_wisa');
        set_config('initial_load_done', 1, 'local_wisa');
        $manager->run_full_sync(true, true);
    } else {
        echo "Starting WISA sync...\n";
        $manager->run_full_sync();
    }
    echo "Sync completed successfully.\n";
} catch (Exception $e) {
    echo "Sync failed: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
