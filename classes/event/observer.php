<?php
namespace local_netrago\event;

defined('MOODLE_INTERNAL') || die();

class observer {
    
    /**
     * Observer for mod_quiz attempt deleted.
     * Deletes NetraGo logs, KYC baseline, and KYC attempts for the user and cmid.
     *
     * @param \core\event\base $event
     */
    public static function quiz_attempt_deleted(\core\event\base $event) {
        global $DB;
        
        // We need the userid and context (cmid)
        $userid = $event->relateduserid;
        $context = $event->get_context();
        
        if (!$userid || !$context || $context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        
        $cmid = $context->instanceid;
        
        // Check if NetraGo is enabled for this module before running queries
        $settings = $DB->get_record('local_netrago', ['cmid' => $cmid]);
        if (!$settings) {
            return;
        }
        
        $conditions = [
            'userid' => $userid,
            'cmid' => $cmid
        ];
        
        // Delete all proctoring logs for this user in this quiz
        $DB->delete_records('local_netrago_logs', $conditions);
        
        // Delete KYC baseline for this user in this quiz
        $DB->delete_records('local_netrago_kyc', $conditions);
        
        // Delete KYC attempts (rate limit tracking) for this user in this quiz
        $DB->delete_records('local_netrago_kyc_attempts', $conditions);
    }
}
