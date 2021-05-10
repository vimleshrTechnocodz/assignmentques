<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This script controls the display of the assignmentques reports.
 *
 * @package   mod_assignmentques
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_OUTPUT_BUFFERING', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');
require_once($CFG->dirroot . '/mod/assignmentques/report/reportlib.php');
require_once($CFG->dirroot . '/mod/assignmentques/report/default.php');

$id = optional_param('id', 0, PARAM_INT);
$q = optional_param('q', 0, PARAM_INT);
$mode = optional_param('mode', '', PARAM_ALPHA);

if ($id) {
    if (!$cm = get_coursemodule_from_id('assignmentques', $id)) {
        print_error('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
        print_error('coursemisconf');
    }
    if (!$assignmentques = $DB->get_record('assignmentques', array('id' => $cm->instance))) {
        print_error('invalidcoursemodule');
    }

} else {
    if (!$assignmentques = $DB->get_record('assignmentques', array('id' => $q))) {
        print_error('invalidassignmentquesid', 'assignmentques');
    }
    if (!$course = $DB->get_record('course', array('id' => $assignmentques->course))) {
        print_error('invalidcourseid');
    }
    if (!$cm = get_coursemodule_from_instance("assignmentques", $assignmentques->id, $course->id)) {
        print_error('invalidcoursemodule');
    }
}

$url = new moodle_url('/mod/assignmentques/report.php', array('id' => $cm->id));
if ($mode !== '') {
    $url->param('mode', $mode);
}
$PAGE->set_url($url);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
$PAGE->set_pagelayout('report');

$reportlist = assignmentques_report_list($context);
if (empty($reportlist)) {
    print_error('erroraccessingreport', 'assignmentques');
}

// Validate the requested report name.
if ($mode == '') {
    // Default to first accessible report and redirect.
    $url->param('mode', reset($reportlist));
    redirect($url);
} else if (!in_array($mode, $reportlist)) {
    print_error('erroraccessingreport', 'assignmentques');
}
if (!is_readable("report/$mode/report.php")) {
    print_error('reportnotfound', 'assignmentques', '', $mode);
}

// Open the selected assignmentques report and display it.
$file = $CFG->dirroot . '/mod/assignmentques/report/' . $mode . '/report.php';
if (is_readable($file)) {
    include_once($file);
}
$reportclassname = 'assignmentques_' . $mode . '_report';
if (!class_exists($reportclassname)) {
    print_error('preprocesserror', 'assignmentques');
}

$report = new $reportclassname();
$report->display($assignmentques, $cm, $course);

// Print footer.
echo $OUTPUT->footer();

// Log that this report was viewed.
$params = array(
    'context' => $context,
    'other' => array(
        'assignmentquesid' => $assignmentques->id,
        'reportname' => $mode
    )
);
$event = \mod_assignmentques\event\report_viewed::create($params);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('assignmentques', $assignmentques);
$event->trigger();
