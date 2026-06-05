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
body { background-color: #f4f6f9; }
#kyc-container { max-width: 700px; margin: 40px auto; text-align: center; border-radius: 12px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.08); background: #ffffff; overflow: hidden; }
.kyc-header { background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white; padding: 25px 20px; border-bottom: 4px solid #004494; }
.kyc-header h2 { margin: 0; font-weight: 700; font-size: 1.8rem; }
.kyc-body { padding: 30px 40px; }
#video-container { position: relative; display: inline-block; border-radius: 12px; overflow: hidden; box-shadow: 0 8px 20px rgba(0,0,0,0.15); border: 4px solid #fff; }
#webcam { transform: scaleX(-1); width: 100%; max-width: 480px; display: block; }
canvas { position: absolute; top: 0; left: 0; transform: scaleX(-1); width: 100%; max-width: 480px; }
.step { display: none; animation: fadeIn 0.4s ease-in-out; }
.step.active { display: block; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.rules-list { list-style: none; padding: 0; margin: 20px 0; text-align: left; }
.rules-list li { padding: 12px 15px; margin-bottom: 10px; background: #f8f9fa; border-left: 4px solid #007bff; border-radius: 4px; font-weight: 500; }
.rules-list.danger li { border-left-color: #dc3545; background: #fff5f5; color: #b02a37; }
.btn-kyc { padding: 12px 30px; font-size: 1.1rem; font-weight: bold; border-radius: 30px; text-transform: uppercase; letter-spacing: 1px; transition: all 0.3s; }
.btn-kyc:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,123,255,0.3); }
.step-icon { font-size: 3rem; color: #007bff; margin-bottom: 15px; }
</style>
";
$CFG->additionalhtmlhead .= $css;

echo $OUTPUT->header();

echo html_writer::start_tag('div', ['id' => 'kyc-container']);

// Header
echo html_writer::start_tag('div', ['class' => 'kyc-header']);
echo html_writer::tag('h2', '<i class="fa fa-shield"></i> Identity Verification (KYC)');
echo html_writer::tag('p', 'NetraGo Secure Proctoring System', ['class' => 'mb-0 mt-1 opacity-75']);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'kyc-body']);

// Step 0: Intro Rules
echo html_writer::start_tag('div', ['id' => 'step-intro', 'class' => 'step active']);
echo html_writer::tag('i', '', ['class' => 'fa fa-lock step-icon']);
echo html_writer::tag('h4', 'NetraGo Verification Rules');
echo html_writer::tag('p', 'This activity is strictly protected by AI Proctoring. To proceed, you must complete the Identity Verification process:', ['class' => 'text-muted']);
echo html_writer::start_tag('ul', ['class' => 'rules-list']);
echo html_writer::tag('li', '<i class="fa fa-camera mr-2 text-primary"></i> Allow camera access in your browser.');
echo html_writer::tag('li', '<i class="fa fa-user mr-2 text-primary"></i> Take a clear selfie of your face.');
echo html_writer::tag('li', '<i class="fa fa-id-card mr-2 text-primary"></i> Capture a valid ID Card (KTP/KTM) that matches your face.');
echo html_writer::end_tag('ul');
echo html_writer::tag('div', html_writer::tag('button', 'I Agree, Start Verification <i class="fa fa-arrow-right ml-2"></i>', ['id' => 'btn-agree-intro', 'class' => 'btn btn-primary btn-kyc']), ['class' => 'text-center mt-4']);
echo html_writer::end_tag('div');

// Step Loading Models
echo html_writer::start_tag('div', ['id' => 'step-loading', 'class' => 'step']);
echo html_writer::tag('div', '', ['class' => 'spinner-border text-primary', 'style' => 'width: 3rem; height: 3rem;', 'role' => 'status']);
echo html_writer::tag('h5', 'Loading AI Engines...', ['class' => 'mt-4 font-weight-bold']);
echo html_writer::tag('p', 'Preparing Face Recognition models. Please wait.', ['class' => 'text-muted']);
echo html_writer::end_tag('div');

// Video container (Shared across steps)
echo html_writer::start_tag('div', ['id' => 'video-container', 'class' => 'my-4 mx-auto', 'style' => 'display:none;']);
echo html_writer::tag('video', '', ['id' => 'webcam', 'autoplay' => true, 'muted' => true, 'playsinline' => true]);
echo html_writer::end_tag('div');

// Step 2: Selfie
echo html_writer::start_tag('div', ['id' => 'step-selfie', 'class' => 'step']);
echo html_writer::tag('h4', '<i class="fa fa-user-circle text-primary mr-2"></i> Step 1: Take a Selfie');
echo html_writer::tag('p', 'Look directly at the camera. Ensure your face is well-lit and not covered.', ['class' => 'text-muted']);
echo html_writer::tag('button', '<i class="fa fa-camera"></i> Capture Selfie', ['id' => 'btn-selfie', 'class' => 'btn btn-primary btn-kyc mt-2']);
echo html_writer::end_tag('div');

// Step 3: ID Card
echo html_writer::start_tag('div', ['id' => 'step-idcard', 'class' => 'step']);
echo html_writer::tag('h4', '<i class="fa fa-id-badge text-primary mr-2"></i> Step 2: Show your ID Card');
echo html_writer::tag('p', 'Hold your ID card (KTP/KTM) in front of the camera so the face on the card is clearly visible to the AI.', ['class' => 'text-muted']);
echo html_writer::tag('button', '<i class="fa fa-search"></i> Capture ID & Verify', ['id' => 'btn-idcard', 'class' => 'btn btn-primary btn-kyc mt-2']);
echo html_writer::end_tag('div');

// Step 4: Processing/Result
echo html_writer::start_tag('div', ['id' => 'step-result', 'class' => 'step']);
echo html_writer::tag('i', '', ['id' => 'result-icon', 'class' => 'fa fa-spinner fa-spin step-icon']);
echo html_writer::tag('h4', 'Verifying Identity...', ['id' => 'result-title']);
echo html_writer::tag('p', 'Comparing your selfie with the ID card.', ['id' => 'result-desc', 'class' => 'text-muted']);
echo html_writer::tag('button', '<i class="fa fa-refresh"></i> Try Again', ['id' => 'btn-retry', 'class' => 'btn btn-danger btn-kyc mt-3', 'style' => 'display:none;']);
echo html_writer::end_tag('div');

// Step 5: Proctoring Rules
echo html_writer::start_tag('div', ['id' => 'step-proctoring-rules', 'class' => 'step']);
echo html_writer::tag('i', '', ['class' => 'fa fa-check-circle step-icon text-success']);
echo html_writer::tag('h4', 'Verification Successful!');
echo html_writer::tag('p', 'During the activity, you will be closely monitored. <strong>Violations will trigger an Auto-Submit.</strong>', ['class' => 'text-muted']);
echo html_writer::start_tag('ul', ['class' => 'rules-list danger']);
echo html_writer::tag('li', '<i class="fa fa-times-circle mr-2"></i> Do NOT leave full-screen mode.');
echo html_writer::tag('li', '<i class="fa fa-times-circle mr-2"></i> Do NOT switch to other tabs or applications.');
echo html_writer::tag('li', '<i class="fa fa-times-circle mr-2"></i> Your face must remain visible at all times.');
echo html_writer::tag('li', '<i class="fa fa-times-circle mr-2"></i> Copy, Paste, and Text Selection are disabled.');
echo html_writer::end_tag('ul');

$fallbackurl = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cmid]);
echo html_writer::tag('div', html_writer::tag('a', '<i class="fa fa-play-circle"></i> I Understand, Start Activity', ['id' => 'btn-continue', 'href' => $returnurl ? $returnurl : $fallbackurl, 'class' => 'btn btn-success btn-kyc']), ['class' => 'text-center mt-4']);
echo html_writer::end_tag('div');

echo html_writer::end_tag('div'); // kyc-body
echo html_writer::end_tag('div'); // kyc-container

// Include face-api.js manually since it's not AMD
echo html_writer::tag('script', '', ['src' => (new moodle_url('/local/netrago/amd/src/face-api.min.js'))->out(false)]);

$config = [
    'cmid' => $cmid,
    'ajaxurl' => (new moodle_url('/local/netrago/ajax_kyc.php'))->out(false)
];

$PAGE->requires->js_call_amd('local_netrago/kyc', 'init', [$config]);

echo $OUTPUT->footer();
