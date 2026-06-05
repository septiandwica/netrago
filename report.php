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

require_login();

$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_capability('moodle/course:manageactivities', $context);

$PAGE->set_url('/local/netrago/report.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
$PAGE->set_title('NetraGo Proctoring Report');
$PAGE->set_heading($course->fullname . ' - Proctoring Report');

$css = "
<style>
.netrago-report-container { background: #fff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 20px; }
.event-row { border-left: 4px solid #007bff; margin-bottom: 15px; padding: 10px 15px; background: #f8f9fa; }
.event-danger { border-left-color: #dc3545; background: #fff5f5; }
.event-warning { border-left-color: #ffc107; background: #fffdf5; }
.event-img { max-width: 320px; border-radius: 4px; margin-top: 10px; border: 1px solid #ddd; }
</style>
";
$CFG->additionalhtmlhead .= $css;

echo $OUTPUT->header();

echo html_writer::start_tag('div', ['class' => 'netrago-report-container']);
echo html_writer::tag('h2', 'NetraGo Proctoring Report');
echo html_writer::tag('p', 'Activity: ' . format_string($cm->name));

echo html_writer::tag('a', 'Back to Activity', ['href' => new moodle_url('/course/view.php', ['id' => $course->id]), 'class' => 'btn btn-secondary mb-4']);

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
        $table->head = ['Student', 'Email', 'KYC Status', 'Violations Logged', 'Action'];
        
        foreach ($users as $u) {
            $kyc = $DB->get_record('local_netrago_kyc', ['userid' => $u->id, 'cmid' => $cmid]);
            $violation_count = $DB->count_records('local_netrago_logs', ['userid' => $u->id, 'cmid' => $cmid]);
            
            $kyc_badge = $kyc ? '<span class="badge badge-success">Verified</span>' : '<span class="badge badge-secondary">Pending</span>';
            $v_badge = $violation_count > 0 ? '<span class="badge badge-danger">'.$violation_count.' Events</span>' : '<span class="badge badge-success">Clean</span>';
            
            $btn = html_writer::link(new moodle_url('/local/netrago/report.php', ['cmid' => $cmid, 'userid' => $u->id]), 'View Details', ['class' => 'btn btn-sm btn-primary']);
            
            $table->data[] = [
                fullname($u),
                $u->email,
                $kyc_badge,
                $v_badge,
                $btn
            ];
        }
        echo html_writer::table($table);
    }
} else {
    // Detailed View for a specific user
    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    
    echo html_writer::tag('h3', 'Report for: ' . fullname($user), ['class' => 'mt-4']);
    echo html_writer::link(new moodle_url('/local/netrago/report.php', ['cmid' => $cmid]), '&laquo; Back to all students', ['class' => 'mb-4 d-inline-block']);
    
    // Show KYC
    $kyc = $DB->get_record('local_netrago_kyc', ['userid' => $userid, 'cmid' => $cmid]);
    if ($kyc) {
        echo html_writer::start_tag('div', ['class' => 'card mb-4']);
        echo html_writer::start_tag('div', ['class' => 'card-header bg-success text-white']);
        echo "KYC Identity Verified (" . userdate($kyc->timeverified) . ")";
        echo html_writer::end_tag('div');
        echo html_writer::start_tag('div', ['class' => 'card-body row']);
        
        echo html_writer::start_tag('div', ['class' => 'col-md-6']);
        echo html_writer::tag('h5', 'Live Selfie');
        echo html_writer::tag('img', '', ['src' => $kyc->selfiedata, 'class' => 'img-fluid border rounded', 'style' => 'max-width:300px;']);
        echo html_writer::end_tag('div');
        
        echo html_writer::start_tag('div', ['class' => 'col-md-6']);
        echo html_writer::tag('h5', 'ID Card (KTP/KTM)');
        echo html_writer::tag('img', '', ['src' => $kyc->ktpdata, 'class' => 'img-fluid border rounded', 'style' => 'max-width:300px;']);
        echo html_writer::end_tag('div');
        
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    } else {
        echo $OUTPUT->notification('User has not completed KYC for this activity.', 'warning');
    }
    
    // Show Logs
    $logs = $DB->get_records('local_netrago_logs', ['userid' => $userid, 'cmid' => $cmid], 'timecreated DESC');
    if (empty($logs)) {
        echo $OUTPUT->notification('No suspicious events logged for this user.', 'success');
    } else {
        echo html_writer::tag('h4', 'Proctoring Timeline', ['class' => 'mt-4']);
        foreach ($logs as $log) {
            $class = 'event-row';
            if (strpos($log->eventtype, 'violation') !== false || strpos($log->eventtype, 'Face not found') !== false || strpos($log->eventtype, 'Unrecognized') !== false) {
                $class .= ' event-danger';
            } else if (strpos($log->eventtype, 'snapshot') === false) {
                $class .= ' event-warning';
            }
            
            echo html_writer::start_tag('div', ['class' => $class]);
            echo html_writer::tag('strong', userdate($log->timecreated) . ' - ');
            echo html_writer::tag('span', $log->eventtype);
            
            if (!empty($log->imagedata)) {
                echo '<br>';
                echo html_writer::tag('img', '', ['src' => $log->imagedata, 'class' => 'event-img']);
            }
            echo html_writer::end_tag('div');
        }
    }
}

echo html_writer::end_tag('div'); // container

echo $OUTPUT->footer();
