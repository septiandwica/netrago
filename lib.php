<?php
/**
 * NetraGo core library functions.
 *
 * @package    local_netrago
 * @copyright  2026 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Injects NetraGo settings into all activity module configuration forms.
 *
 * @param moodleform_mod $formwrapper The moodle quickforms wrapper object.
 * @param MoodleQuickForm $mform The actual form object.
 */
function local_netrago_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB;

    // Check if plugin is globally enabled
    if (!get_config('local_netrago', 'enable_plugin')) {
        return;
    }

    $mform->addElement('header', 'netragoheader', get_string('netragoproctoring', 'local_netrago'));

    if (get_config('local_netrago', 'allow_camera')) {
        $mform->addElement('selectyesno', 'netrago_requirecamera', get_string('requirecamera', 'local_netrago'));
        $mform->addHelpButton('netrago_requirecamera', 'requirecamera', 'local_netrago');
        $mform->setDefault('netrago_requirecamera', 0);
    }

    if (get_config('local_netrago', 'allow_fullscreen')) {
        $mform->addElement('selectyesno', 'netrago_requirefullscreen', get_string('requirefullscreen', 'local_netrago'));
        $mform->addHelpButton('netrago_requirefullscreen', 'requirefullscreen', 'local_netrago');
        $mform->setDefault('netrago_requirefullscreen', 0);
    }

    if (get_config('local_netrago', 'allow_screencapture')) {
        $mform->addElement('selectyesno', 'netrago_requirescreencapture', get_string('requirescreencapture', 'local_netrago'));
        $mform->addHelpButton('netrago_requirescreencapture', 'requirescreencapture', 'local_netrago');
        $mform->setDefault('netrago_requirescreencapture', 0);
    }

    if (get_config('local_netrago', 'allow_copypaste')) {
        $mform->addElement('selectyesno', 'netrago_disablecopypaste', get_string('disablecopypaste', 'local_netrago'));
        $mform->addHelpButton('netrago_disablecopypaste', 'disablecopypaste', 'local_netrago');
        $mform->setDefault('netrago_disablecopypaste', 0);
    }

    if (get_config('local_netrago', 'allow_focusloss')) {
        $mform->addElement('selectyesno', 'netrago_disablefocusloss', get_string('disablefocusloss', 'local_netrago'));
        $mform->addHelpButton('netrago_disablefocusloss', 'disablefocusloss', 'local_netrago');
        $mform->setDefault('netrago_disablefocusloss', 0);
    }

    if (get_config('local_netrago', 'allow_devtools')) {
        $mform->addElement('selectyesno', 'netrago_disabledevtools', get_string('disabledevtools', 'local_netrago'));
        $mform->addHelpButton('netrago_disabledevtools', 'disabledevtools', 'local_netrago');
        $mform->setDefault('netrago_disabledevtools', 0);
    }

    // If editing an existing module, load the current settings.
    $cm = $formwrapper->get_coursemodule();
    if ($cm && $cm->id) {
        $settings = $DB->get_record('local_netrago', ['cmid' => $cm->id]);
        if ($settings) {
            $mform->setDefault('netrago_requirecamera', $settings->requirecamera);
            $mform->setDefault('netrago_requirefullscreen', $settings->requirefullscreen);
            $mform->setDefault('netrago_requirescreencapture', $settings->requirescreencapture ?? 0);
            $mform->setDefault('netrago_disablecopypaste', $settings->disablecopypaste);
            $mform->setDefault('netrago_disablefocusloss', $settings->disablefocusloss ?? 0);
            $mform->setDefault('netrago_disabledevtools', $settings->disabledevtools ?? 0);
        }
    }
}

/**
 * Saves NetraGo settings when an activity module is saved.
 *
 * @param stdClass $data The data submitted from the form.
 * @param stdClass $course The course object.
 */
