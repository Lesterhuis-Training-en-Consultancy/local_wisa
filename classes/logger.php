<?php
namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

class logger {
    public static function log($action, $objecttype, $objectid, $status, $message = '') {
        global $DB;

        $record = new \stdClass();
        $record->timecreated = time();
        $record->action = substr($action, 0, 20);
        $record->objecttype = substr($objecttype, 0, 20);
        $record->objectid = substr($objectid, 0, 100);
        $record->status = substr($status, 0, 10);
        $record->message = $message;

        try {
            $DB->insert_record('local_wisa_log', $record);
        } catch (\Exception $e) {
            // Fallback logging if DB fails
            error_log("WISA Plugin Logging Failed: " . $e->getMessage());
        }
    }
}
