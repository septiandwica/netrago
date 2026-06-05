<?php
/**
 * NetraGo core library functions.
 *
 * @package    local_netrago
 * @copyright  2026 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Injects NetraGo settings into all activity module configuration forms.
 *
 * @param moodleform_mod $formwrapper The moodle quickforms wrapper object.
 * @param MoodleQuickForm $mform The actual form object.
 */
function local_netrago_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB;

    // We only want to add these settings to activities that actually involve students taking an action.
    // However, as a universal plugin, we'll add it to all and let teachers decide.
    $mform->addElement('header', 'netragoheader', get_string('netragoproctoring', 'local_netrago'));

    $mform->addElement('selectyesno', 'netrago_requirecamera', get_string('requirecamera', 'local_netrago'));
    $mform->addHelpButton('netrago_requirecamera', 'requirecamera', 'local_netrago');
    $mform->setDefault('netrago_requirecamera', 0);

    $mform->addElement('selectyesno', 'netrago_requirefullscreen', get_string('requirefullscreen', 'local_netrago'));
    $mform->addHelpButton('netrago_requirefullscreen', 'requirefullscreen', 'local_netrago');
    $mform->setDefault('netrago_requirefullscreen', 0);

    $mform->addElement('selectyesno', 'netrago_disablecopypaste', get_string('disablecopypaste', 'local_netrago'));
    $mform->addHelpButton('netrago_disablecopypaste', 'disablecopypaste', 'local_netrago');
    $mform->setDefault('netrago_disablecopypaste', 0);

    // If editing an existing module, load the current settings.
    $cm = $formwrapper->get_coursemodule();
    if ($cm && $cm->id) {
        $settings = $DB->get_record('local_netrago', ['cmid' => $cm->id]);
        if ($settings) {
            $mform->setDefault('netrago_requirecamera', $settings->requirecamera);
            $mform->setDefault('netrago_requirefullscreen', $settings->requirefullscreen);
            $mform->setDefault('netrago_disablecopypaste', $settings->disablecopypaste);
        }
    }
}

/**
 * Saves NetraGo settings when an activity module is saved.
 *
 * @param stdClass $data The data submitted from the form.
 * @param stdClass $course The course object.
 */
function local_netrago_coursemodule_edit_post_actions($data, $course) {
    global $DB;

    // If we don't have a coursemodule ID, we can't save. This hook is called after the CM is created.
    if (!isset($data->coursemodule)) {
        return $data;
    }

    $cmid = $data->coursemodule;
    $settings = $DB->get_record('local_netrago', ['cmid' => $cmid]);

    $requirecamera = isset($data->netrago_requirecamera) ? $data->netrago_requirecamera : 0;
    $requirefullscreen = isset($data->netrago_requirefullscreen) ? $data->netrago_requirefullscreen : 0;
    $disablecopypaste = isset($data->netrago_disablecopypaste) ? $data->netrago_disablecopypaste : 0;

    if ($requirecamera || $requirefullscreen || $disablecopypaste) {
        $record = new stdClass();
        $record->cmid = $cmid;
        $record->requirecamera = $requirecamera;
        $record->requirefullscreen = $requirefullscreen;
        $record->disablecopypaste = $disablecopypaste;

        if ($settings) {
            $record->id = $settings->id;
            $DB->update_record('local_netrago', $record);
        } else {
            $DB->insert_record('local_netrago', $record);
        }
    } else if ($settings) {
        $DB->delete_records('local_netrago', ['id' => $settings->id]);
    }

    return $data;
}

/**
 * Injects NetraGo Proctoring Javascript on relevant activity pages.
 */
function local_netrago_before_footer() {
    global $PAGE, $USER, $DB, $CFG;

    // Ignore if not logged in or is guest or site admin
    if (!isloggedin() || isguestuser() || is_siteadmin()) {
        return;
    }

    // Only inject if we are viewing a specific course module.
    if (!isset($PAGE->cm) || empty($PAGE->cm->id)) {
        return;
    }

    $cmid = $PAGE->cm->id;

    // Check if this module has NetraGo enabled.
    $settings = $DB->get_record('local_netrago', ['cmid' => $cmid]);
    if (!$settings) {
        return;
    }

    if (!$settings->requirecamera && !$settings->requirefullscreen && !$settings->disablecopypaste) {
        return;
    }

    // Inject our AMD module.
    $config = [
        'cmid' => $cmid,
        'userid' => $USER->id,
        'requirecamera' => $settings->requirecamera,
        'requirefullscreen' => $settings->requirefullscreen,
        'disablecopypaste' => $settings->disablecopypaste,
        'ajaxurl' => (new moodle_url('/local/netrago/ajax.php'))->out(false)
    ];

    $PAGE->requires->js_call_amd('local_netrago/proctoring', 'init', [$config]);
}