function local_netrago_coursemodule_edit_post_actions($data, $course) {
    global $DB;

    // If we don't have a coursemodule ID, we can't save. This hook is called after the CM is created.
    if (!isset($data->coursemodule)) {
        return $data;
    }

    $cmid = $data->coursemodule;
    $settings = $DB->get_record('local_netrago', ['cmid' => $cmid]);

    $requirecamera = isset($data->netrago_requirecamera) ? $data->netrago_requirecamera : 0;
    $requirefullscreen = isset($data->netrago_requirefullscreen) ? $data->netrago_requirefullscreen : 0;
    $requirescreencapture = isset($data->netrago_requirescreencapture) ? $data->netrago_requirescreencapture : 0;
    $disablecopypaste = isset($data->netrago_disablecopypaste) ? $data->netrago_disablecopypaste : 0;
    $disablefocusloss = isset($data->netrago_disablefocusloss) ? $data->netrago_disablefocusloss : 0;
    $disabledevtools = isset($data->netrago_disabledevtools) ? $data->netrago_disabledevtools : 0;

    if ($requirecamera || $requirefullscreen || $requirescreencapture || $disablecopypaste || $disablefocusloss || $disabledevtools) {
        $record = new stdClass();
        $record->cmid = $cmid;
        $record->requirecamera = $requirecamera;
        $record->requirefullscreen = $requirefullscreen;
        $record->requirescreencapture = $requirescreencapture;
        $record->disablecopypaste = $disablecopypaste;
        $record->disablefocusloss = $disablefocusloss;
        $record->disabledevtools = $disabledevtools;

        if ($settings) {
            $record->id = $settings->id;
            $DB->update_record('local_netrago', $record);
        } else {
            $DB->insert_record('local_netrago', $record);
        }
    } else if ($settings) {
        $DB->delete_records('local_netrago', ['id' => $settings->id]);
    }

    return $data;
}

/**
 * Injects NetraGo Proctoring Javascript on relevant activity pages.
 * We use extend_navigation as it is a standard Moodle callback that runs on every page.
 */
