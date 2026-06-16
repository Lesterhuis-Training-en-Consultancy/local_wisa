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
 * Dutch strings for local_wisa.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'WISA-synchronisatie';
$string['settings_heading'] = 'Instellingen WISA-synchronisatie';
$string['runtime_heading'] = 'Uitvoeringsopties';

$string['api_url'] = 'WISA API-URL';
$string['api_url_desc'] = 'Basis-URL van de WISA-API (bv. https://uwschool.schoolware.be/webwisad/bin/server.fcgi/QUERY/).';
$string['api_user'] = 'API-gebruikersnaam';
$string['api_user_desc'] = 'Gebruikersnaam voor authenticatie bij de WISA-API.';
$string['api_pass'] = 'API-wachtwoord';
$string['api_pass_desc'] = 'Wachtwoord voor authenticatie bij de WISA-API.';
$string['institute_num'] = 'Instellingsnummer';
$string['institute_num_desc'] = 'Het instellingsnummer waarop bevraagd wordt.';
$string['teacher_role'] = 'Rol voor leraren';
$string['teacher_role_desc'] = 'De Moodle-rol die aan leraren wordt toegekend (bv. editingteacher).';
$string['student_role'] = 'Rol voor cursisten';
$string['student_role_desc'] = 'De Moodle-rol die aan cursisten wordt toegekend (bv. student).';
$string['default_category'] = 'Standaard cursuscategorie';
$string['default_category_desc'] = 'Categorie-ID waarin nieuwe cursussen geplaatst worden (in modus "Vast", of als terugval in modus "Uit WISA-feed").';

$string['category_mode'] = 'Modus cursuscategorie';
$string['category_mode_desc'] = 'Hoe de categorie van nieuwe cursussen bepaald wordt. "Vast" plaatst elke cursus in de standaardcategorie hierboven. "Uit WISA-feed" leest het CATEGORY-veld uit de cursusquery (een naam, of een pad zoals "Talen / NT2"), zoekt het op en maakt het indien nodig aan; bij een leeg veld valt het terug op de standaardcategorie.';
$string['category_mode_fixed'] = 'Vast — altijd de standaardcategorie';
$string['category_mode_from_feed'] = 'Uit WISA-feed — gebruik het CATEGORY-veld';

$string['queries_heading'] = 'WISA-querycodes — de querynamen (Q_CODE) zoals gedefinieerd in WISA-querybeheer. De standaardwaarden zijn de MCVOD-deltaqueries; max. 10 tekens elk. Elke query krijgt een "sinds"-parameter voor delta-laden.';
$string['query_courses'] = 'Cursussenquery';
$string['query_courses_desc'] = 'Geeft één rij per cursus terug (standaard MCVOD_C).';
$string['query_students'] = 'Cursistenquery';
$string['query_students_desc'] = 'Geeft cursisten terug die gewijzigd/aangemaakt zijn sinds de delta-watermark (standaard MCVOD_STUD).';
$string['query_teachers'] = 'Lerarenquery';
$string['query_teachers_desc'] = 'Geeft leraren terug die gewijzigd/aangemaakt zijn sinds de delta-watermark (standaard MCVOD_LKR).';
$string['query_enrolments'] = 'Inschrijvingenquery';
$string['query_enrolments_desc'] = 'Globale inschrijvingsfeed met kolommen KLAS_ID, USERNAME en ROL — vervangt de aanroepen per cursus (standaard MCVOD_INS).';
$string['query_unenrolments'] = 'Uitschrijvingenquery';
$string['query_unenrolments_desc'] = 'Feed van uitgeschreven/gestopte personen; gematchte gebruikers worden in de cursus op non-actief gezet (standaard MCVOD_UIT).';

