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
 * CLI script to run a SIS sync for testing.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Get command line options.
[$options, $unrecognized] = cli_get_params(
    ['help' => false, 'preview' => false, 'approve' => false],
    ['h' => 'help']
);

if ($options['help']) {
    echo "SIS sync CLI\n";
    echo "Usage: php local/wisa/cli/test_sync.php [--preview|--approve]\n";
    echo "  (no option)  Queue a sync using the configured test/live mode.\n";
    echo "  --preview    Queue a read-only count of the next full load.\n";
    echo "  --approve    Queue the approved initial full load in live mode.\n";
    exit(0);
}

$userid = (int)get_admin()->id;

if ($options['preview']) {
    \local_wisa\explicit_action_queue::queue_preview($userid);
    echo "Preview queued.\n";
    exit(0);
}

try {
    if ($options['approve']) {
        $result = \local_wisa\explicit_action_queue::queue_initial_load($userid);
        echo $result['queued'] ? "Initial full load queued.\n" : "Initial full load already queued.\n";
    } else {
        \local_wisa\explicit_action_queue::queue_manual_sync($userid);
        echo "SIS sync queued.\n";
    }
} catch (Exception $e) {
    echo "Unable to queue SIS action: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