function local_netrago_extend_navigation(global_navigation $nav) {
    global $PAGE, $USER, $DB, $CFG;

    // Check global enable (default to 1 if not set in db yet)
    $enabled = get_config('local_netrago', 'enable_plugin');
    if ($enabled === false) {
        $enabled = 1;
    }
    if (!$enabled) {
        return;
    }

    // Ignore if not logged in or is guest
    if (!isloggedin() || isguestuser()) {
        return;
    }

    $urlpath = '';
    if ($PAGE->url) {
        $urlpath = $PAGE->url->out_as_local_url(false);
    } else {
        $urlpath = $_SERVER['REQUEST_URI'] ?? '';
    }

    // Inject into Course Grader Report (Course Gradebook)
    if (strpos($urlpath, '/grade/report/grader/index.php') !== false) {
        $courseid = optional_param('id', 0, PARAM_INT);
        if ($courseid && has_capability('moodle/course:manageactivities', context_course::instance($courseid))) {
            $sql = "SELECT l.userid, COUNT(l.id) as vcount 
                    FROM {local_netrago_logs} l
                    JOIN {course_modules} cm ON cm.id = l.cmid
                    WHERE cm.course = ?
                    GROUP BY l.userid";
            $violators = $DB->get_records_sql($sql, [$courseid]);
            
            if ($violators) {
                $violator_data = [];
                foreach ($violators as $v) {
                    $violator_data[$v->userid] = $v->vcount;
                }
                $js = "
                    document.addEventListener('DOMContentLoaded', function() {
                        var violators = " . json_encode($violator_data) . ";
                        var links = document.querySelectorAll('a[href*=\"user/view.php\"]');
                        links.forEach(function(link) {
                            try {
                                var url = new URL(link.href, window.location.origin);
                                var uid = url.searchParams.get('id');
                                if (uid && violators[uid]) {
                                    // Check if badge already exists
                                    if (link.parentNode.querySelector('.netrago-course-badge')) return;
                                    
                                    var badge = document.createElement('span');
                                    badge.className = 'badge badge-danger ml-2 netrago-course-badge';
                                    badge.style.cssText = 'background-color:#dc3545;color:white;padding:3px 6px;border-radius:4px;font-size:0.85em;';
                                    badge.title = 'Total NetraGo Violations in this Course';
                                    badge.innerHTML = '⚠️ ' + violators[uid] + ' Violations';
                                    link.parentNode.appendChild(badge);
                                }
                            } catch (e) {}
                        });
                    });
                ";
                $CFG->additionalhtmlhead .= "<script>{$js}</script>";
            }
        }
        return; // Stop execution here, no need for cmid logic on gradebook
    }

    // Try to get cmid from PAGE or from URL parameters
    $cmid = 0;
    if (isset($PAGE->cm) && !empty($PAGE->cm->id)) {
        $cmid = $PAGE->cm->id;
    } else {
        $cmid = optional_param('cmid', 0, PARAM_INT);
        if (!$cmid) {
            $cmid = optional_param('id', 0, PARAM_INT);
        }
    }

    if (!$cmid) {
        return;
    }

    // We only want to inject on view/attempt pages, not course pages or edit pages
    $urlpath = '';
    if ($PAGE->url) {
        $urlpath = $PAGE->url->out_as_local_url(false);
    } else {
        $urlpath = $_SERVER['REQUEST_URI'];
    }
    
    if (strpos($urlpath, '/mod/') === false || strpos($urlpath, 'edit') !== false || strpos($urlpath, 'review.php') !== false) {
        return;
    }

    $context = context_module::instance($cmid);

    // Check if this module has NetraGo enabled.
    $settings = $DB->get_record('local_netrago', ['cmid' => $cmid]);
    if (!$settings) {
        return;
    }

    if (!$settings->requirecamera && !$settings->requirefullscreen && !($settings->requirescreencapture ?? 0) && !$settings->disablecopypaste && !$settings->disablefocusloss && !$settings->disabledevtools) {
        return;
    }

    // Do not proctor users who can manage activities (Teachers/Admins).
    if (has_capability('moodle/course:manageactivities', $context)) {
        if (strpos($urlpath, '/mod/') !== false && strpos($urlpath, 'report.php') !== false) {
            // Inject Badges to Grading Table
            $violators = $DB->get_records_sql("SELECT userid, COUNT(id) as vcount FROM {local_netrago_logs} WHERE cmid = ? GROUP BY userid", [$cmid]);
            $violator_data = [];
            foreach ($violators as $v) {
                $violator_data[$v->userid] = $v->vcount;
            }
            
            $rep_url = (new moodle_url('/local/netrago/report.php', ['cmid' => $cmid]))->out(false);
            $js = "
                document.addEventListener('DOMContentLoaded', function() {
                    var violators = " . json_encode($violator_data) . ";
                    var links = document.querySelectorAll('table.generaltable a[href*=\"user/view.php\"]');
                    links.forEach(function(link) {
                        var url = new URL(link.href, window.location.origin);
                        var uid = url.searchParams.get('id');
                        if (uid && violators[uid]) {
                            var badge = document.createElement('span');
                            badge.className = 'badge badge-danger ml-2';
                            badge.style.cssText = 'background-color:#dc3545;color:white;padding:3px 6px;border-radius:4px;font-size:0.85em;';
                            badge.innerHTML = '⚠️ ' + violators[uid] + ' Violations';
                            var repLink = document.createElement('a');
                            repLink.href = '{$rep_url}&userid=' + uid;
                            repLink.title = 'View Proctoring Report';
                            repLink.target = '_blank';
                            repLink.appendChild(badge);
                            link.parentNode.appendChild(repLink);
                        }
                    });
                });
            ";
            $CFG->additionalhtmlhead .= "<script>{$js}</script>";
        }
        return;
    }

    // Require KYC Onboarding if they don't have a baseline for this CM.
    $kyc = $DB->get_record('local_netrago_kyc', ['userid' => $USER->id, 'cmid' => $cmid]);
    
    // Check if user has master face
    $master_field = $DB->get_record('user_info_field', ['shortname' => 'netrago_master_face']);
    $master_descriptor = null;
    if ($master_field) {
        $master_data = $DB->get_record('user_info_data', ['userid' => $USER->id, 'fieldid' => $master_field->id]);
        if ($master_data && !empty($master_data->data)) {
            $master_descriptor = $master_data->data;
        }
    }

    // Bypass KYC if camera is not required, OR if they already did KYC for this quiz, OR if they have a Master Face
    if ($settings->requirecamera && !$kyc && !$master_descriptor) {
        $returnurl = new moodle_url($PAGE->url);
        $kycurl = new moodle_url('/local/netrago/kyc.php', ['cmid' => $cmid, 'returnurl' => $returnurl->out_as_local_url(false)]);
        redirect($kycurl);
    }

    $descriptor_to_use = $kyc ? $kyc->descriptor : ($master_descriptor ? $master_descriptor : null);

    // Inject our AMD module.
    $config = [
        'cmid' => $cmid,
        'userid' => $USER->id,
        'requirecamera' => get_config('local_netrago', 'allow_camera') ? $settings->requirecamera : 0,
        'requirefullscreen' => get_config('local_netrago', 'allow_fullscreen') ? $settings->requirefullscreen : 0,
        'requirescreencapture' => get_config('local_netrago', 'allow_screencapture') ? ($settings->requirescreencapture ?? 0) : 0,
        'disablecopypaste' => get_config('local_netrago', 'allow_copypaste') ? $settings->disablecopypaste : 0,
        'allow_focusloss' => get_config('local_netrago', 'allow_focusloss') ? $settings->disablefocusloss : 0,
        'allow_devtools' => get_config('local_netrago', 'allow_devtools') ? $settings->disabledevtools : 0,
        'ajaxurl' => (new moodle_url('/local/netrago/ajax.php'))->out(false),
        'descriptor' => $descriptor_to_use
    ];

    // Auto-attempt quiz if navigating from KYC or bypassing
    if (strpos($urlpath, '/mod/quiz/view.php') !== false) {
        $auto_start_js = "
            document.addEventListener('DOMContentLoaded', function() {
                var forms = document.querySelectorAll('form[action*=\"startattempt.php\"]');
                if (forms.length > 0) {
                    var btn = forms[0].querySelector('button[type=\"submit\"], input[type=\"submit\"]');
                    if (btn) {
                        // Create a temporary overlay so they don't see the page flash
                        var overlay = document.createElement('div');
                        overlay.style.cssText = 'position:fixed; top:0; left:0; width:100vw; height:100vh; background:#f4f6f9; z-index:9999999; display:flex; align-items:center; justify-content:center; flex-direction:column;';
                        overlay.innerHTML = '<div class=\"spinner-border text-primary\" style=\"width: 3rem; height: 3rem;\" role=\"status\"></div><h4 class=\"mt-3\">Starting Activity...</h4>';
                        document.body.appendChild(overlay);
                        btn.click();
                    }
                }
            });
        ";
        $CFG->additionalhtmlhead .= "<script>{$auto_start_js}</script>";
    }

    // No-JS Fallback: Hide the main content via CSS.
    // The proctoring.js will remove this CSS once camera/permissions are granted.
    $warningmsg = get_string('js_required_warning', 'local_netrago');
    $css = "
        <style id='netrago-anti-js-bypass'>
            body { overflow: hidden !important; }
            #netrago-nojs-warning { 
                position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; 
                background: #f8d7da; color: #721c24; 
                z-index: 9999999; display: flex; flex-direction: column;
                align-items: center; justify-content: center;
                font-size: 1.2rem; font-weight: bold; text-align: center; padding: 20px;
            }
        </style>
        <div id='netrago-nojs-warning'>
            <i class='fa fa-exclamation-triangle fa-3x mb-3'></i>
            <span id='netrago-warning-text'>{$warningmsg}</span>
            <button id='netrago-start-btn' class='btn btn-primary mt-4' style='display:none; font-size: 1.1rem; padding: 10px 20px;'><i class='fa fa-desktop'></i> Start Activity & Share Screen</button>
        </div>
    ";
    
    // We add this to the page header so it renders before the content.
    $CFG->additionalhtmlhead .= $css;

    $PAGE->requires->js(new moodle_url('/local/netrago/amd/src/face-api.min.js'));
    $PAGE->requires->js_call_amd('local_netrago/proctoring', 'init', [$config]);
}

