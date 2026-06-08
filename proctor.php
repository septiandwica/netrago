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
    'maxstrikes' => isset($settings->maxstrikes) ? $settings->maxstrikes : 3,
    'ajaxurl' => (new moodle_url('/local/netrago/ajax.php'))->out(false),
    'descriptor' => $descriptor_to_use,
    'attempt_url' => $url
];

$requires_password = false;
if ($cm->modname === 'quiz') {
    $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'password', IGNORE_MISSING);
    if ($quiz && !empty($quiz->password)) {
        $requires_password = true;
    }
}

// No-JS Fallback / Loading Overlay
$warningmsg = get_string('js_required_warning', 'local_netrago');
$css = "
    <style id='netrago-anti-js-bypass'>
        body { margin: 0; padding: 0; background: #f4f6f8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }
        #netrago-preflight-container {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: #f4f6f8; z-index: 9999999; display: flex;
            align-items: center; justify-content: center; overflow-y: auto; padding: 20px;
        }
        .netrago-card {
            background: #fff; width: 100%; max-width: 650px; border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1); padding: 30px; text-align: center;
        }
        .netrago-card h2 { font-size: 24px; margin-bottom: 15px; font-weight: 600; color: #333; }
        .netrago-card p { font-size: 15px; color: #555; margin-bottom: 20px; line-height: 1.5; }
        .netrago-input-group { text-align: left; margin-bottom: 25px; }
        .netrago-input-group label { display: block; font-weight: 600; margin-bottom: 8px; color: #444; }
        .netrago-input { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 16px; box-sizing: border-box; }
        .netrago-btn {
            background: #007bff; color: white; border: none; padding: 12px 24px;
            font-size: 16px; border-radius: 4px; cursor: pointer; width: 100%; font-weight: 600;
        }
        .netrago-btn:hover { background: #0056b3; }
        .netrago-btn:disabled { background: #a0c4ff; cursor: not-allowed; }
        
        .netrago-info-box {
            background: #e9f5ff; border: 1px solid #b8daff; color: #004085;
            padding: 15px; border-radius: 4px; text-align: left; margin-bottom: 25px; font-size: 14px;
        }
        .netrago-info-box ul { margin: 10px 0 0 20px; padding: 0; }
        .netrago-info-box li { margin-bottom: 5px; }
        
        .netrago-step { display: none; }
        .netrago-step.active { display: block; }
        
        .netrago-checkbox-group {
            display: flex; align-items: flex-start; text-align: left; margin-bottom: 25px;
            background: #f8f9fa; padding: 15px; border-radius: 4px; border: 1px solid #dee2e6;
        }
        .netrago-checkbox-group input { margin-top: 4px; margin-right: 12px; transform: scale(1.2); }
        .netrago-checkbox-group label { font-size: 14px; color: #444; line-height: 1.4; cursor: pointer; }
        
        .netrago-spinner {
            border: 4px solid #f3f3f3; border-top: 4px solid #007bff;
            border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite;
            margin: 0 auto 15px auto;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        #netrago-quiz-frame {
            border: none; width: 100vw; height: 100vh; display: none;
        }
    </style>

    <div id='netrago-preflight-container'>
        <div class='netrago-card'>
            <!-- Loading Step -->
            <div id='nf-step-loading' class='netrago-step active'>
                <div class='netrago-spinner' id='nf-loading-spinner'></div>
                <h2 id='nf-loading-text'>Initializing Session...</h2>
                <p id='nf-loading-desc'>Please wait while we prepare your proctoring environment.</p>
                <button id='nf-btn-start-setup' class='netrago-btn' style='margin-top:20px;' onclick='this.disabled=true; this.innerHTML=\"<i class=\\\"fa fa-spinner fa-spin\\\"></i> Starting camera...\"; document.dispatchEvent(new Event(\"netrago_start_clicked\"));'><i class='fa fa-play'></i> Start Setup</button>
            </div>
            
            <!-- KYC Video Container -->
            " . ($requirekyc ? "
            <div id='kyc-video-container' style='display:none; text-align:center; margin-bottom: 20px;'>
                <video id='webcam' autoplay muted playsinline style='width: 100%; max-width: 600px; border-radius: 8px; border: 2px solid #ddd;'></video>
            </div>
            " : "") . "

            <!-- KYC Step 1: Selfie -->
            <div id='nf-step-kyc-selfie' class='netrago-step'>
                <h2>Step 1: Take a Selfie</h2>
                <p>Look directly at the camera. Ensure your face is well-lit and not covered.</p>
                <p id='selfie-error' style='color:#dc3545; font-weight:bold; display:none;'></p>
                <button id='btn-selfie' class='netrago-btn'><i class='fa fa-camera'></i> Capture Selfie</button>
            </div>

            <!-- KYC Step 2: ID Card -->
            <div id='nf-step-kyc-idcard' class='netrago-step'>
                <h2>Step 2: Official ID Card</h2>
                <p>Hold your ID Card (KTP/KTM/SIM) in front of the camera.</p>
                <p id='idcard-error' style='color:#dc3545; font-weight:bold; display:none;'></p>
                <button id='btn-idcard' class='netrago-btn'><i class='fa fa-search'></i> Capture & Verify</button>
            </div>

            <!-- KYC Step 3: Result -->
            <div id='nf-step-kyc-result' class='netrago-step'>
                <div class='netrago-spinner' id='kyc-result-spinner'></div>
                <h2 id='result-title'>Verifying Identity...</h2>
                <p id='result-desc'>Comparing your selfie with the ID card.</p>
                <button id='btn-retry' class='netrago-btn' style='display:none; background:#dc3545;'><i class='fa fa-refresh'></i> Try Again</button>
            </div>

            <!-- Step 1: Password & Info -->
            <div id='nf-step-1' class='netrago-step'>
                <h2>Start attempt</h2>
                " . ($requires_password ? "
                <p>To attempt this quiz you need to know the quiz password.</p>
                <div class='netrago-input-group'>
                    <label for='nf-quiz-password'>Quiz password</label>
                    <input type='password' id='nf-quiz-password' class='netrago-input' placeholder='Click to enter text'>
                </div>
                " : "") . "
                <div class='netrago-info-box'>
                    <strong>Camera and screen tracking is enabled</strong>
                    <ul>
                        <li>To start an attempt you need to provide access to your camera and screen.</li>
                        <li>Snapshots from your camera and screen will be taken during your attempt.</li>
                        <li>A report will be shown to your teacher once you finish your quiz attempt.</li>
                    </ul>
                </div>
                <button id='nf-btn-next-1' class='netrago-btn'>Continue</button>
            </div>

            <!-- Step 2: Screen Share -->
            <div id='nf-step-2' class='netrago-step'>
                <h2>Almost done</h2>
                <p>Now please provide access to your screen.</p>
                <div class='netrago-info-box' style='background: #fff3cd; border-color: #ffeeba; color: #856404;'>
                    <i class='fa fa-warning'></i> You <strong>MUST</strong> select the <strong>Entire Screen</strong> option. Sharing a window or tab is prohibited.
                </div>
                <button id='nf-btn-share-screen' class='netrago-btn'><i class='fa fa-desktop'></i> Allow Share Screen</button>
            </div>

            <!-- Step 3: Consent -->
            <div id='nf-step-3' class='netrago-step'>
                <h2>Do you see yourself and your screen?</h2>
                <div style='display:flex; justify-content:center; gap: 10px; margin-bottom: 15px;'>
                    <video id='nf-preview-screen' autoplay muted playsinline style='width: 48%; border-radius: 4px; border: 1px solid #ccc; background: #000; height: 120px; object-fit: cover;'></video>
                    <video id='nf-preview-camera' autoplay muted playsinline style='width: 48%; border-radius: 4px; border: 1px solid #ccc; background: #000; height: 120px; object-fit: cover;'></video>
                </div>
                <p>Please review the proctoring agreement below.</p>
                <div class='netrago-checkbox-group'>
                    <input type='checkbox' id='nf-consent-checkbox'>
                    <label for='nf-consent-checkbox'>
                        I provide consent to record, process and store the proctoring data, including screenshots of my screen and photos of myself, and share them with my teacher.
                    </label>
                </div>
                <button id='nf-btn-start-attempt' class='netrago-btn' disabled>Start attempt</button>
            </div>

            <!-- Warning Step (Shown briefly before attempt starts) -->
            <div id='nf-step-warning' class='netrago-step'>
                <h2 class='text-danger'>Attention!</h2>
                <p style='font-size: 18px; font-weight: bold;'>Do NOT disable your camera or screen during your test as this may affect your test results.</p>
                <div class='netrago-spinner' style='margin-top: 30px;'></div>
                <p>Starting quiz...</p>
            </div>
        </div>
    </div>
    
    <!-- Hidden form to submit password to Moodle native startattempt.php -->
    <form id='nf-hidden-start-form' method='POST' action='" . new moodle_url('/mod/quiz/startattempt.php') . "' target='netrago-quiz-iframe'>
        <input type='hidden' name='cmid' value='{$cmid}'>
        <input type='hidden' name='sesskey' value='" . sesskey() . "'>
        <input type='hidden' name='quizpassword' id='nf-hidden-password' value=''>
        <input type='hidden' name='_qf__mod_quiz_pre_attempt_form' value='1'>
        <input type='hidden' name='submitbutton' value='Start attempt'>
    </form>
";
$CFG->additionalhtmlhead .= $css;

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

// The iFrame container
echo '<iframe id="netrago-quiz-frame" name="netrago-quiz-iframe" allow="camera *; microphone *; display-capture *; fullscreen *"></iframe>';

echo $OUTPUT->footer();
