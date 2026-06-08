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

// Persistent Strikes Calculation (Per-Attempt)
$violation_count = 0;
if ($cm->modname === 'quiz') {
    $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
    $lastattempts = $DB->get_records('quiz_attempts', ['quiz' => $quiz->id, 'userid' => $USER->id], 'attempt DESC', '*', 0, 1);
    if ($lastattempts) {
        $lastattempt = reset($lastattempts);
        if (empty($lastattempt->timefinish)) {
            // Attempt is currently in progress. Count violations since it started.
            $violation_count = $DB->count_records_select('local_netrago_logs', 
                "userid = ? AND cmid = ? AND timecreated >= ? AND (eventtype LIKE '%violation%' OR eventtype LIKE '%focus_loss%' OR eventtype LIKE '%tab_switch%')", 
                [$USER->id, $cmid, $lastattempt->timestart]);
        }
    }
} else {
    // For non-quiz activities, we count everything in the last 2 hours as a heuristic
    $twelvehoursago = time() - (2 * 3600);
    $violation_count = $DB->count_records_select('local_netrago_logs', 
        "userid = ? AND cmid = ? AND timecreated >= ? AND (eventtype LIKE '%violation%' OR eventtype LIKE '%focus_loss%' OR eventtype LIKE '%tab_switch%')", 
        [$USER->id, $cmid, $twelvehoursago]);
}

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
    'maxstrikes' => isset($settings->maxstrikes) ? $settings->maxstrikes : 3,
    'ajaxurl' => (new moodle_url('/local/netrago/ajax.php'))->out(false),
    'descriptor' => $descriptor_to_use,
    'attempt_url' => $url
];

$requirekyc = isset($settings->requirekyc) ? $settings->requirekyc : 1;
$requires_password = false;
if ($cm->modname === 'quiz') {
    $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'password', IGNORE_MISSING);
    if ($quiz && !empty($quiz->password)) {
        $requires_password = true;
    }
}

// No-JS Fallback / Loading Overlay
$warningmsg = get_string('js_required_warning', 'local_netrago');
$context_data = [
    'requirekyc' => $requirekyc
];

echo $OUTPUT->render_from_template('local_netrago/proctor', $context_data);


$faceapi_url = new moodle_url('/local/netrago/amd/src/face-api.min.js');
$kyc_js_url = new moodle_url('/local/netrago/amd/src/kyc.min.js'); // Assuming we load it as well, or we can use require JS
$js_injection = "
<script>var _temp_define = window.define; window.define = undefined;</script>
<script src=\"{$faceapi_url}\"></script>
<script>window.define = _temp_define;</script>
<script>
    window.netragoKycCompleted = " . (($kyc || !$requirekyc) ? 'true' : 'false') . ";
    window.netragoHasMasterFace = " . ($master_descriptor ? 'true' : 'false') . ";
    window.netragoKycAjaxUrl = '" . (new moodle_url('/local/netrago/ajax_kyc.php'))->out(false) . "';
</script>
";
$CFG->additionalhtmlhead .= $js_injection;
$PAGE->requires->js_call_amd('local_netrago/proctoringv2', 'init', [$config]);
// Load KYC script directly
$PAGE->requires->js_call_amd('local_netrago/kycv2', 'init', [[
    'cmid' => $cmid,
    'ajaxurl' => (new moodle_url('/local/netrago/ajax_kyc.php'))->out(false),
    'has_master_face' => $master_descriptor ? true : false,
    'requirecamera' => $settings->requirecamera,
    'requirekyc' => $requirekyc
]]);

echo $OUTPUT->header();

echo $OUTPUT->footer();
