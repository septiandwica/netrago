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

    // Check if plugin is globally enabled
    if (!get_config('local_netrago', 'enable_plugin')) {
        return;
    }

    $mform->addElement('header', 'netragoheader', get_string('netragoproctoring', 'local_netrago'));

    if (get_config('local_netrago', 'allow_camera')) {
        $mform->addElement('selectyesno', 'netrago_requirecamera', get_string('requirecamera', 'local_netrago'));
        $mform->addHelpButton('netrago_requirecamera', 'requirecamera', 'local_netrago');
        $mform->setDefault('netrago_requirecamera', 0);
    }

    if (get_config('local_netrago', 'allow_fullscreen')) {
        $mform->addElement('selectyesno', 'netrago_requirefullscreen', get_string('requirefullscreen', 'local_netrago'));
        $mform->addHelpButton('netrago_requirefullscreen', 'requirefullscreen', 'local_netrago');
        $mform->setDefault('netrago_requirefullscreen', 0);
    }

    if (get_config('local_netrago', 'allow_copypaste')) {
        $mform->addElement('selectyesno', 'netrago_disablecopypaste', get_string('disablecopypaste', 'local_netrago'));
        $mform->addHelpButton('netrago_disablecopypaste', 'disablecopypaste', 'local_netrago');
        $mform->setDefault('netrago_disablecopypaste', 0);
    }

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

    // Check global enable
    if (!get_config('local_netrago', 'enable_plugin')) {
        return;
    }

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

    // Require KYC Onboarding if they don't have a baseline for this CM.
    $kyc = $DB->get_record('local_netrago_kyc', ['userid' => $USER->id, 'cmid' => $cmid]);
    
    if (!$kyc) {
        $returnurl = new moodle_url($PAGE->url);
        $kycurl = new moodle_url('/local/netrago/kyc.php', ['cmid' => $cmid, 'returnurl' => $returnurl->out_as_local_url(false)]);
        redirect($kycurl);
    }

    // Inject our AMD module.
    $config = [
        'cmid' => $cmid,
        'userid' => $USER->id,
        'requirecamera' => get_config('local_netrago', 'allow_camera') ? $settings->requirecamera : 0,
        'requirefullscreen' => get_config('local_netrago', 'allow_fullscreen') ? $settings->requirefullscreen : 0,
        'disablecopypaste' => get_config('local_netrago', 'allow_copypaste') ? $settings->disablecopypaste : 0,
        'allow_focusloss' => get_config('local_netrago', 'allow_focusloss'),
        'allow_devtools' => get_config('local_netrago', 'allow_devtools'),
        'ajaxurl' => (new moodle_url('/local/netrago/ajax.php'))->out(false),
        'descriptor' => $kyc->descriptor
    ];

    // No-JS Fallback: Hide the main content via CSS.
    // The proctoring.js will remove this CSS once camera/permissions are granted.
    $warningmsg = get_string('js_required_warning', 'local_netrago');
    $css = "
        <style id='netrago-anti-js-bypass'>
            #region-main, .region-main, [role='main'] { display: none !important; }
            .netrago-nojs-warning { 
                padding: 20px; background: #f8d7da; color: #721c24; 
                border: 1px solid #f5c6cb; border-radius: 5px; 
                font-weight: bold; text-align: center; margin: 20px;
            }
        </style>
        <div class='netrago-nojs-warning' id='netrago-nojs-warning'>
            <i class='fa fa-exclamation-triangle fa-2x mb-2'></i><br>
            {$warningmsg}
        </div>
    ";
    
    // We add this to the page header so it renders before the content.
    $CFG->additionalhtmlhead .= $css;

    $PAGE->requires->js(new moodle_url('/local/netrago/amd/src/face-api.min.js'));
    $PAGE->requires->js_call_amd('local_netrago/proctoring', 'init', [$config]);
}
