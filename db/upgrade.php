<?php
/**
 * Upgrade logic for the netrago local plugin.
 *
 * @package    local_netrago
 * @copyright  2026 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the local_netrago plugin.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool Always true.
 */
function xmldb_local_netrago_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026060503) {

        // Define field requirescreencapture to be added to local_netrago.
        $table = new xmldb_table('local_netrago');
        $field = new xmldb_field('requirescreencapture', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'requirefullscreen');

        // Conditionally launch add field requirescreencapture.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Netrago savepoint reached.
        upgrade_plugin_savepoint(true, 2026060503, 'local', 'netrago');
    }

    if ($oldversion < 2026060504) {
        // Change imagedata column to longtext to support base64 screen captures.
        $table = new xmldb_table('local_netrago_logs');
        $field = new xmldb_field('imagedata', XMLDB_TYPE_TEXT, 'big', null, null, null, null, 'eventtype');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }

        // Change selfiedata, ktpdata, descriptor columns in kyc table.
        $table = new xmldb_table('local_netrago_kyc');

        $field = new xmldb_field('selfiedata', XMLDB_TYPE_TEXT, 'big', null, XMLDB_NOTNULL, null, null, 'cmid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }

        $field = new xmldb_field('ktpdata', XMLDB_TYPE_TEXT, 'big', null, XMLDB_NOTNULL, null, null, 'selfiedata');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }

        $field = new xmldb_field('descriptor', XMLDB_TYPE_TEXT, 'big', null, XMLDB_NOTNULL, null, null, 'ktpdata');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026060504, 'local', 'netrago');
    }

    if ($oldversion < 2026060803) {
        $table = new xmldb_table('local_netrago');
        
        $field_cp = new xmldb_field('disablecopypaste', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'requirescreencapture');
        if (!$dbman->field_exists($table, $field_cp)) {
            $dbman->add_field($table, $field_cp);
        }
        
        $field_fl = new xmldb_field('disablefocusloss', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'disablecopypaste');
        if (!$dbman->field_exists($table, $field_fl)) {
            $dbman->add_field($table, $field_fl);
        }
        
        $field_dt = new xmldb_field('disabledevtools', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'disablefocusloss');
        if (!$dbman->field_exists($table, $field_dt)) {
            $dbman->add_field($table, $field_dt);
        }

        $field_ms = new xmldb_field('maxstrikes', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '3', 'disabledevtools');
        if (!$dbman->field_exists($table, $field_ms)) {
            $dbman->add_field($table, $field_ms);
        }
        
        upgrade_plugin_savepoint(true, 2026060803, 'local', 'netrago');
    }

    if ($oldversion < 2026060804) {
        $table = new xmldb_table('local_netrago');
        $field = new xmldb_field('requirekyc', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'maxstrikes');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026060804, 'local', 'netrago');
    }

    if ($oldversion < 2026060806) {
        $table = new xmldb_table('local_netrago');
        $field = new xmldb_field('requireaudio', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'requirekyc');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026060806, 'local', 'netrago');
    }

    return true;
}
