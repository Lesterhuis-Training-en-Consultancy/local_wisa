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
 * Dutch strings for sissource_athenasoft.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['api_key'] = 'API-sleutel';
$string['api_key_desc'] = 'Waarde voor de AthenaSoft HTTP-header Api-Authorization-Key. Wordt versleuteld in de database ' .
    'opgeslagen. Een plaintextwaarde in config.php via <code>$CFG-&gt;forced_plugin_settings[\'sissource_athenasoft\']' .
    '[\'api_key\']</code> heeft voorrang en maakt deze instelling alleen-lezen. De interface heeft geen bediening om ' .
    'de waarde zichtbaar te maken.';
$string['api_url'] = 'AthenaSoft API-URL';
$string['api_url_desc'] = 'AthenaSoft script-endpoint. Testomgevingen gebruiken https://api-test.&lt;DOMEINCENTRUM&gt;.be/script en productieomgevingen gebruiken https://api-athenasoft.&lt;DOMEINCENTRUM&gt;.be/script. De standaardwaarde https://api-test.example.invalid/script is een placeholder en moet vervangen worden. Een 404 vanaf een niet-gewhitelist IP-adres wijst op een toegangs- of configuratieprobleem, niet op een ontbrekend endpoint.';
$string['auth_cred'] = 'Authenticatiecredential';
$string['auth_cred_desc'] = 'Credential die als auth.cred in de AthenaSoft JSON-requestbody wordt verstuurd. Wordt ' .
    'versleuteld in de database opgeslagen. Een plaintextwaarde in config.php via ' .
    '<code>$CFG-&gt;forced_plugin_settings[\'sissource_athenasoft\'][\'auth_cred\']</code> heeft voorrang en maakt deze ' .
    'instelling alleen-lezen. De interface heeft geen bediening om de waarde zichtbaar te maken.';
$string['auth_user'] = 'Authenticatiegebruiker';
$string['auth_user_desc'] = 'Gebruikersnaam die als auth.user in de AthenaSoft JSON-requestbody wordt verstuurd. Wordt ' .
    'versleuteld in de database opgeslagen. Een plaintextwaarde in config.php via ' .
    '<code>$CFG-&gt;forced_plugin_settings[\'sissource_athenasoft\'][\'auth_user\']</code> heeft voorrang en maakt deze ' .
    'instelling alleen-lezen. De interface heeft geen bediening om de waarde zichtbaar te maken.';
$string['extra_params'] = 'Extra requestparameters';
$string['extra_params_desc'] = 'Optioneel JSON-object dat in elke AthenaSoft requestbody wordt samengevoegd. Voorbeeld: {"school":"main","debug":false}. Ongeldige JSON wordt genegeerd en als waarschuwing gelogd.';
$string['fieldmap'] = 'Veldmapping-overschrijvingen';
$string['fieldmap_desc'] = 'Optioneel JSON-object dat AthenaSoft-bronveldnamen per recordtype overschrijft. Ondersteunde doelen: ' .
    'course (idnumber, shortname, fullname, startdate, enddate, category, templatekey); user (idnumber, username, firstname, ' .
    'lastname, email, city, country, lang, description, institution, department, phone1, phone2, address, password en ' .
    'profile_field_&lt;shortname&gt;); enrolment (courseidnumber, useridnumber, role, startdate, enddate); ' .
    'unenrolment (courseidnumber, useridnumber). De identiteitsdoelen idnumber en username moeten naar stabiele, unieke ' .
    'bronkolommen verwijzen. Ze worden gebruikt om gebruikers te koppelen en aan te maken; wijziging van deze mappings ' .
    'hernoemt bestaande gebruikers niet en kan leiden tot overgeslagen conflicten of dubbele accounts. templatekey gebruikt ' .
    'standaard de bronkolom opleidingsvariantId. password gebruikt standaard de broncode password en wordt alleen gebruikt ' .
    'bij het aanmaken van gebruikers; overschrijf die met de exacte API-sleutel. Voorbeeld: ' .
    '{"course":{"shortname":"ovNaam"},"user":{"email":"emailadres","password":"wachtwoord"}}. Lege JSON gebruikt ' .
    'de ingebouwde mapping; ongeldige JSON ' .
    'wordt bij opslaan geweigerd.';
$string['institution'] = 'Instellingsnummer';
$string['institution_desc'] = 'AthenaSoft-instellingsnummer dat in requests wordt verstuurd en als namespace in cursus-idnumbers wordt gebruikt.';
$string['periode'] = 'Periode';
$string['periode_desc'] = 'AthenaSoft-periodewaarde die in elke request wordt verstuurd, bijvoorbeeld 20262027.';
$string['pluginname'] = 'AthenaSoft';
$string['privacy:metadata:athenasoft_api'] = 'Deze source-adapter bevraagt de AthenaSoft-API. Identificerende gebruikersgegevens worden naar AthenaSoft verstuurd en ervan ontvangen.';
$string['privacy:metadata:athenasoft_api:email'] = 'E-mailadres zoals opgeslagen in AthenaSoft.';
$string['privacy:metadata:athenasoft_api:firstname'] = 'Voornaam zoals opgeslagen in AthenaSoft.';
$string['privacy:metadata:athenasoft_api:idnumber'] = 'Gebruikersnummer-ID zoals opgeslagen in AthenaSoft.';
$string['privacy:metadata:athenasoft_api:lastname'] = 'Achternaam zoals opgeslagen in AthenaSoft.';
$string['script_courses'] = 'Script-ID cursussen';
$string['script_courses_desc'] = 'AthenaSoft script-ID voor planningen (cursussen).';
$string['script_placements'] = 'Script-ID plaatsingen';
$string['script_placements_desc'] = 'AthenaSoft script-ID voor plaatsingen (cursisten, cursistinschrijvingen en cursistuitschrijvingen).';
$string['script_teachers'] = 'Script-ID leerkrachten';
$string['script_teachers_desc'] = 'AthenaSoft script-ID voor gekoppelde leerkrachten (leerkrachten en leerkrachtinschrijvingen).';
$string['settings_heading'] = 'Instellingen AthenaSoft-source';
$string['stream_courses'] = 'Cursussen';
$string['stream_linked_teachers'] = 'Gekoppelde leerkrachten';
$string['stream_placements'] = 'Plaatsingen';
