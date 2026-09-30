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
 * Entrypoint security tests for local_wisa.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;


/**
 * Static checks for web entrypoint hardening.
 *
 * @group local_wisa
 * @coversNothing
 */
final class security_entrypoints_test extends \advanced_testcase {
    /**
     * Read a plugin entrypoint source file.
     *
     * @param string $filename File name in the plugin root.
     * @return string
     */
    private function read_entrypoint(string $filename): string {
        $path = __DIR__ . '/../' . $filename;
        $this->assertFileExists($path);
        return file_get_contents($path);
    }

    /**
     * Admin entrypoints should use admin setup and an explicit system capability.
     */
    public function test_admin_web_entrypoints_require_admin_setup_and_site_config_capability(): void {
        foreach (['logs.php', 'preview.php', 'provisioning.php', 'stream_status.php', 'test_connection.php'] as $filename) {
            $source = $this->read_entrypoint($filename);
            $this->assertStringContainsString('admin_externalpage_setup', $source, $filename);
            $this->assertStringContainsString("require_capability('moodle/site:config'", $source, $filename);
        }
    }

    /**
     * State-changing web actions should be protected by sesskey checks.
     */
    public function test_state_changing_web_actions_require_sesskey(): void {
        $logs = $this->read_entrypoint('logs.php');
        $preview = $this->read_entrypoint('preview.php');
        $provisioning = $this->read_entrypoint('provisioning.php');
        $testconnection = $this->read_entrypoint('test_connection.php');
        $streamstatus = $this->read_entrypoint('stream_status.php');

        $this->assertStringContainsString('if ($runsync) {', $logs);
        $this->assertStringContainsString('require_sesskey();', $logs);
        $this->assertStringContainsString("if (\$action === 'doapprove')", $preview);
        $this->assertStringContainsString("if (\$action === 'reset')", $preview);
        $this->assertStringContainsString('require_sesskey();', $preview);
        $this->assertStringContainsString("optional_param('retry', 0, PARAM_INT)", $provisioning);
        $this->assertStringContainsString('if ($retry > 0 && data_submitted()) {', $provisioning);
        $this->assertStringContainsString('require_sesskey();', $provisioning);
        $this->assertStringContainsString('if (data_submitted()) {', $testconnection);
        $this->assertStringContainsString('require_sesskey();', $testconnection);
        $this->assertStringContainsString('if (data_submitted()) {', $streamstatus);
        $sesskeyposition = strpos($streamstatus, 'require_sesskey();');
        $mutationposition = strpos($streamstatus, 'resolve_wisa_enrolments($choice)');
        $this->assertNotFalse($sesskeyposition);
        $this->assertNotFalse($mutationposition);
        $this->assertLessThan($mutationposition, $sesskeyposition);
    }

