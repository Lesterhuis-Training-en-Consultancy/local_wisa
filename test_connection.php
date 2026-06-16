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
 * Admin page: WISA connection test.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_wisa_test_connection');

$PAGE->set_url(new moodle_url('/local/wisa/test_connection.php'));
$PAGE->set_title(get_string('test_connection', 'local_wisa'));
$PAGE->set_heading(get_string('test_connection', 'local_wisa'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('test_connection', 'local_wisa'));

$client = new \local_wisa\api_client();

$start = microtime(true);
$result = $client->get_courses();
$duration = round((microtime(true) - $start) * 1000);

if ($result === false) {
    echo $OUTPUT->notification(get_string('test_failed', 'local_wisa'), 'error');
    echo \html_writer::tag('p', get_string('test_failed_help', 'local_wisa'));
    echo \html_writer::tag('p',
        \html_writer::link(new moodle_url('/local/wisa/logs.php'),
            get_string('log_view', 'local_wisa')));
} else {
    $count = is_array($result) ? count($result) : 0;
    echo $OUTPUT->notification(
        get_string('test_ok', 'local_wisa', (object)['count' => $count, 'ms' => $duration]),
        'success'
    );
    if ($count > 0) {
        echo \html_writer::tag('h4', get_string('test_sample', 'local_wisa'));
        echo \html_writer::tag('pre',
            s(json_encode($result[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            ['class' => 'p-2 bg-light']);
    }
}

echo $OUTPUT->single_button(
    new moodle_url('/local/wisa/test_connection.php'),
    get_string('test_again', 'local_wisa')
);

echo $OUTPUT->footer();