$string['sync_parts_heading'] = 'Sync-onderdelen en schooljaar — kies welke imports lopen en welke schooljaren in scope zijn. Elk onderdeel houdt zijn eigen "laatst geladen"-tijdstip bij; uit- en weer aanzetten haalt enkel de wijzigingen sindsdien op.';
$string['schoolyear_scope'] = 'Schooljaar-scope';
$string['schoolyear_scope_desc'] = 'Welke schooljaren (1 september – 31 augustus) gesynchroniseerd worden. Cursussen en inschrijvingen buiten de scope worden overgeslagen.';
$string['schoolyear_off'] = 'Geen filter (alles wat de queries teruggeven)';
$string['schoolyear_current'] = 'Alleen het lopende schooljaar';
$string['schoolyear_current_next'] = 'Lopend + volgend schooljaar';
$string['enable_courses'] = 'Cursussen synchroniseren';
$string['enable_courses_desc'] = 'Cursussen aanmaken en bijwerken (MCVOD_C).';
$string['enable_students'] = 'Cursisten synchroniseren';
$string['enable_students_desc'] = 'Cursistaccounts aanmaken en bijwerken (MCVOD_STUD).';
$string['enable_teachers'] = 'Leraren synchroniseren';
$string['enable_teachers_desc'] = 'Leraaraccounts aanmaken en bijwerken (MCVOD_LKR).';
$string['enable_enrolments'] = 'Inschrijvingen synchroniseren';
$string['enable_enrolments_desc'] = 'Cursisten en leraren inschrijven in hun cursussen (MCVOD_INS).';
$string['enable_unenrolments'] = 'Uitschrijvingen synchroniseren';
$string['enable_unenrolments_desc'] = 'Uitgeschreven cursisten in de cursus op non-actief zetten (MCVOD_UIT).';
$string['enrol_teachers'] = 'Leraren inschrijven in cursussen';
$string['enrol_teachers_desc'] = 'Binnen de inschrijvingsfeed (MCVOD_INS): schrijf de leraar-rijen in hun cursussen in. Zet dit uit om leraar-inschrijvingen over te slaan; zet je het later weer aan, dan worden de intussen gemiste inschrijvingen alsnog verwerkt (elke rol houdt een eigen delta-watermark).';
$string['enrol_students'] = 'Cursisten inschrijven in cursussen';
$string['enrol_students_desc'] = 'Binnen de inschrijvingsfeed (MCVOD_INS): schrijf de cursist-rijen in hun cursussen in. In combinatie met de leraar-schakelaar geef je leraren alvast toegang terwijl cursisten nog wachten.';
$string['enable_reconcile'] = 'Nachtelijke volledige reconciliatie';
$string['enable_reconcile_desc'] = 'Eenmaal per nacht een volledige sync uitvoeren (sinds 1900) als vangnet voor wijzigingen die de delta-watermark zou kunnen missen. Laat dit uit tot de gewone delta-sync gevalideerd is; een volledige run is zwaarder dan een delta-run.';

$string['dry_run'] = 'Dry-run-modus';
$string['dry_run_desc'] = 'Indien aan logt de sync elke wijziging die hij zou doen, maar schrijft NIETS naar de database. Handig om de configuratie te valideren of de impact van een wijziging te bekijken vóór livegang.';
$string['debug_logging'] = 'Debug-logging (API-URLs)';
$string['debug_logging_desc'] = 'Log elke WISA API-aanroep (URL, credentials gemaskeerd) in de logtabel. Zet enkel aan om problemen op te sporen — de log groeit hierdoor snel.';
$string['unenrol_safety_max'] = 'Veiligheidslimiet uitschrijvingen (aantal)';
$string['unenrol_safety_max_desc'] = 'Als één sync meer dan dit aantal inschrijvingen zou suspenden, wordt er niemand gesuspend en volgt een alarmlog. Beschermt tegen foute MCVOD_UIT-data. Zet op 0 om uit te schakelen. Standaard: 500.';
$string['unenrol_safety_pct'] = 'Veiligheidslimiet uitschrijvingen (procent)';
$string['unenrol_safety_pct_desc'] = 'Als één sync meer dan dit percentage van alle actieve inschrijvingen zou suspenden, wordt er niemand gesuspend en volgt een alarmlog. Zet op 0 om uit te schakelen. Standaard: 50.';
$string['log_retention'] = 'Logbewaring (dagen)';
$string['log_retention_desc'] = 'Logregels ouder dan dit aantal dagen worden door de dagelijkse opschoontaak verwijderd. Standaard: 30.';

$string['task_sync'] = 'WISA-synchronisatietaak';
$string['task_log_cleanup'] = 'WISA-logopschoning';
$string['task_reconcile'] = 'WISA nachtelijke reconciliatie';
$string['task_initial_load'] = 'WISA eerste volledige load (achtergrond)';

$string['log_view'] = 'WISA-logs bekijken';
$string['log_action'] = 'Actie';
$string['log_status'] = 'Status';
$string['log_message'] = 'Bericht';
$string['log_time'] = 'Tijd';
$string['log_type'] = 'Type';
$string['log_objectid'] = 'Object-ID';

$string['last_run'] = 'Laatste run';
$string['no_runs_yet'] = 'Er is nog geen sync uitgevoerd.';
$string['ago'] = 'geleden';
$string['manual_sync_btn'] = 'Nu handmatig synchroniseren';
$string['manual_sync_started'] = 'Handmatige sync gestart.';

$string['test_connection'] = 'WISA-verbinding testen';
$string['test_connection_btn'] = 'Open de testpagina voor de verbinding';
$string['test_again'] = 'Test opnieuw uitvoeren';
$string['test_ok'] = 'Verbinding OK — {$a->count} record(s) opgehaald in {$a->ms} ms.';
$string['test_failed'] = 'Verbinding mislukt.';
$string['test_failed_help'] = 'Controleer API-URL, gebruikersnaam, wachtwoord en instellingsnummer. Bekijk de WISA-logs voor de exacte HTTP-fout.';
$string['test_sample'] = 'Voorbeeldrecord (eerste):';

