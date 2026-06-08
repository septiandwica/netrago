<?php
/**
 * NetraGo Proctoring Report Page
 *
 * @package    local_netrago
 * @copyright  2026 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT); // If 0, show all users
$attempt_num = optional_param('attempt', 0, PARAM_INT);

require_login();

$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_capability('moodle/course:manageactivities', $context);

$action = optional_param('action', '', PARAM_ALPHA);
if ($action == 'resetkyc' && $userid > 0) {
    require_sesskey();
    $DB->delete_records('local_netrago_kyc', ['userid' => $userid, 'cmid' => $cmid]);
    $DB->delete_records('local_netrago_kyc_attempts', ['userid' => $userid, 'cmid' => $cmid]);
    $DB->delete_records('local_netrago_logs', ['userid' => $userid, 'cmid' => $cmid]);
    redirect(new moodle_url('/local/netrago/report.php', ['cmid' => $cmid, 'userid' => $userid]), 'KYC data has been reset for this user. They will need to complete verification again.', null, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->set_url('/local/netrago/report.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
$PAGE->set_title('NetraGo Proctoring Report');
$PAGE->set_heading($course->fullname . ' - Proctoring Report');

$css = "
<style>
.netrago-report-container { background: #f8f9fa; border-radius: 8px; padding: 20px; }
.netrago-dashboard-card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 15px; text-align: center; }
.netrago-dashboard-card h3 { margin: 0; font-size: 2rem; font-weight: bold; }
.text-danger { color: #dc3545 !important; }
.text-warning { color: #ffc107 !important; }
.text-info { color: #17a2b8 !important; }
.event-card { background: #fff; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid #17a2b8; height: 100%; display: flex; flex-direction: column; }
.event-card.event-danger { border-top-color: #dc3545; background: #fff5f5; }
.event-card.event-warning { border-top-color: #ffc107; background: #fffdf5; }
.event-card .card-body { padding: 15px; flex-grow: 1; }
.event-card .event-time { font-size: 0.85em; color: #6c757d; margin-bottom: 5px; display: block; }
.event-img-wrapper { width: 100%; height: 150px; overflow: hidden; background: #eee; border-radius: 4px; margin-top: 10px; }
.event-img-wrapper img { width: 100%; height: 100%; object-fit: cover; }
.kyc-img { width: 100%; height: 200px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; }
</style>
";
$CFG->additionalhtmlhead .= $css;

echo $OUTPUT->header();

echo html_writer::start_tag('div', ['class' => 'netrago-report-container']);
echo html_writer::tag('h2', 'NetraGo Proctoring Report');
echo html_writer::tag('p', 'Activity: ' . format_string($cm->name));

echo html_writer::tag('a', '&laquo; Back to Activity', ['href' => new moodle_url('/course/view.php', ['id' => $course->id]), 'class' => 'btn btn-secondary mb-4']);

if ($userid == 0) {
    // Summary View: List all users who have logs or KYC for this CM
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
            FROM {user} u
            JOIN {local_netrago_logs} l ON l.userid = u.id
            WHERE l.cmid = ?
            UNION
            SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
            FROM {user} u
            JOIN {local_netrago_kyc} k ON k.userid = u.id
            WHERE k.cmid = ?";
            
    $users = $DB->get_records_sql($sql, [$cmid, $cmid]);

    if (empty($users)) {
        echo $OUTPUT->notification('No proctoring data or KYC records found for this activity yet.', 'info');
    } else {
        $table = new html_table();
        
        if ($cm->modname == 'quiz') {
            $table->head = ['Email', 'Name', 'Attempt', 'Score', 'Submitted', 'Duration', 'Trust score', 'Proctoring report', 'Results'];
            
            // Get Quiz Data
            $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
            
            foreach ($users as $u) {
                // Get attempts
                $attempts = $DB->get_records('quiz_attempts', ['quiz' => $quiz->id, 'userid' => $u->id], 'attempt ASC');
                
                if (empty($attempts)) {
                    $btn = html_writer::link(new moodle_url('/local/netrago/report.php', ['cmid' => $cmid, 'userid' => $u->id]), 'View KYC/Logs', ['class' => 'btn btn-sm btn-outline-primary']);
                    $table->data[] = [
                        s($u->email),
                        fullname($u),
                        '-', '-', '-', '-', '-',
                        $btn,
                        '-'
                    ];
                    continue;
                }
                
                foreach ($attempts as $att) {
                    $violation_count = $DB->count_records_select('local_netrago_logs', 
                        "userid = ? AND cmid = ? AND timecreated >= ? AND timecreated <= ?", 
                        [$u->id, $cmid, $att->timestart, $att->timefinish ?: time()]);
                        
                    // Trust score calculation
                    $trust_score = 'High';
                    $trust_class = 'text-success';
                    if ($violation_count > 0 && $violation_count < 5) { $trust_score = 'Moderate'; $trust_class = 'text-warning'; }
                    else if ($violation_count >= 5) { $trust_score = 'Low'; $trust_class = 'text-danger'; }
                    
                    // Duration
                    $duration = ($att->timefinish > 0) ? format_time($att->timefinish - $att->timestart) : 'In progress';
                    $submitted = ($att->timefinish > 0) ? userdate($att->timefinish, '%d %B, %H:%M') : '-';
                    
                    // Score
                    $score_str = ($att->state == 'finished' && $quiz->sumgrades > 0 && isset($att->sumgrades)) 
                        ? round(($att->sumgrades / $quiz->sumgrades) * 100) . '%' 
                        : '-';
                        
                    $btn = html_writer::link(new moodle_url('/local/netrago/report.php', ['cmid' => $cmid, 'userid' => $u->id, 'attempt' => $att->attempt]), 'View report', ['class' => 'btn btn-sm btn-primary']);
                    
                    // Results link (Review Attempt in Moodle)
                    $review_url = new moodle_url('/mod/quiz/review.php', ['attempt' => $att->id, 'cmid' => $cmid]);
                    $review_link = html_writer::link($review_url, 'Review', ['target' => '_blank']);
                    
                    $table->data[] = [
                        s($u->email),
                        fullname($u),
                        $att->attempt,
                        $score_str,
                        $submitted,
                        $duration,
                        "<span class='{$trust_class}'><strong>{$trust_score}</strong></span>",
                        $btn,
                        $review_link
                    ];
                }
            }
        } else {
            // Standard generic table for non-quiz modules
            $table->head = ['Student', 'Email', 'KYC Status', 'Violations Logged', 'Action'];
            
            foreach ($users as $u) {
                $kyc = $DB->get_record('local_netrago_kyc', ['userid' => $u->id, 'cmid' => $cmid]);
                $violation_count = $DB->count_records('local_netrago_logs', ['userid' => $u->id, 'cmid' => $cmid]);
                
                $kyc_badge = $kyc ? '<span class="badge badge-success">Verified</span>' : '<span class="badge badge-secondary">Pending</span>';
                $v_badge = $violation_count > 0 ? '<span class="badge badge-danger">'.$violation_count.' Events</span>' : '<span class="badge badge-success">Clean</span>';
                
                $btn = html_writer::link(new moodle_url('/local/netrago/report.php', ['cmid' => $cmid, 'userid' => $u->id]), 'View Details', ['class' => 'btn btn-sm btn-primary']);
                
                $table->data[] = [
                    fullname($u),
                    s($u->email),
                    $kyc_badge,
                    $v_badge,
                    $btn
                ];
            }
        }
        
        echo html_writer::table($table);
    }
} else {
    // Detailed View for a specific user
    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    
    echo html_writer::tag('h3', 'Proctoring Report: ' . fullname($user), ['class' => 'mt-2']);
    echo html_writer::link(new moodle_url('/local/netrago/report.php', ['cmid' => $cmid]), '&laquo; Back to all students', ['class' => 'mb-4 d-inline-block']);
    
    // Fetch Settings
    $settings = $DB->get_record('local_netrago', ['cmid' => $cmid]);
    $settings_arr = [];
    if ($settings->requirecamera) $settings_arr[] = 'Camera';
    if ($settings->requirescreencapture ?? 0) $settings_arr[] = 'Screen';
    if ($settings->requirefullscreen || $settings->disablefocusloss || $settings->disabledevtools) $settings_arr[] = 'Forced Tracking';
    
    // Process Logs for Attempt
    if ($attempt_num > 0 && $cm->modname == 'quiz') {
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
        $attempt = $DB->get_record('quiz_attempts', ['quiz' => $quiz->id, 'userid' => $userid, 'attempt' => $attempt_num]);
        if ($attempt) {
            $timestart = $attempt->timestart;
            $timefinish = $attempt->timefinish ?: time();
            $logs = $DB->get_records_select('local_netrago_logs', "userid = ? AND cmid = ? AND timecreated >= ? AND timecreated <= ?", [$userid, $cmid, $timestart, $timefinish], 'timecreated ASC');
        } else {
            $logs = [];
        }
    } else {
        $logs = $DB->get_records('local_netrago_logs', ['userid' => $userid, 'cmid' => $cmid], 'timecreated ASC');
    }
    
    $suspicious_count = 0;
    $camera_logs = [];
    $screen_logs = [];
    $left_test = 0;
    
    foreach ($logs as $log) {
        if (strpos($log->eventtype, 'Face not found') !== false || strpos($log->eventtype, 'violation') !== false || strpos($log->eventtype, 'Unrecognized') !== false) {
            $suspicious_count++;
        }
        if (strpos($log->eventtype, 'tab_switch') !== false || strpos($log->eventtype, 'focus_loss') !== false || strpos($log->eventtype, 'visibility') !== false) {
            $left_test++;
        }
        
        if (strpos($log->eventtype, 'Screen') !== false || strpos($log->eventtype, 'screen') !== false || strpos($log->eventtype, 'devtools') !== false || strpos($log->eventtype, 'fullscreen') !== false) {
            $screen_logs[] = $log;
        } else {
            $camera_logs[] = $log; // Default to camera
        }
    }

    $total_camera = count($camera_logs);
    $faces_found = $total_camera - $suspicious_count; // Rough estimate
    $face_presence = $total_camera > 0 ? round(($faces_found / $total_camera) * 100) : 0;
    
    // Info Box
    echo '<div class="card mb-4"><div class="card-body">';
    echo '<p><strong title="The active tracking features enforced during this attempt">Proctoring settings <i class="fa fa-question-circle text-info"></i>:</strong> Activity, ' . implode(', ', $settings_arr) . '</p>';
    echo '<p><strong title="Percentage of camera snapshots where a human face was successfully detected">Face presence <i class="fa fa-question-circle text-info"></i>:</strong> ' . $face_presence . '%</p>';
    echo '<p><strong title="Average number of faces detected in the camera frames. Indicates if multiple people were present.">Average faces per frame <i class="fa fa-question-circle text-info"></i>:</strong> ' . ($face_presence > 50 ? 'Single face' : 'No face') . '</p>';
    echo '<p><strong title="Number of times the student switched tabs, minimized the browser, or clicked outside the quiz window.">Left test <i class="fa fa-question-circle text-info"></i>:</strong> ' . $left_test . '</p>';
    echo '<p><strong title="Number of camera snapshots containing violations (e.g. face not matching KYC identity, multiple faces, or no face). Does not include screen tab switches.">Screenshots <i class="fa fa-question-circle text-info"></i>:</strong> ' . $suspicious_count . ' suspicious</p>';
    echo '<p class="text-muted small mt-3"><i class="fa fa-info-circle"></i> Camera tracking and Screen tracking are displayed in chronological timelines below.</p>';
    echo '</div></div>';
    
    // Timelines CSS
    echo '<style>
        .timeline-row { display: flex; overflow-x: auto; padding-bottom: 15px; margin-bottom: 30px; gap: 15px; }
        .timeline-item { min-width: 150px; text-align: center; position: relative; }
        .timeline-time { font-size: 14px; color: #555; margin-bottom: 5px; display: block; font-weight: bold; }
        .timeline-img { width: 150px; height: 100px; object-fit: cover; border-radius: 4px; border: 1px solid #ccc; cursor: pointer; }
        .timeline-img.suspicious { border: 2px solid #dc3545; }
        .timeline-pill { position: absolute; bottom: 5px; left: 5px; right: 5px; background: rgba(0,0,0,0.7); color: white; font-size: 11px; padding: 3px 5px; border-radius: 3px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; pointer-events: none; }
        .timeline-pill.suspicious { background: #dc3545; font-weight: bold; }
        #netrago-lightbox { display: none; position: fixed; z-index: 9999999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.85); text-align: center; }
        #netrago-lightbox-img { margin: auto; display: inline-block; max-width: 90%; max-height: 90vh; margin-top: 5vh; border-radius: 4px; box-shadow: 0 5px 25px rgba(0,0,0,0.5); }
        #netrago-lightbox-close { position: absolute; top: 20px; right: 40px; color: #fff; font-size: 40px; font-weight: bold; cursor: pointer; }
    </style>';
    
    // Lightbox HTML & JS
    echo '<div id="netrago-lightbox" onclick="this.style.display=\'none\'">
            <span id="netrago-lightbox-close">&times;</span>
            <img id="netrago-lightbox-img">
          </div>';
    echo '<script>
            function showNetragoPreview(src) {
                document.getElementById("netrago-lightbox-img").src = src;
                document.getElementById("netrago-lightbox").style.display = "block";
            }
          </script>';
    
    // Camera Tracking Timeline
    echo html_writer::tag('h4', 'Camera tracking');
    echo '<div class="timeline-row">';
    if (empty($camera_logs)) {
        echo '<p class="text-muted ml-3">No camera data recorded.</p>';
    } else {
        foreach ($camera_logs as $log) {
            if (empty($log->imagedata)) continue;
            $is_susp = (strpos($log->eventtype, 'violation') !== false || strpos($log->eventtype, 'not found') !== false) ? 'suspicious' : '';
            $time_str = userdate($log->timecreated, '%H:%M');
            echo '<div class="timeline-item">';
            echo "<span class='timeline-time'>{$time_str}</span>";
            echo "<img src='{$log->imagedata}' class='timeline-img {$is_susp}' onclick='showNetragoPreview(this.src)' title='" . s($log->eventtype) . "'>";
            echo "<div class='timeline-pill {$is_susp}' title='" . s($log->eventtype) . "'>" . s(ucwords(str_replace('_', ' ', $log->eventtype))) . "</div>";
            echo '</div>';
        }
    }
    echo '</div>';
    
    // Screen Tracking Timeline
    echo html_writer::tag('h4', 'Screen tracking');
    echo '<div class="timeline-row">';
    if (empty($screen_logs)) {
        echo '<p class="text-muted ml-3">No screen data recorded.</p>';
    } else {
        foreach ($screen_logs as $log) {
            if (empty($log->imagedata)) continue;
            $is_susp = (strpos($log->eventtype, 'violation') !== false || strpos($log->eventtype, 'tab_switch') !== false) ? 'suspicious' : '';
            $time_str = userdate($log->timecreated, '%H:%M');
            echo '<div class="timeline-item">';
            echo "<span class='timeline-time'>{$time_str}</span>";
            echo "<img src='{$log->imagedata}' class='timeline-img {$is_susp}' onclick='showNetragoPreview(this.src)' title='" . s($log->eventtype) . "'>";
            echo "<div class='timeline-pill {$is_susp}' title='" . s($log->eventtype) . "'>" . s(ucwords(str_replace('_', ' ', $log->eventtype))) . "</div>";
            echo '</div>';
        }
    }
    echo '</div>';
    
    // Show KYC Details and Reset
    echo html_writer::tag('h4', 'Identity Verification (KYC)', ['class' => 'mt-4 border-top pt-4']);
    
    $kyc = $DB->get_record('local_netrago_kyc', ['userid' => $userid, 'cmid' => $cmid]);
    
    $reset_url = new moodle_url('/local/netrago/report.php', ['cmid' => $cmid, 'userid' => $userid, 'action' => 'resetkyc', 'sesskey' => sesskey()]);
    echo html_writer::link($reset_url, '<i class="fa fa-refresh"></i> Reset KYC Verification', ['class' => 'btn btn-sm btn-danger mb-4', 'onclick' => 'return confirm("Are you sure you want to reset KYC for this user? They will have to retake their selfie and ID.");']);
    
    if ($kyc) {
        echo html_writer::start_tag('div', ['class' => 'card mb-4']);
        echo html_writer::start_tag('div', ['class' => 'card-header bg-success text-white']);
        echo "<i class='fa fa-check-circle'></i> KYC Identity Verified (" . userdate($kyc->timeverified) . ")";
        echo html_writer::end_tag('div');
        echo html_writer::start_tag('div', ['class' => 'card-body row']);
        
        echo html_writer::start_tag('div', ['class' => 'col-md-6 text-center']);
        echo html_writer::tag('h5', 'Live Selfie');
        $selfie_src = (strpos((string)$kyc->selfiedata, 'data:image/') === 0) ? $kyc->selfiedata : '';
        echo html_writer::tag('img', '', ['src' => $selfie_src, 'class' => 'kyc-img shadow-sm']);
        echo html_writer::end_tag('div');

        echo html_writer::start_tag('div', ['class' => 'col-md-6 text-center']);
        echo html_writer::tag('h5', 'Official ID Card (KTP/KTM/SIM)');
        $ktp_src = (strpos((string)$kyc->ktpdata, 'data:image/') === 0) ? $kyc->ktpdata : '';
        echo html_writer::tag('img', '', ['src' => $ktp_src, 'class' => 'kyc-img shadow-sm']);
        echo html_writer::end_tag('div');
        
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    } else {
        echo $OUTPUT->notification('User has not completed KYC for this activity.', 'warning');
    }
}

echo html_writer::end_tag('div'); // container

echo $OUTPUT->footer();
