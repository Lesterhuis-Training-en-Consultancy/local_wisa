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
 * Dutch strings for sissource_wisa.
 *
 * @package    sissource_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


$string['api_pass'] = 'API-wachtwoord';
$string['api_pass_desc'] = 'Wachtwoord voor authenticatie bij de WISA-API. Wordt versleuteld in de database opgeslagen. ' .
    'Een plaintextwaarde in config.php via <code>$CFG-&gt;forced_plugin_settings[\'sissource_wisa\']' .
    '[\'api_pass\']</code> heeft voorrang en maakt deze instelling alleen-lezen. De interface heeft geen bediening ' .
    'om de waarde zichtbaar te maken.';
$string['api_url'] = 'WISA API-URL';
$string['api_url_desc'] = 'Basis-URL van de WISA-API (bv. https://uwschool.schoolware.be/webwisad/bin/server.fcgi/QUERY/).';
$string['api_user'] = 'API-gebruikersnaam';
$string['api_user_desc'] = 'Gebruikersnaam voor authenticatie bij de WISA-API. Wordt versleuteld in de database ' .
    'opgeslagen. Een plaintextwaarde in config.php via <code>$CFG-&gt;forced_plugin_settings[\'sissource_wisa\']' .
    '[\'api_user\']</code> heeft voorrang en maakt deze instelling alleen-lezen. De interface heeft geen bediening ' .
    'om de waarde zichtbaar te maken.';
$string['fieldmap'] = 'Overschrijvingen van veldmapping';
$string['fieldmap_desc'] = 'Optioneel JSON-object dat WISA-bronveldnamen per recordtype overschrijft. Ondersteunde doelen: ' .
    'course (idnumber, shortname, fullname, startdate, enddate, category, templatekey); user (idnumber, username, firstname, ' .
    'lastname, email, city, country, lang, description, institution, department, phone1, phone2, address en ' .
    'profile_field_&lt;shortname&gt;); enrolment (courseidnumber, useridnumber, role, startdate, enddate); ' .
    'unenrolment (courseidnumber, useridnumber). De identiteitsdoelen idnumber en username moeten naar stabiele, unieke ' .
    'bronkolommen verwijzen. Ze worden gebruikt om gebruikers te koppelen en aan te maken; wijziging van deze mappings ' .
    'hernoemt bestaande gebruikers niet en kan leiden tot overgeslagen conflicten of dubbele accounts. templatekey heeft ' .
    'geen standaard en vereist een expliciete bronkolom. Voorbeeld: {"course":{"templatekey":"TEMPLATE_COLUMN"}}. ' .
    'Lege JSON gebruikt de ingebouwde mapping; ongeldige JSON wordt bij opslaan geweigerd.';
$string['institute_num'] = 'Instellingsnummer';
$string['institute_num_desc'] = 'Het WISA-instellingsnummer waarop bevraagd wordt.';
$string['pluginname'] = 'WISA / Schoolware';
$string['privacy:metadata:wisa_api'] = 'Deze source-adapter bevraagt de WISA-API. Identificerende gebruikersgegevens worden naar het WISA-systeem verstuurd en ervan ontvangen.';
$string['privacy:metadata:wisa_api:email'] = 'E-mailadres zoals opgeslagen in WISA.';
$string['privacy:metadata:wisa_api:firstname'] = 'Voornaam zoals opgeslagen in WISA.';
$string['privacy:metadata:wisa_api:lastname'] = 'Achternaam zoals opgeslagen in WISA.';
$string['privacy:metadata:wisa_api:username'] = 'De WISA-login of het numerieke ID.';
$string['queries_heading'] = 'WISA-querycodes - de querynamen (Q_CODE) zoals gedefinieerd in WISA-querybeheer. De standaardwaarden zijn de MCVOD-deltaqueries; max. 10 tekens elk. Elke query krijgt een "sinds"-parameter voor delta-laden.';
$string['query_courses'] = 'Cursussenquery';
$string['query_courses_desc'] = 'Geeft één rij per cursus terug (standaard MCVOD_C).';
$string['query_enrolments'] = 'Inschrijvingenquery';
$string['query_enrolments_desc'] = 'Globale inschrijvingsfeed met kolommen KLAS_ID, USERNAME en ROL - vervangt de aanroepen per cursus (standaard MCVOD_INS).';
$string['query_students'] = 'Cursistenquery';
$string['query_students_desc'] = 'Geeft cursisten terug die gewijzigd/aangemaakt zijn sinds de delta-watermark (standaard MCVOD_STUD).';
$string['query_teachers'] = 'Lerarenquery';
$string['query_teachers_desc'] = 'Geeft leraren terug die gewijzigd/aangemaakt zijn sinds de delta-watermark (standaard MCVOD_LKR).';
$string['query_unenrolments'] = 'Uitschrijvingenquery';
$string['query_unenrolments_desc'] = 'Feed van uitgeschreven/gestopte personen; gematchte gebruikers worden in de cursus op non-actief gezet (standaard MCVOD_UIT).';
$string['settings_heading'] = 'Instellingen WISA / Schoolware-source';
$string['stream_courses'] = 'Cursussen';
$string['stream_enrolments'] = 'Inschrijvingen';
$string['stream_student_accounts'] = 'Cursistenaccounts';
$string['stream_teacher_accounts'] = 'Lerarenaccounts';
$string['stream_unenrolments'] = 'Uitschrijvingen';
$string['test_connection_link'] = 'Verbindingstest';
$string['test_connection_link_desc'] = 'Gebruik de testpagina van de parent plugin om expliciet één live cursusquery voor de actieve source uit te voeren.';
