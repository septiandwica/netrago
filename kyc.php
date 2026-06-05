<?php
/**
 * NetraGo KYC Onboarding Page
 *
 * @package    local_netrago
 * @copyright  2026 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_URL);

require_login();

$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

$PAGE->set_url('/local/netrago/kyc.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
$PAGE->set_title('NetraGo KYC Onboarding');
$PAGE->set_heading($course->fullname);

// Check if rate limited
$thirty_mins_ago = time() - (30 * 60);
$attempts = $DB->count_records_select('local_netrago_kyc_attempts', 
    "userid = ? AND cmid = ? AND timeattempted > ? AND status = 'failed'", 
    [$USER->id, $cmid, $thirty_mins_ago]);

if ($attempts >= 5) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification("You have failed the KYC verification 5 times. You are locked out for 30 minutes. Please contact your instructor.", 'danger');
    echo $OUTPUT->footer();
    exit;
}

$PAGE->requires->css(new moodle_url('/local/netrago/styles.css'));

$fallbackurl = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cmid]);
$finalreturnurl = $returnurl ? $returnurl : $fallbackurl->out(false);

// Check for existing Master Face (successful KYC in the past)
$master_face = $DB->get_record_sql("SELECT * FROM {local_netrago_kyc} WHERE userid = ? ORDER BY timeverified DESC", [$USER->id], IGNORE_MULTIPLE);

$has_master_face = $master_face ? true : false;
$master_descriptor = $master_face ? $master_face->descriptor : '';

$settings = $DB->get_record('local_netrago', ['cmid' => $cmid]);

$templatedata = [
    'returnurl' => $finalreturnurl,
    'has_master_face' => $has_master_face,
    'requirecamera' => get_config('local_netrago', 'allow_camera') ? ($settings->requirecamera ?? 0) : 0,
    'requirefullscreen' => get_config('local_netrago', 'allow_fullscreen') ? ($settings->requirefullscreen ?? 0) : 0,
    'requirescreencapture' => get_config('local_netrago', 'allow_screencapture') ? ($settings->requirescreencapture ?? 0) : 0,
    'disablecopypaste' => get_config('local_netrago', 'allow_copypaste') ? ($settings->disablecopypaste ?? 0) : 0,
    'disablefocusloss' => get_config('local_netrago', 'allow_focusloss') ? ($settings->disablefocusloss ?? 0) : 0
];

echo $OUTPUT->header();

echo $OUTPUT->render_from_template('local_netrago/kyc', $templatedata);

// Load face-api.js properly using Moodle Page Requirements
$PAGE->requires->js(new moodle_url('/local/netrago/amd/src/face-api.min.js'));

$config = [
    'cmid' => $cmid,
    'ajaxurl' => (new moodle_url('/local/netrago/ajax_kyc.php'))->out(false),
    'has_master_face' => $has_master_face,
    'master_descriptor' => $master_descriptor
];

$PAGE->requires->js_call_amd('local_netrago/kyc', 'init', [$config]);

echo $OUTPUT->footer();
