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

    if (get_config('local_netrago', 'allow_focusloss')) {
        $mform->addElement('selectyesno', 'netrago_disablefocusloss', get_string('disablefocusloss', 'local_netrago'));
        $mform->addHelpButton('netrago_disablefocusloss', 'disablefocusloss', 'local_netrago');
        $mform->setDefault('netrago_disablefocusloss', 0);
    }

    if (get_config('local_netrago', 'allow_devtools')) {
        $mform->addElement('selectyesno', 'netrago_disabledevtools', get_string('disabledevtools', 'local_netrago'));
        $mform->addHelpButton('netrago_disabledevtools', 'disabledevtools', 'local_netrago');
        $mform->setDefault('netrago_disabledevtools', 0);
    }

    // If editing an existing module, load the current settings.
    $cm = $formwrapper->get_coursemodule();
    if ($cm && $cm->id) {
        $settings = $DB->get_record('local_netrago', ['cmid' => $cm->id]);
        if ($settings) {
            $mform->setDefault('netrago_requirecamera', $settings->requirecamera);
            $mform->setDefault('netrago_requirefullscreen', $settings->requirefullscreen);
            $mform->setDefault('netrago_disablecopypaste', $settings->disablecopypaste);
            $mform->setDefault('netrago_disablefocusloss', $settings->disablefocusloss ?? 0);
            $mform->setDefault('netrago_disabledevtools', $settings->disabledevtools ?? 0);
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
    $disablefocusloss = isset($data->netrago_disablefocusloss) ? $data->netrago_disablefocusloss : 0;
    $disabledevtools = isset($data->netrago_disabledevtools) ? $data->netrago_disabledevtools : 0;

    if ($requirecamera || $requirefullscreen || $disablecopypaste || $disablefocusloss || $disabledevtools) {
        $record = new stdClass();
        $record->cmid = $cmid;
        $record->requirecamera = $requirecamera;
        $record->requirefullscreen = $requirefullscreen;
        $record->disablecopypaste = $disablecopypaste;
        $record->disablefocusloss = $disablefocusloss;
        $record->disabledevtools = $disabledevtools;

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
 * We use extend_navigation as it is a standard Moodle callback that runs on every page.
 */
function local_netrago_extend_navigation(global_navigation $nav) {
    global $PAGE, $USER, $DB, $CFG;

    // Check global enable (default to 1 if not set in db yet)
    $enabled = get_config('local_netrago', 'enable_plugin');
    if ($enabled === false) {
        $enabled = 1;
    }
    if (!$enabled) {
        return;
    }

    // Ignore if not logged in or is guest
    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Try to get cmid from PAGE or from URL parameters
    $cmid = 0;
    if (isset($PAGE->cm) && !empty($PAGE->cm->id)) {
        $cmid = $PAGE->cm->id;
    } else {
        $cmid = optional_param('cmid', 0, PARAM_INT);
        if (!$cmid) {
            $cmid = optional_param('id', 0, PARAM_INT);
        }
    }

    if (!$cmid) {
        return;
    }

    // We only want to inject on view/attempt pages, not course pages or edit pages
    $urlpath = '';
    if ($PAGE->url) {
        $urlpath = $PAGE->url->out_as_local_url(false);
    } else {
        $urlpath = $_SERVER['REQUEST_URI'];
    }
    
    if (strpos($urlpath, '/mod/') === false || strpos($urlpath, 'edit') !== false) {
        return;
    }

    $context = context_module::instance($cmid);

    // Check if this module has NetraGo enabled.
    $settings = $DB->get_record('local_netrago', ['cmid' => $cmid]);
    if (!$settings) {
        return;
    }

    if (!$settings->requirecamera && !$settings->requirefullscreen && !$settings->disablecopypaste && !$settings->disablefocusloss && !$settings->disabledevtools) {
        return;
    }

    // Do not proctor users who can manage activities (Teachers/Admins).
    // This allows them to test the plugin by using the "Switch role to... Student" feature.
    // Instead of proctoring them, we show a "View NetraGo Report" button.
    if (has_capability('moodle/course:manageactivities', $context)) {
        $reporturl = new moodle_url('/local/netrago/report.php', ['cmid' => $cmid]);
        $btncss = "<style>.netrago-teacher-btn { display:block; margin: 15px 0; padding: 10px; background:#007bff; color:#fff; text-align:center; border-radius:5px; font-weight:bold; text-decoration:none; }</style>";
        $js = "
            document.addEventListener('DOMContentLoaded', function() {
                var btn = document.createElement('a');
                btn.href = '{$reporturl}';
                btn.className = 'netrago-teacher-btn';
                btn.innerHTML = '<i class=\"fa fa-shield\"></i> View NetraGo Proctoring Report for this Activity';
                var region = document.querySelector('[role=\"main\"]') || document.querySelector('#region-main');
                if (region) {
                    region.appendChild(btn);
                }
            });
        ";
        $CFG->additionalhtmlhead .= $btncss . "<script>{$js}</script>";
        return;
    }

    // Require KYC Onboarding if they don't have a baseline for this CM.
    $kyc = $DB->get_record('local_netrago_kyc', ['userid' => $USER->id, 'cmid' => $cmid]);
    
    // Only require KYC if camera/face-verification is enabled for this activity
    if ($settings->requirecamera && !$kyc) {
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
        'allow_focusloss' => get_config('local_netrago', 'allow_focusloss') ? $settings->disablefocusloss : 0,
        'allow_devtools' => get_config('local_netrago', 'allow_devtools') ? $settings->disabledevtools : 0,
        'ajaxurl' => (new moodle_url('/local/netrago/ajax.php'))->out(false),
        'descriptor' => $kyc ? $kyc->descriptor : null
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
