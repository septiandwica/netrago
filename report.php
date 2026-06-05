<?php
/**
 * NetraGo Universal Proctoring Report Viewer
 *
 * @package    local_netrago
 * @copyright  2026 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);

require_login();

// Validate user has access to this course module.
$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

// Only teachers/managers can view reports.
require_capability('moodle/course:manageactivities', $context);

$PAGE->set_url('/local/netrago/report.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
$PAGE->set_title(get_string('report_title', 'local_netrago', $cm->name));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report_title', 'local_netrago', $cm->name));

// Fetch all logs for this activity, grouped by user.
$logs = $DB->get_records('local_netrago_logs', ['cmid' => $cmid], 'userid ASC, timecreated ASC');

if (empty($logs)) {
    echo $OUTPUT->notification(get_string('no_suspicious_events', 'local_netrago'), 'info');
} else {
    // Group logs by user
    $userlogs = [];
    foreach ($logs as $log) {
        if (!isset($userlogs[$log->userid])) {
            $userlogs[$log->userid] = [];
        }
        $userlogs[$log->userid][] = $log;
    }

    echo html_writer::start_tag('div', ['class' => 'accordion', 'id' => 'netragoReportAccordion']);

    foreach ($userlogs as $userid => $events) {
        $user = $DB->get_record('user', ['id' => $userid]);
        $fullname = fullname($user);
        
        echo html_writer::start_tag('div', ['class' => 'card mb-2']);
        echo html_writer::start_tag('div', ['class' => 'card-header d-flex justify-content-between align-items-center', 'id' => 'heading' . $userid]);
        echo html_writer::tag('h2', 
            html_writer::tag('button', $fullname . ' (' . count($events) . ' events)', [
                'class' => 'btn btn-link btn-block text-left collapsed',
                'type' => 'button',
                'data-toggle' => 'collapse',
                'data-target' => '#collapse' . $userid,
                'aria-expanded' => 'false',
                'aria-controls' => 'collapse' . $userid
            ]), 
        ['class' => 'mb-0']);
        echo html_writer::end_tag('div'); // card-header

        echo html_writer::start_tag('div', [
            'id' => 'collapse' . $userid, 
            'class' => 'collapse', 
            'aria-labelledby' => 'heading' . $userid, 
            'data-parent' => '#netragoReportAccordion'
        ]);
        echo html_writer::start_tag('div', ['class' => 'card-body']);

        echo html_writer::start_tag('div', ['class' => 'row']);
        foreach ($events as $log) {
            $eventname = get_string('event_' . $log->eventtype, 'local_netrago');
            if (strpos($eventname, '[[') !== false) {
                $eventname = $log->eventtype; 
            }
            $time = userdate($log->timecreated, get_string('strftimedatetimeshort', 'langconfig'));

            echo html_writer::start_tag('div', ['class' => 'col-md-3 mb-4']);
            echo html_writer::start_tag('div', ['class' => 'card shadow-sm h-100']);
            
            if (!empty($log->imagedata)) {
                echo html_writer::empty_tag('img', [
                    'src' => $log->imagedata, 
                    'class' => 'card-img-top', 
                    'style' => 'object-fit: cover; height: 150px;'
                ]);
            } else {
                echo html_writer::start_tag('div', ['class' => 'card-img-top bg-light d-flex align-items-center justify-content-center', 'style' => 'height: 150px;']);
                echo html_writer::tag('i', '', ['class' => 'fa fa-exclamation-triangle text-warning fa-2x']);
                echo html_writer::end_tag('div');
            }

            echo html_writer::start_tag('div', ['class' => 'card-body p-2']);
            echo html_writer::tag('h6', $eventname, ['class' => 'card-title text-danger m-0', 'style' => 'font-size: 14px;']);
            echo html_writer::tag('p', $time, ['class' => 'card-text text-muted small m-0']);
            echo html_writer::end_tag('div'); // card-body

            echo html_writer::end_tag('div'); // card
            echo html_writer::end_tag('div'); // col
        }
        echo html_writer::end_tag('div'); // row
        
        echo html_writer::end_tag('div'); // card-body collapse
        echo html_writer::end_tag('div'); // collapse inner
        echo html_writer::end_tag('div'); // card
    }

    echo html_writer::end_tag('div'); // accordion
}

echo html_writer::start_tag('div', ['class' => 'mt-4']);
echo html_writer::link(new moodle_url('/course/mod.php', ['update' => $cm->id, 'sesskey' => sesskey()]), get_string('backtoactivity', 'local_netrago'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
