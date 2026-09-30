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
 * English strings for sissource_athenasoft.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['api_key'] = 'API key';
$string['api_key_desc'] = 'Value for the AthenaSoft Api-Authorization-Key HTTP header. Stored encrypted in the ' .
    'database. A plaintext config.php value at <code>$CFG-&gt;forced_plugin_settings[\'sissource_athenasoft\']' .
    '[\'api_key\']</code> takes precedence and makes this setting read-only. The interface provides no reveal control.';
$string['api_url'] = 'AthenaSoft API URL';
$string['api_url_desc'] = 'AthenaSoft script endpoint. Test endpoints use https://api-test.&lt;DOMEINCENTRUM&gt;.be/script and live endpoints use https://api-athenasoft.&lt;DOMEINCENTRUM&gt;.be/script. The default https://api-test.example.invalid/script is a placeholder and must be replaced. A 404 from a non-whitelisted IP indicates an access/configuration issue, not a missing endpoint.';
$string['auth_cred'] = 'Authentication credential';
$string['auth_cred_desc'] = 'Credential sent as auth.cred in the AthenaSoft JSON request body. Stored encrypted in the ' .
    'database. A plaintext config.php value at <code>$CFG-&gt;forced_plugin_settings[\'sissource_athenasoft\']' .
    '[\'auth_cred\']</code> takes precedence and makes this setting read-only. The interface provides no reveal control.';
$string['auth_user'] = 'Authentication user';
$string['auth_user_desc'] = 'Username sent as auth.user in the AthenaSoft JSON request body. Stored encrypted in the ' .
    'database. A plaintext config.php value at <code>$CFG-&gt;forced_plugin_settings[\'sissource_athenasoft\']' .
    '[\'auth_user\']</code> takes precedence and makes this setting read-only. The interface provides no reveal control.';
$string['extra_params'] = 'Extra request parameters';
$string['extra_params_desc'] = 'Optional JSON object merged into every AthenaSoft request body. Example: {"school":"main","debug":false}. Invalid JSON is ignored and logged as a warning.';
$string['fieldmap'] = 'Field mapping overrides';
$string['fieldmap_desc'] = 'Optional JSON object overriding AthenaSoft source field names per record type. Supported targets: ' .
    'course (idnumber, shortname, fullname, startdate, enddate, category, templatekey); user (idnumber, username, firstname, ' .
    'lastname, email, city, country, lang, description, institution, department, phone1, phone2, address, password, and ' .
    'profile_field_&lt;shortname&gt;); enrolment (courseidnumber, useridnumber, role, startdate, enddate); ' .
    'unenrolment (courseidnumber, useridnumber). Identity targets idnumber and username must map to stable, unique ' .
    'source columns. They are used to match and create users; changing these mappings does not rename existing users ' .
    'and may cause collision skips or duplicate accounts. templatekey uses the default source column opleidingsvariantId. ' .
    'password defaults to the source key password and is used only when creating users; override it with the exact API key. ' .
    'Example: {"course":{"shortname":"ovNaam"},"user":{"email":"emailadres","password":"wachtwoord"}}. ' .
    'Empty JSON uses the built-in mapping; ' .
    'invalid JSON is rejected on save.';
$string['institution'] = 'Institution number';
$string['institution_desc'] = 'AthenaSoft instellingsnummer sent in requests and used as the namespace in course idnumbers.';
$string['periode'] = 'Period';
$string['periode_desc'] = 'AthenaSoft period value sent in every request, for example 20262027.';
$string['pluginname'] = 'AthenaSoft';
$string['privacy:metadata:athenasoft_api'] = 'The AthenaSoft API is queried by this source adapter. User identifying data is sent to and received from AthenaSoft.';
$string['privacy:metadata:athenasoft_api:email'] = 'Email address as stored in AthenaSoft.';
$string['privacy:metadata:athenasoft_api:firstname'] = 'First name as stored in AthenaSoft.';
$string['privacy:metadata:athenasoft_api:idnumber'] = 'User number ID as stored in AthenaSoft.';
$string['privacy:metadata:athenasoft_api:lastname'] = 'Last name as stored in AthenaSoft.';
$string['script_courses'] = 'Courses script ID';
$string['script_courses_desc'] = 'AthenaSoft script ID for planningen (courses).';
$string['script_placements'] = 'Placements script ID';
$string['script_placements_desc'] = 'AthenaSoft script ID for plaatsingen (students, student enrolments and student unenrolments).';
$string['script_teachers'] = 'Teachers script ID';
$string['script_teachers_desc'] = 'AthenaSoft script ID for gekoppelde leerkrachten (teachers and teacher enrolments).';
$string['settings_heading'] = 'AthenaSoft source settings';
$string['stream_courses'] = 'Courses';
$string['stream_linked_teachers'] = 'Linked teachers';
$string['stream_placements'] = 'Placements';
