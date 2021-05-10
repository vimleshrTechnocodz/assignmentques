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
 * This script lists all the instances of assignmentques in a particular course
 *
 * @package    mod_assignmentques
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once("../../config.php");
require_once("locallib.php");

$id = required_param('id', PARAM_INT);
$PAGE->set_url('/mod/assignmentques/index.php', array('id'=>$id));
if (!$course = $DB->get_record('course', array('id' => $id))) {
    print_error('invalidcourseid');
}
$coursecontext = context_course::instance($id);
require_login($course);
$PAGE->set_pagelayout('incourse');

$params = array(
    'context' => $coursecontext
);
$event = \mod_assignmentques\event\course_module_instance_list_viewed::create($params);
$event->trigger();

// Print the header.
$strassignmentqueszes = get_string("modulenameplural", "assignmentques");
$PAGE->navbar->add($strassignmentqueszes);
$PAGE->set_title($strassignmentqueszes);
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
echo $OUTPUT->heading($strassignmentqueszes, 2);

// Get all the appropriate data.
if (!$assignmentqueszes = get_all_instances_in_course("assignmentques", $course)) {
    notice(get_string('thereareno', 'moodle', $strassignmentqueszes), "../../course/view.php?id=$course->id");
    die;
}

// Check if we need the feedback header.
$showfeedback = false;
foreach ($assignmentqueszes as $assignmentques) {
    if (assignmentques_has_feedback($assignmentques)) {
        $showfeedback=true;
    }
    if ($showfeedback) {
        break;
    }
}

// Configure table for displaying the list of instances.
$headings = array(get_string('name'));
$align = array('left');

array_push($headings, get_string('assignmentquescloses', 'assignmentques'));
array_push($align, 'left');

if (course_format_uses_sections($course->format)) {
    array_unshift($headings, get_string('sectionname', 'format_'.$course->format));
} else {
    array_unshift($headings, '');
}
array_unshift($align, 'center');

$showing = '';

if (has_capability('mod/assignmentques:viewreports', $coursecontext)) {
    array_push($headings, get_string('attempts', 'assignmentques'));
    array_push($align, 'left');
    $showing = 'stats';

} else if (has_any_capability(array('mod/assignmentques:reviewmyattempts', 'mod/assignmentques:attempt'),
        $coursecontext)) {
    array_push($headings, get_string('grade', 'assignmentques'));
    array_push($align, 'left');
    if ($showfeedback) {
        array_push($headings, get_string('feedback', 'assignmentques'));
        array_push($align, 'left');
    }
    $showing = 'grades';

    $grades = $DB->get_records_sql_menu('
            SELECT qg.assignmentques, qg.grade
            FROM {assignmentques_grades} qg
            JOIN {assignmentques} q ON q.id = qg.assignmentques
            WHERE q.course = ? AND qg.userid = ?',
            array($course->id, $USER->id));
}

$table = new html_table();
$table->head = $headings;
$table->align = $align;

// Populate the table with the list of instances.
$currentsection = '';
// Get all closing dates.
$timeclosedates = assignmentques_get_user_timeclose($course->id);
foreach ($assignmentqueszes as $assignmentques) {
    $cm = get_coursemodule_from_instance('assignmentques', $assignmentques->id);
    $context = context_module::instance($cm->id);
    $data = array();

    // Section number if necessary.
    $strsection = '';
    if ($assignmentques->section != $currentsection) {
        if ($assignmentques->section) {
            $strsection = $assignmentques->section;
            $strsection = get_section_name($course, $assignmentques->section);
        }
        if ($currentsection) {
            $learningtable->data[] = 'hr';
        }
        $currentsection = $assignmentques->section;
    }
    $data[] = $strsection;

    // Link to the instance.
    $class = '';
    if (!$assignmentques->visible) {
        $class = ' class="dimmed"';
    }
    $data[] = "<a$class href=\"view.php?id=$assignmentques->coursemodule\">" .
            format_string($assignmentques->name, true) . '</a>';

    // Close date.
    if (($timeclosedates[$assignmentques->id]->usertimeclose != 0)) {
        $data[] = userdate($timeclosedates[$assignmentques->id]->usertimeclose);
    } else {
        $data[] = get_string('noclose', 'assignmentques');
    }

    if ($showing == 'stats') {
        // The $assignmentques objects returned by get_all_instances_in_course have the necessary $cm
        // fields set to make the following call work.
        $data[] = assignmentques_attempt_summary_link_to_reports($assignmentques, $cm, $context);

    } else if ($showing == 'grades') {
        // Grade and feedback.
        $attempts = assignmentques_get_user_attempts($assignmentques->id, $USER->id, 'all');
        list($someoptions, $alloptions) = assignmentques_get_combined_reviewoptions(
                $assignmentques, $attempts);

        $grade = '';
        $feedback = '';
        if ($assignmentques->grade && array_key_exists($assignmentques->id, $grades)) {
            if ($alloptions->marks >= question_display_options::MARK_AND_MAX) {
                $a = new stdClass();
                $a->grade = assignmentques_format_grade($assignmentques, $grades[$assignmentques->id]);
                $a->maxgrade = assignmentques_format_grade($assignmentques, $assignmentques->grade);
                $grade = get_string('outofshort', 'assignmentques', $a);
            }
            if ($alloptions->overallfeedback) {
                $feedback = assignmentques_feedback_for_grade($grades[$assignmentques->id], $assignmentques, $context);
            }
        }
        $data[] = $grade;
        if ($showfeedback) {
            $data[] = $feedback;
        }
    }

    $table->data[] = $data;
} // End of loop over assignmentques instances.

// Display the table.
echo html_writer::table($table);

// Finish the page.
echo $OUTPUT->footer();
