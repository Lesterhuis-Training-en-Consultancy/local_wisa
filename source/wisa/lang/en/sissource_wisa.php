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
 * English strings for sissource_wisa.
 *
 * @package    sissource_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


$string['api_pass'] = 'API password';
$string['api_pass_desc'] = 'Password for WISA API authentication. Stored encrypted in the database. A plaintext ' .
    'config.php value at <code>$CFG-&gt;forced_plugin_settings[\'sissource_wisa\'][\'api_pass\']</code> takes ' .
    'precedence and makes this setting read-only. The interface provides no reveal control.';
$string['api_url'] = 'WISA API URL';
$string['api_url_desc'] = 'Base URL for the WISA API (e.g. https://yourschool.schoolware.be/webwisad/bin/server.fcgi/QUERY/).';
$string['api_user'] = 'API username';
$string['api_user_desc'] = 'Username for WISA API authentication. Stored encrypted in the database. A plaintext ' .
    'config.php value at <code>$CFG-&gt;forced_plugin_settings[\'sissource_wisa\'][\'api_user\']</code> takes ' .
    'precedence and makes this setting read-only. The interface provides no reveal control.';
$string['fieldmap'] = 'Field mapping overrides';
$string['fieldmap_desc'] = 'Optional JSON object overriding WISA source field names per record type. Supported targets: ' .
    'course (idnumber, shortname, fullname, startdate, enddate, category, templatekey); user (idnumber, username, firstname, ' .
    'lastname, email, city, country, lang, description, institution, department, phone1, phone2, address, and ' .
    'profile_field_&lt;shortname&gt;); enrolment (courseidnumber, useridnumber, role, startdate, enddate); ' .
    'unenrolment (courseidnumber, useridnumber). Identity targets idnumber and username must map to stable, unique ' .
    'source columns. They are used to match and create users; changing these mappings does not rename existing users ' .
    'and may cause collision skips or duplicate accounts. templatekey has no default and requires an explicit source column. ' .
    'Example: {"course":{"templatekey":"TEMPLATE_COLUMN"}}. Empty JSON uses the built-in mapping; invalid JSON is rejected on save.';
$string['institute_num'] = 'Institute number';
$string['institute_num_desc'] = 'The WISA institute number (instellingsnummer) to query.';
$string['pluginname'] = 'WISA / Schoolware';
$string['privacy:metadata:wisa_api'] = 'The WISA API is queried by this source adapter. User identifying data is sent to and received from the WISA system.';
$string['privacy:metadata:wisa_api:email'] = 'Email address as stored in WISA.';
$string['privacy:metadata:wisa_api:firstname'] = 'First name as stored in WISA.';
$string['privacy:metadata:wisa_api:lastname'] = 'Last name as stored in WISA.';
$string['privacy:metadata:wisa_api:username'] = 'The WISA login or numeric ID.';
$string['queries_heading'] = 'WISA query codes - the query names (Q_CODE) as defined in WISA query management. Defaults are the MCVOD delta queries; max 10 characters each. Each query receives a "sinds" (since) parameter for delta loading.';
$string['query_courses'] = 'Courses query';
$string['query_courses_desc'] = 'Returns one row per course (default MCVOD_C).';
$string['query_enrolments'] = 'Enrolments query';
$string['query_enrolments_desc'] = 'Global enrolment feed with KLAS_ID, USERNAME and ROL columns - replaces the per-course calls (default MCVOD_INS).';
$string['query_students'] = 'Students query';
$string['query_students_desc'] = 'Returns students changed/created since the delta watermark (default MCVOD_STUD).';
$string['query_teachers'] = 'Teachers query';
$string['query_teachers_desc'] = 'Returns teachers changed/created since the delta watermark (default MCVOD_LKR).';
$string['query_unenrolments'] = 'Unenrolments query';
$string['query_unenrolments_desc'] = 'Feed of leavers/stops; matched users are suspended in the course (default MCVOD_UIT).';
$string['settings_heading'] = 'WISA / Schoolware source settings';
$string['stream_courses'] = 'Courses';
$string['stream_enrolments'] = 'Enrolments';
$string['stream_student_accounts'] = 'Student accounts';
$string['stream_teacher_accounts'] = 'Teacher accounts';
$string['stream_unenrolments'] = 'Unenrolments';
$string['test_connection_link'] = 'Connection test';
$string['test_connection_link_desc'] = 'Use the parent test page to perform one explicit live course-query request for the active source.';
