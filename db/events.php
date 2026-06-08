<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname'   => '\mod_quiz\event\attempt_deleted',
        'callback'    => '\local_netrago\event\observer::quiz_attempt_deleted',
    ],
];
