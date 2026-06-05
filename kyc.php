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

// Add CSS
$css = "
<style>
#kyc-container { max-width: 600px; margin: 0 auto; text-align: center; }
#video-container { position: relative; display: inline-block; }
#webcam { transform: scaleX(-1); border-radius: 8px; border: 2px solid #ccc; width: 100%; max-width: 400px; }
canvas { position: absolute; top: 0; left: 0; transform: scaleX(-1); width: 100%; max-width: 400px; }
.step { display: none; }
.step.active { display: block; }
</style>
";
$CFG->additionalhtmlhead .= $css;

echo $OUTPUT->header();

echo html_writer::start_tag('div', ['id' => 'kyc-container', 'class' => 'card p-4 shadow-sm']);
echo html_writer::tag('h2', 'Identity Verification (KYC)', ['class' => 'mb-3']);
echo html_writer::tag('p', 'This activity requires identity verification. Please prepare your ID Card (KTP/KTM).', ['id' => 'kyc-status', 'class' => 'text-muted']);

// Step 1: Model Loading
echo html_writer::start_tag('div', ['id' => 'step-loading', 'class' => 'step active']);
echo html_writer::tag('div', '', ['class' => 'spinner-border text-primary', 'role' => 'status']);
echo html_writer::tag('p', 'Loading AI Models... Please wait.', ['class' => 'mt-2']);
echo html_writer::end_tag('div');

// Video container
echo html_writer::start_tag('div', ['id' => 'video-container', 'class' => 'my-3 mx-auto', 'style' => 'display:none;']);
echo html_writer::tag('video', '', ['id' => 'webcam', 'autoplay' => true, 'muted' => true, 'playsinline' => true]);
echo html_writer::end_tag('div');

// Step 2: Selfie
echo html_writer::start_tag('div', ['id' => 'step-selfie', 'class' => 'step']);
echo html_writer::tag('h4', 'Step 1: Take a Selfie');
echo html_writer::tag('p', 'Look directly at the camera and ensure your face is well-lit.');
echo html_writer::tag('button', 'Capture Selfie', ['id' => 'btn-selfie', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('div');

// Step 3: ID Card
echo html_writer::start_tag('div', ['id' => 'step-idcard', 'class' => 'step']);
echo html_writer::tag('h4', 'Step 2: Show your ID Card');
echo html_writer::tag('p', 'Hold your ID card (KTP/KTM) in front of the camera so the face on the card is clearly visible.');
echo html_writer::tag('button', 'Capture ID & Verify', ['id' => 'btn-idcard', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('div');

// Step 4: Processing/Result
echo html_writer::start_tag('div', ['id' => 'step-result', 'class' => 'step']);
echo html_writer::tag('h4', 'Verifying...', ['id' => 'result-title']);
echo html_writer::tag('p', '', ['id' => 'result-desc']);
echo html_writer::tag('button', 'Try Again', ['id' => 'btn-retry', 'class' => 'btn btn-secondary mt-2', 'style' => 'display:none;']);
echo html_writer::tag('a', 'Continue to Activity', ['id' => 'btn-continue', 'href' => $returnurl ? $returnurl : new moodle_url('/mod/assign/view.php', ['id' => $cmid]), 'class' => 'btn btn-success mt-2', 'style' => 'display:none;']);
echo html_writer::end_tag('div');

echo html_writer::end_tag('div'); // kyc-container

// Include face-api.js manually since it's not AMD
echo html_writer::tag('script', '', ['src' => (new moodle_url('/local/netrago/amd/src/face-api.min.js'))->out(false)]);

$config = [
    'cmid' => $cmid,
    'ajaxurl' => (new moodle_url('/local/netrago/ajax_kyc.php'))->out(false)
];

$PAGE->requires->js_call_amd('local_netrago/kyc', 'init', [$config]);

echo $OUTPUT->footer();