$string['preview_title'] = 'WISA-preview en goedkeuring';
$string['preview_intro'] = 'Bekijk wat de eerste volledige load gaat aanmaken voordat hij draait. De geplande sync blijft gepauzeerd tot je hier goedkeurt.';
$string['preview_status_pending'] = 'De eerste volledige load is nog NIET goedgekeurd — de geplande sync staat gepauzeerd en schrijft niets.';
$string['preview_status_done'] = 'De eerste volledige load is goedgekeurd — de geplande sync is actief.';
$string['preview_status_queued'] = 'De eerste volledige load draait op de achtergrond. De geplande sync wordt automatisch actief zodra die klaar is.';
$string['preview_fieldmap_heading'] = 'Wat wordt geladen, en in welke velden';
$string['fieldmap_part'] = 'Onderdeel';
$string['fieldmap_wisa'] = 'WISA-veld';
$string['fieldmap_moodle'] = 'Moodle-veld';
$string['fieldmap_note'] = 'Opmerking';
$string['fieldmap_matchkey'] = 'matchsleutel';
$string['fieldmap_catnote'] = 'alleen in categoriemodus "uit WISA-feed"';
$string['fieldmap_usernote'] = 'omgezet naar kleine letters en opgeschoond';
$string['fieldmap_emailnote'] = 'gevalideerd; genegeerd indien ongeldig';
$string['fieldmap_windownote'] = 'gebruikt voor het schooljaarfilter';
$string['fieldmap_suspendnote'] = 'zet de bijbehorende inschrijving op non-actief';
$string['preview_counts_heading'] = 'Preview van de volgende volledige load';
$string['preview_window'] = 'Schooljaarvenster: {$a}';
$string['preview_col_part'] = 'Onderdeel';
$string['preview_col_fetched'] = 'Opgehaald uit WISA';
$string['preview_col_inscope'] = 'In scope';
$string['preview_col_new'] = 'Nieuw';
$string['part_courses'] = 'Cursussen';
$string['part_students'] = 'Cursisten';
$string['part_teachers'] = 'Leraren';
$string['part_enrolments'] = 'Inschrijvingen';
$string['part_unenrolments'] = 'Uitschrijvingen';
$string['preview_fetch_error'] = 'ophaalfout';
$string['preview_note'] = 'Klik op "Preview tonen" om te tellen wat er geladen zou worden. Dit kan bij een grote dataset tot een minuut duren.';
$string['preview_btn'] = 'Preview tonen (alleen tellen)';
$string['approve_intro'] = 'Goedkeuren schakelt de testmodus uit en start de eerste volledige load als achtergrondtaak — zo kan zelfs een grote load niet aflopen op een time-out van de webserver. De geplande sync wordt automatisch actief zodra de load klaar is.';
$string['approve_btn'] = 'Goedkeuren en de eerste volledige load starten';
$string['approve_confirm'] = 'Dit schakelt de testmodus uit en start een achtergrondtaak die alle cursussen, gebruikers en inschrijvingen uit WISA in Moodle laadt. Doorgaan?';
$string['approve_done'] = 'Eerste volledige load goedgekeurd en gestart.';
$string['approve_queued'] = 'Eerste volledige load ingepland. Start bij de eerstvolgende cron-run (meestal binnen een minuut) en draait op de achtergrond.';
$string['approve_queued_info'] = 'De load draait als achtergrondtaak en kan niet aflopen op een time-out. Ververs deze pagina of open de logs om de voortgang te volgen; zodra hij klaar is, neemt de geplande sync het automatisch over.';
$string['refresh_btn'] = 'Status verversen';
$string['reset_btn'] = 'Goedkeuring resetten (sync opnieuw pauzeren)';
$string['reset_done'] = 'Goedkeuring gereset — de geplande sync staat weer gepauzeerd.';
$string['gate_pending'] = 'De eerste volledige load is nog niet goedgekeurd. De geplande sync staat gepauzeerd.';

$string['privacy:metadata:local_wisa_log'] = 'Logregels van de synchronisatie. Registreert WISA-sync-acties, inclusief identificatoren van gesynchroniseerde gebruikers, cursussen en inschrijvingen.';
$string['privacy:metadata:local_wisa_log:timecreated'] = 'Wanneer de logregel is aangemaakt.';
$string['privacy:metadata:local_wisa_log:action'] = 'De sync-actie (bv. sync_user, sync_enrol).';
$string['privacy:metadata:local_wisa_log:objecttype'] = 'Het soort object dat geraakt werd (user, course, enrollment).';
$string['privacy:metadata:local_wisa_log:objectid'] = 'Identificator van het geraakte object — voor gebruikers is dit de WISA-username/idnumber.';
$string['privacy:metadata:local_wisa_log:status'] = 'Resultaat van de actie (info, create, update, fail, warn, dryrun).';
$string['privacy:metadata:local_wisa_log:message'] = 'Vrije tekst; kan namen van gebruikers bevatten voor auditdoeleinden.';

$string['privacy:metadata:wisa_api'] = 'Deze plugin bevraagt de WISA-API. Identificerende gebruikersgegevens worden naar het WISA-systeem verstuurd en ervan ontvangen.';
$string['privacy:metadata:wisa_api:username'] = 'De WISA-login of het numerieke ID.';
$string['privacy:metadata:wisa_api:firstname'] = 'Voornaam zoals opgeslagen in WISA.';
$string['privacy:metadata:wisa_api:lastname'] = 'Achternaam zoals opgeslagen in WISA.';
$string['privacy:metadata:wisa_api:email'] = 'E-mailadres zoals opgeslagen in WISA.';