    /**
     * Stream status rendering must remain source-free and source-neutral.
     *
     * @return void
     */
    public function test_stream_status_uses_only_static_registry_and_persisted_state(): void {
        $source = $this->read_entrypoint('stream_status.php');

        $this->assertStringContainsString('source_factory::get_active_component()', $source);
        $this->assertStringContainsString('source_factory::get_registry_for_component($sourcecomponent)', $source);
        $this->assertStringContainsString('new \\local_wisa\\source_stream_state($sourcecomponent)', $source);
        $this->assertStringContainsString('new html_table()', $source);
        $this->assertStringContainsString("'type' => 'radio'", $source);
        $this->assertStringContainsString("'type' => 'submit'", $source);
        foreach (
            ['get_active_source', 'get_source_for_component', 'fetch_streams', 'queue_', 'adhoc_task',
                'feedtype', 'student_role', 'teacher_role', '<style', '<script', ] as $forbidden
        ) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden);
        }
    }

    /**
     * Preview tuple metadata must use only the source-free parent registry, state, and view path.
     *
     * @return void
     */
    public function test_preview_tuple_metadata_uses_only_source_free_parent_helpers(): void {
        $source = $this->read_entrypoint('preview.php');
        $metadataposition = strpos($source, 'source_factory::get_active_component()');
        $mappingposition = strpos($source, '// Field mapping:');

        $this->assertNotFalse($metadataposition);
        $this->assertNotFalse($mappingposition);
        $this->assertLessThan($mappingposition, $metadataposition);
        $metadata = substr($source, $metadataposition, $mappingposition - $metadataposition);

        $this->assertStringContainsString('source_factory::get_registry_for_component($sourcecomponent)', $metadata);
        $this->assertStringContainsString('new \\local_wisa\\source_stream_state($sourcecomponent)', $metadata);
        $this->assertStringContainsString('new \\local_wisa\\source_stream_migration_conflict_resolver()', $metadata);
        $this->assertStringContainsString('new \\local_wisa\\source_stream_admin_view(', $metadata);
        $this->assertStringContainsString('$view->get_rows()', $metadata);
        $this->assertStringContainsString('new html_table()', $metadata);
        $this->assertStringContainsString('$tupletable->responsive = true;', $metadata);

        foreach (
            ['get_active_source', 'get_source_for_component', 'fetch_streams', 'get_courses', 'get_students',
                'get_teachers', 'get_enrolments', 'get_unenrolments', 'feedtype', 'student_role', 'teacher_role',
                'errorcode', 'lastsuccess', 'lastattempt', 'rowcount', 'endpoint', '<style', '<script', ] as $forbidden
        ) {
            $this->assertStringNotContainsString($forbidden, $metadata, $forbidden);
        }
    }

    /**
     * Resolution feedback must be redacted and independent of exception text.
     *
     * @return void
     */
    public function test_stream_status_resolution_notifications_are_redacted(): void {
        $source = $this->read_entrypoint('stream_status.php');

        $this->assertStringContainsString("'stream_resolution_success'", $source);
        $this->assertStringContainsString("'stream_resolution_failed'", $source);
        $this->assertStringNotContainsString('getMessage()', $source);
        $this->assertStringNotContainsString('errorcode', $source);
        $this->assertStringNotContainsString('watermark]', $source);
    }

    /**
     * Explicit web actions must use the source-free action queue under the current administrator.
     *
     * @return void
     */
    public function test_explicit_actions_use_queue_and_redirect(): void {
        $logs = $this->read_entrypoint('logs.php');
        $preview = $this->read_entrypoint('preview.php');
        $provisioning = $this->read_entrypoint('provisioning.php');
        $testconnection = $this->read_entrypoint('test_connection.php');

        $this->assertStringContainsString('explicit_action_queue::queue_preview((int)$USER->id)', $preview);
        $this->assertStringContainsString('explicit_action_queue::queue_initial_load((int)$USER->id)', $preview);
        $this->assertStringContainsString(
            'explicit_action_queue::queue_connection_test((int)$USER->id, $sourcecomponent, $stream)',
            $testconnection
        );
        $this->assertStringContainsString('explicit_action_queue::queue_manual_sync((int)$USER->id)', $logs);
        $this->assertStringContainsString('$service->retry($retry, (int)$USER->id)', $provisioning);
        $this->assertStringContainsString('$service = new \\local_wisa\\provisioning_service();', $provisioning);

        foreach ([$logs, $preview, $testconnection] as $source) {
            $this->assertStringContainsString('redirect($PAGE->url);', $source);
        }
        $this->assertStringContainsString('redirect(new moodle_url($PAGE->url', $provisioning);
    }

    /**
     * The connection-test page must render a required static-registry descriptor select without source work.
     *
     * @return void
     */
    public function test_connection_test_uses_source_free_static_descriptor_form(): void {
        $source = $this->read_entrypoint('test_connection.php');

        $this->assertStringContainsString('source_factory::get_active_component()', $source);
        $this->assertStringContainsString('source_factory::get_registry_for_component($sourcecomponent)', $source);
        $this->assertStringContainsString("required_param('stream', PARAM_ALPHANUMEXT)", $source);
        $this->assertStringContainsString("\$descriptor['label']", $source);
        $this->assertStringContainsString("\$descriptor['healthcheckphase']", $source);
        $this->assertStringContainsString("\$descriptor['transport']", $source);
        $this->assertStringContainsString("get_string('test_connection_option'", $source);
        $this->assertStringContainsString('html_writer::alist(', $source);
        $this->assertStringContainsString("'name' => 'sesskey'", $source);
        $this->assertStringContainsString("'stream',", $source);
        $this->assertStringContainsString("'required' => 'required'", $source);
        $this->assertStringContainsString("'type' => 'submit'", $source);
        foreach (
            ['get_active_source', 'get_source_for_component', 'fetch_streams', 'reset($registry)',
                'feedtype', 'student_role', 'teacher_role', '<style', '<script', ] as $forbidden
        ) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden);
        }
    }

    /**
     * The connection-test page must rely on the admin page heading only.
     *
     * @return void
     */
    public function test_connection_test_does_not_render_a_duplicate_heading(): void {
        $source = $this->read_entrypoint('test_connection.php');

        $this->assertStringNotContainsString('$OUTPUT->heading', $source);
    }

    /**
     * Request entrypoints must never execute source work inline.
     *
     * @return void
     */
    public function test_explicit_action_entrypoints_do_not_run_source_work_inline(): void {
        foreach (['logs.php', 'preview.php', 'provisioning.php', 'test_connection.php'] as $filename) {
            $source = $this->read_entrypoint($filename);
            $this->assertStringNotContainsString('get_courses()', $source, $filename);
            $this->assertStringNotContainsString('sync_manager())->preview()', $source, $filename);
            $this->assertStringNotContainsString('run_full_sync()', $source, $filename);
        }
    }

    /**
     * Initial-load state must be reconciled before the preview page renders it.
     *
     * @return void
     */
    public function test_preview_reconciles_initial_load_before_status_rendering(): void {
        $source = $this->read_entrypoint('preview.php');
        $reconcileposition = strpos($source, 'explicit_action_queue::reconcile_initial_load()');
        $statusposition = strpos($source, "get_config('local_wisa', 'initial_load_done')");

        $this->assertNotFalse($reconcileposition);
        $this->assertNotFalse($statusposition);
        $this->assertLessThan($statusposition, $reconcileposition);
    }

    /**
     * Explicit action pages must read and escape only persisted safe task summaries.
     *
     * @return void
     */
    public function test_explicit_action_pages_render_sanitized_persisted_results(): void {
        $preview = $this->read_entrypoint('preview.php');
        foreach (['last_preview_status', 'last_preview_counts', 'last_preview_window', 'last_preview_time'] as $key) {
            $this->assertStringContainsString("get_config('local_wisa', '$key')", $preview);
        }
        $this->assertStringContainsString('s($previewwindow)', $preview);

        $testconnection = $this->read_entrypoint('test_connection.php');
        foreach (
            ['last_connection_test_status', 'last_connection_test_count', 'last_connection_test_time',
                'last_connection_test_source', 'last_connection_test_stream', 'last_connection_test_phase',
                'last_connection_test_transport', ] as $key
        ) {
            $this->assertStringContainsString("get_config('local_wisa', '$key')", $testconnection);
        }

        $logs = $this->read_entrypoint('logs.php');
        foreach (['last_manual_sync_status', 'last_manual_sync_mode', 'last_manual_sync_time'] as $key) {
            $this->assertStringContainsString("get_config('local_wisa', '$key')", $logs);
        }
        $this->assertStringContainsString("['queued', 'success', 'partial', 'failed']", $logs);
    }

    /**
     * Log output should escape persisted log fields before rendering.
     */
    public function test_logs_table_escapes_persisted_values(): void {
        $source = $this->read_entrypoint('logs.php');

        foreach (['action', 'objecttype', 'objectid', 'status', 'message'] as $field) {
            $this->assertStringContainsString('s($log->' . $field . ')', $source);
        }
    }

    /**
     * Provisioning output must be bounded, escaped, and rendered with native components.
     *
     * @return void
     */
    public function test_provisioning_page_uses_bounded_native_escaped_output(): void {
        $source = $this->read_entrypoint('provisioning.php');

        $this->assertStringContainsString('count_admin_rows($status)', $source);
        $this->assertStringContainsString('get_admin_page($status, $page * $perpage, $perpage)', $source);
        $this->assertStringContainsString('new paging_bar(', $source);
        $this->assertStringContainsString('new html_table()', $source);
        $this->assertStringContainsString('$OUTPUT->single_button(', $source);
        $this->assertStringContainsString("'post'", $source);
        $this->assertStringContainsString('$service->is_retry_available($record)', $source);
        foreach (['sourcecomponent', 'courseidnumber', 'desiredfullname', 'status'] as $field) {
            $this->assertStringContainsString('s($record->' . $field . ')', $source);
        }
        $this->assertStringContainsString('provisioning_diagnostic::format((string)$record->lasterror)', $source);
        $this->assertStringContainsString('s(userdate((int)$record->timemodified))', $source);
        $this->assertStringNotContainsString('getMessage()', $source);
        $this->assertStringNotContainsString('<form', $source);
        $this->assertStringNotContainsString('<style', $source);
        $this->assertStringNotContainsString('<script', $source);
    }

    /**
     * Manual recovery uses its own POST action and the shared row eligibility predicate.
     *
     * @return void
     */
    public function test_provisioning_recovery_requires_post_and_sesskey(): void {
        $source = $this->read_entrypoint('provisioning.php');

        $this->assertStringContainsString("require_capability('moodle/site:config'", $source);
        $this->assertMatchesRegularExpression(
            '/if \(\$recover > 0 && data_submitted\(\)\) \{\s*require_sesskey\(\);\s*try \{\s*' .
                '\$service->recover\(\$recover, \(int\)\$USER->id\);/',
            $source
        );
        $this->assertStringContainsString('$service->is_recovery_available($record)', $source);
        $this->assertStringContainsString("\$action = \$recoveryavailable ? 'recover' : 'retry';", $source);
        $this->assertStringContainsString('$action => (int)$record->id', $source);
        $this->assertStringContainsString("get_string('provisioning_' . \$action, 'local_wisa')", $source);
        $this->assertStringContainsString("single_button(\$actionurl, \$actionlabel, 'post')", $source);
    }

    /**
     * Parent settings must load SIS source subplugin settings.
     */
    public function test_parent_settings_load_sis_source_subplugin_settings(): void {
        $source = $this->read_entrypoint('settings.php');

        $this->assertStringContainsString("get_plugins_of_type('sissource')", $source);
        $this->assertStringContainsString('->load_settings($ADMIN', $source);
    }
}