/**
 * Injects NetraGo Report link into the module's secondary navigation (Results tab).
 */
function local_netrago_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    global $DB;
    if ($context->contextlevel == CONTEXT_MODULE) {
        $cm = get_coursemodule_from_id('', $context->instanceid, 0, false, MUST_EXIST);
        if (has_capability('moodle/course:manageactivities', $context)) {
            // Check if NetraGo is enabled
            $settings = $DB->get_record('local_netrago', ['cmid' => $cm->id]);
            if ($settings && ($settings->requirecamera || $settings->requirefullscreen || $settings->disablecopypaste || $settings->disablefocusloss || $settings->disabledevtools)) {
                $reporturl = new moodle_url('/local/netrago/report.php', ['cmid' => $cm->id]);
                
                // Try to add to the "Results" report node for Quiz
                $reportnode = $settingsnav->find('mod_quiz_report', navigation_node::TYPE_SETTING);
                if ($reportnode) {
                    $reportnode->add('NetraGo Proctoring', $reporturl, navigation_node::TYPE_SETTING, null, 'netrago_report', new pix_icon('i/report', ''));
                } else {
                    // Fallback to module settings node
                    $modulenode = $settingsnav->get('modulesettings');
                    if ($modulenode) {
                        $modulenode->add('NetraGo Proctoring', $reporturl, navigation_node::TYPE_SETTING, null, 'netrago_report', new pix_icon('i/report', ''));
                    }
                }
            }
        }
    }
}
