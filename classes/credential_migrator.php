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
 * Migrates source credential configuration to Sodium ciphertext.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Safely migrates raw source credential values without consulting forced settings.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class credential_migrator {
    /** @var callable|null Test seam for the Sodium extension check. */
    private $sodiumchecker;

    /** @var callable|null Test seam for persisted credential writes. */
    private $configwriter;

    /**
     * Construct a migrator.
     *
     * @param callable|null $sodiumchecker Optional test seam for Sodium availability.
     * @param callable|null $configwriter Optional test seam for persisted credential writes.
     * @return void
     */
    public function __construct(?callable $sodiumchecker = null, ?callable $configwriter = null) {
        $this->sodiumchecker = $sodiumchecker;
        $this->configwriter = $configwriter;
    }

    /**
     * Encrypt legacy plaintext settings while preserving valid existing ciphertext unchanged.
     *
     * @param array $credentials Component names mapped to their credential setting names.
     * @return bool True when all values are safe for an upgrade savepoint, otherwise false.
     */
    public function migrate(array $credentials): bool {
        global $DB;

        $plaintextcredentials = [];
        foreach ($credentials as $component => $settingnames) {
            foreach ($settingnames as $settingname) {
                $record = $DB->get_record('config_plugins', [
                    'plugin' => $component,
                    'name' => $settingname,
                ], 'id, value');
                if ($record === false || $record->value === '') {
                    continue;
                }

                $storedvalue = (string)$record->value;
                if ($this->is_recognised_ciphertext($storedvalue)) {
                    try {
                        \core\encryption::decrypt($storedvalue);
                    } catch (\Throwable $exception) {
                        self::log_migration_failure($component, $settingname);
                        return false;
                    }
                    continue;
                }

                $plaintextcredentials[] = (object)[
                    'component' => $component,
                    'settingname' => $settingname,
                    'value' => $storedvalue,
                ];
            }
        }

        if ($plaintextcredentials === []) {
            return true;
        }

        if (!$this->is_sodium_available()) {
            $credential = reset($plaintextcredentials);
            self::log_sodium_unavailable($credential->component, $credential->settingname);
            return false;
        }

        foreach ($plaintextcredentials as $credential) {
            try {
                $encryptedvalue = \core\encryption::encrypt($credential->value);
            } catch (\Throwable $exception) {
                self::log_migration_failure($credential->component, $credential->settingname);
                return false;
            }
            if (strpos($encryptedvalue, 'sodium:') !== 0) {
                self::log_migration_failure($credential->component, $credential->settingname);
                return false;
            }
            $credential->encryptedvalue = $encryptedvalue;
        }

        $transaction = $DB->start_delegated_transaction();
        $currentcredential = null;
        try {
            foreach ($plaintextcredentials as $credential) {
                $currentcredential = $credential;
                if ($this->configwriter === null) {
                    $written = set_config(
                        $credential->settingname,
                        $credential->encryptedvalue,
                        $credential->component
                    );
                } else {
                    $written = call_user_func(
                        $this->configwriter,
                        $credential->settingname,
                        $credential->encryptedvalue,
                        $credential->component
                    );
                }
                if (!$written) {
                    throw new \RuntimeException('Credential configuration write failed.');
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            if ($currentcredential !== null) {
                self::log_migration_failure($currentcredential->component, $currentcredential->settingname);
            }
            if (!$transaction->is_disposed()) {
                try {
                    $transaction->rollback($exception);
                } catch (\Throwable $rollbackexception) {
                    if ($currentcredential !== null) {
                        self::log_transaction_rollback($currentcredential->component, $currentcredential->settingname);
                    }
                }
            }
            return false;
        }

        return true;
    }

    /**
     * Determine whether a raw value declares a Moodle-supported ciphertext format.
     *
     * @param string $storedvalue Raw persisted value.
     * @return bool True when the value must be decrypted before migration.
     */
    private function is_recognised_ciphertext(string $storedvalue): bool {
        return strpos($storedvalue, 'sodium:') === 0
            || strpos($storedvalue, 'openssl-aes-256-ctr:') === 0;
    }

    /**
     * Determine whether Sodium encryption is available.
     *
     * @return bool True when the migration may create Sodium ciphertext.
     */
    private function is_sodium_available(): bool {
        if ($this->sodiumchecker !== null) {
            return (bool)call_user_func($this->sodiumchecker);
        }
        return extension_loaded('sodium');
    }

    /**
     * Log an unsafe migration candidate without including credential data or exception details.
     *
     * @param string $component Source component name.
     * @param string $settingname Credential setting name.
     * @return void
     */
    private static function log_migration_failure(string $component, string $settingname): void {
        debugging(
            'local_wisa credential migration failed for ' . $component . '/' . $settingname . '.',
            DEBUG_DEVELOPER
        );
    }

    /**
     * Log unavailable Sodium without including credential data.
     *
     * @param string $component Source component name.
     * @param string $settingname Credential setting name.
     * @return void
     */
    private static function log_sodium_unavailable(string $component, string $settingname): void {
        debugging(
            'local_wisa credential migration requires Sodium for ' . $component . '/' . $settingname . '.',
            DEBUG_DEVELOPER
        );
    }

    /**
     * Log transaction rollback handling without credential data or exception details.
     *
     * @param string $component Source component name.
     * @param string $settingname Credential setting name.
     * @return void
     */
    private static function log_transaction_rollback(string $component, string $settingname): void {
        debugging(
            'local_wisa credential migration rolled back for ' . $component . '/' . $settingname . '.',
            DEBUG_DEVELOPER
        );
    }
}
