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

$context_data = [
    'activity_name' => format_string($cm->name),
    'course_url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
    'is_summary' => ($userid == 0)
];

if ($userid == 0) {
    // Summary View
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
    $context_data['has_users'] = !empty($users);
    
    if (!empty($users)) {
        $context_data['is_quiz'] = ($cm->modname == 'quiz');
        $users_data = [];
        
        if ($cm->modname == 'quiz') {
            $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
            
            foreach ($users as $u) {
                $attempts = $DB->get_records('quiz_attempts', ['quiz' => $quiz->id, 'userid' => $u->id], 'attempt ASC');
                
                if (empty($attempts)) {
                    $users_data[] = [
                        'email' => s($u->email),
                        'fullname' => fullname($u),
                        'attempt' => '-', 'score_str' => '-', 'submitted' => '-', 'duration' => '-',
                        'trust_score' => '-', 'trust_class' => '',
                        'report_url' => (new moodle_url('/local/netrago/report.php', ['cmid' => $cmid, 'userid' => $u->id]))->out(false),
                        'btn_class' => 'outline-primary',
                        'btn_text' => 'View KYC/Logs',
                        'review_url' => false
                    ];
                    continue;
                }
                
                foreach ($attempts as $att) {
                    $violation_count = $DB->count_records_select('local_netrago_logs', 
                        "userid = ? AND cmid = ? AND timecreated >= ? AND timecreated <= ?", 
                        [$u->id, $cmid, $att->timestart, $att->timefinish ?: time()]);
                        
                    $trust_score = 'High'; $trust_class = 'text-success';
                    if ($violation_count > 0 && $violation_count < 5) { $trust_score = 'Moderate'; $trust_class = 'text-warning'; }
                    else if ($violation_count >= 5) { $trust_score = 'Low'; $trust_class = 'text-danger'; }
                    
                    $duration = ($att->timefinish > 0) ? format_time($att->timefinish - $att->timestart) : 'In progress';
                    $submitted = ($att->timefinish > 0) ? userdate($att->timefinish, '%d %B, %H:%M') : '-';
                    $score_str = ($att->state == 'finished' && $quiz->sumgrades > 0 && isset($att->sumgrades)) 
                        ? round(($att->sumgrades / $quiz->sumgrades) * 100) . '%' : '-';
                        
                    $users_data[] = [
                        'email' => s($u->email),
                        'fullname' => fullname($u),
                        'attempt' => $att->attempt,
                        'score_str' => $score_str,
                        'submitted' => $submitted,
                        'duration' => $duration,
                        'trust_score' => $trust_score,
                        'trust_class' => $trust_class,
                        'report_url' => (new moodle_url('/local/netrago/report.php', ['cmid' => $cmid, 'userid' => $u->id, 'attempt' => $att->attempt]))->out(false),
                        'btn_class' => 'primary',
                        'btn_text' => 'View report',
                        'review_url' => (new moodle_url('/mod/quiz/review.php', ['attempt' => $att->id, 'cmid' => $cmid]))->out(false)
                    ];
                }
            }
        } else {
            foreach ($users as $u) {
                $kyc = $DB->get_record('local_netrago_kyc', ['userid' => $u->id, 'cmid' => $cmid]);
                $violation_count = $DB->count_records('local_netrago_logs', ['userid' => $u->id, 'cmid' => $cmid]);
                
                $users_data[] = [
                    'fullname' => fullname($u),
                    'email' => s($u->email),
                    'kyc_badge' => $kyc ? '<span class="badge badge-success">Verified</span>' : '<span class="badge badge-secondary">Pending</span>',
                    'v_badge' => $violation_count > 0 ? '<span class="badge badge-danger">'.$violation_count.' Events</span>' : '<span class="badge badge-success">Clean</span>',
                    'report_url' => (new moodle_url('/local/netrago/report.php', ['cmid' => $cmid, 'userid' => $u->id]))->out(false)
                ];
            }
        }
        $context_data['users'] = $users_data;
    }
} else {
    // Detailed View
    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    $context_data['student_name'] = fullname($user);
    $context_data['back_url'] = (new moodle_url('/local/netrago/report.php', ['cmid' => $cmid]))->out(false);
    
    $settings = $DB->get_record('local_netrago', ['cmid' => $cmid]);
    $settings_arr = [];
    
    // Check if features are both globally enabled and locally required
    $camera_enabled = get_config('local_netrago', 'allow_camera') ? $settings->requirecamera : 0;
    $screen_enabled = get_config('local_netrago', 'allow_screencapture') ? ($settings->requirescreencapture ?? 0) : 0;
    $fs_enabled = get_config('local_netrago', 'allow_fullscreen') ? $settings->requirefullscreen : 0;
    $focus_enabled = get_config('local_netrago', 'allow_focusloss') ? $settings->disablefocusloss : 0;
    $devtools_enabled = get_config('local_netrago', 'allow_devtools') ? $settings->disabledevtools : 0;
    $copypaste_enabled = get_config('local_netrago', 'allow_copypaste') ? $settings->disablecopypaste : 0;
    
    if ($camera_enabled) $settings_arr[] = 'Camera';
    if ($screen_enabled) $settings_arr[] = 'Screen';
    if ($fs_enabled) $settings_arr[] = 'Fullscreen Enforced';
    if ($focus_enabled) $settings_arr[] = 'Tab Switching Blocked';
    if ($copypaste_enabled) $settings_arr[] = 'Copy-Paste Blocked';
    if ($devtools_enabled) $settings_arr[] = 'DevTools Blocked';
    
    $context_data['proctoring_settings'] = empty($settings_arr) ? 'None' : implode(', ', $settings_arr);
    
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
        
        $log_data = [
            'time_str' => userdate($log->timecreated, '%H:%M'),
            'imagedata' => $log->imagedata,
            'is_susp' => (strpos($log->eventtype, 'violation') !== false || strpos($log->eventtype, 'not found') !== false || strpos($log->eventtype, 'tab_switch') !== false) ? 'suspicious' : '',
            'event_raw' => s($log->eventtype),
            'event_clean' => s(ucwords(str_replace('_', ' ', $log->eventtype)))
        ];
        
        if (empty($log->imagedata)) continue;
        
        if (strpos($log->eventtype, 'Screen') !== false || strpos($log->eventtype, 'screen') !== false || strpos($log->eventtype, 'devtools') !== false || strpos($log->eventtype, 'fullscreen') !== false) {
            $screen_logs[] = $log_data;
        } else {
            $camera_logs[] = $log_data;
        }
    }

    $total_camera = count($camera_logs);
    $faces_found = $total_camera - $suspicious_count;
    $face_presence = $total_camera > 0 ? round(($faces_found / $total_camera) * 100) : 0;
    
    $context_data['face_presence'] = $face_presence;
    $context_data['average_faces'] = ($face_presence > 50 ? 'Single face' : 'No face');
    $context_data['left_test'] = $left_test;
    $context_data['suspicious_count'] = $suspicious_count;
    
    $context_data['camera_logs'] = $camera_logs;
    $context_data['has_camera_logs'] = !empty($camera_logs);
    
    $context_data['screen_logs'] = $screen_logs;
    $context_data['has_screen_logs'] = !empty($screen_logs);
    
    $kyc = $DB->get_record('local_netrago_kyc', ['userid' => $userid, 'cmid' => $cmid]);
    $context_data['reset_url'] = (new moodle_url('/local/netrago/report.php', ['cmid' => $cmid, 'userid' => $userid, 'action' => 'resetkyc', 'sesskey' => sesskey()]))->out(false);
    
    if ($kyc) {
        $context_data['has_kyc'] = true;
        $context_data['kyc_verified_date'] = userdate($kyc->timeverified);
        $context_data['selfie_src'] = (strpos((string)$kyc->selfiedata, 'data:image/') === 0) ? $kyc->selfiedata : '';
        $context_data['ktp_src'] = (strpos((string)$kyc->ktpdata, 'data:image/') === 0) ? $kyc->ktpdata : '';
    } else {
        $context_data['has_kyc'] = false;
    }
}

echo $OUTPUT->render_from_template('local_netrago/report', $context_data);

echo $OUTPUT->footer();
