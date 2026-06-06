<?php
/**
 * NetraGo Proctoring iFrame Wrapper Page
 *
 * @package    local_netrago
 * @copyright  2026 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$url = required_param('url', PARAM_URL); // The original attempt.php URL to load in the iframe

require_login();

$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

// Prevent open redirect: only allow URLs within this Moodle installation.
if (strpos($url, $CFG->wwwroot) !== 0) {
    throw new \moodle_exception('invalidurl', 'error');
}

$PAGE->set_url('/local/netrago/proctor.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
$PAGE->set_title('NetraGo Proctoring Session');
$PAGE->set_heading($course->fullname);

// VERY IMPORTANT: Use embedded layout so Moodle header/footer doesn't take up space
$PAGE->set_pagelayout('embedded');

// Fetch Settings
$settings = $DB->get_record('local_netrago', ['cmid' => $cmid]);
if (!$settings) {
    // No proctoring settings for this CM — redirect to the attempt directly.
    redirect(new moodle_url($url));
}

// Persistent Strikes Calculation
$violation_count = $DB->count_records_select('local_netrago_logs', 
    "userid = ? AND cmid = ? AND (eventtype LIKE '%violation%' OR eventtype LIKE '%focus_loss%' OR eventtype LIKE '%tab_switch%')", 
    [$USER->id, $cmid]);

$kyc = $DB->get_record('local_netrago_kyc', ['userid' => $USER->id, 'cmid' => $cmid]);
$master_field = $DB->get_record('user_info_field', ['shortname' => 'netrago_master_face']);
$master_descriptor = null;
if ($master_field) {
    $master_data = $DB->get_record('user_info_data', ['userid' => $USER->id, 'fieldid' => $master_field->id]);
    if ($master_data && !empty($master_data->data)) {
        $master_descriptor = $master_data->data;
    }
}
$descriptor_to_use = $kyc ? $kyc->descriptor : ($master_descriptor ? $master_descriptor : null);

$config = [
    'cmid' => $cmid,
    'courseid' => (int)$cm->course,
    'current_strikes' => $violation_count,
    'userid' => $USER->id,
    'requirecamera' => get_config('local_netrago', 'allow_camera') ? ($settings->requirecamera ?? 0) : 0,
    'requirefullscreen' => get_config('local_netrago', 'allow_fullscreen') ? ($settings->requirefullscreen ?? 0) : 0,
    'requirescreencapture' => get_config('local_netrago', 'allow_screencapture') ? ($settings->requirescreencapture ?? 0) : 0,
    'disablecopypaste' => get_config('local_netrago', 'allow_copypaste') ? ($settings->disablecopypaste ?? 0) : 0,
    'allow_focusloss' => get_config('local_netrago', 'allow_focusloss') ? ($settings->disablefocusloss ?? 0) : 0,
    'allow_devtools' => get_config('local_netrago', 'allow_devtools') ? ($settings->disabledevtools ?? 0) : 0,
    'ajaxurl' => (new moodle_url('/local/netrago/ajax.php'))->out(false),
    'descriptor' => $descriptor_to_use,
    'attempt_url' => $url
];

// No-JS Fallback / Loading Overlay
$warningmsg = get_string('js_required_warning', 'local_netrago');
$css = "
    <style id='netrago-anti-js-bypass'>
        body { overflow: hidden !important; margin: 0; padding: 0; }
        #netrago-nojs-warning { 
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; 
            background: #ffffff; color: #333; 
            z-index: 9999999; display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            font-size: 1.1rem; text-align: center; padding: 20px;
        }
        .netrago-spinner {
            border: 4px solid #f3f3f3; border-top: 4px solid #007bff;
            border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin-bottom: 15px;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        #netrago-quiz-frame {
            border: none;
            width: 100vw;
            height: 100vh;
            display: none; /* Hidden until unlocked */
        }
    </style>
    <div id='netrago-nojs-warning'>
        <div class='netrago-spinner' id='netrago-loading-spinner'></div>
        <span id='netrago-warning-text' class='text-muted'>Initializing Proctoring Session...</span>
        <button id='netrago-start-btn' class='btn btn-primary mt-4' style='display:none; font-size: 1.1rem; padding: 10px 20px;'><i class='fa fa-desktop'></i> Start Activity & Share Screen</button>
    </div>
";
$CFG->additionalhtmlhead .= $css;

$PAGE->requires->js(new moodle_url('/local/netrago/amd/src/face-api.min.js'));
$PAGE->requires->js_call_amd('local_netrago/proctoring', 'init', [$config]);

echo $OUTPUT->header();

// The iFrame container
echo '<iframe id="netrago-quiz-frame" allow="camera *; microphone *; display-capture *; fullscreen *"></iframe>';

echo $OUTPUT->footer();
