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

    return true;
}
