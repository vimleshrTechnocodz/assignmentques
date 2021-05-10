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
 * This page handles deleting assignmentques overrides
 *
 * @package    mod_assignmentques
 * @copyright  2010 Matt Petro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot.'/mod/assignmentques/lib.php');
require_once($CFG->dirroot.'/mod/assignmentques/locallib.php');
require_once($CFG->dirroot.'/mod/assignmentques/override_form.php');

$overrideid = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', false, PARAM_BOOL);

if (! $override = $DB->get_record('assignmentques_overrides', array('id' => $overrideid))) {
    print_error('invalidoverrideid', 'assignmentques');
}
if (! $assignmentques = $DB->get_record('assignmentques', array('id' => $override->assignmentques))) {
    print_error('invalidcoursemodule');
}
if (! $cm = get_coursemodule_from_instance("assignmentques", $assignmentques->id, $assignmentques->course)) {
    print_error('invalidcoursemodule');
}
$course = $DB->get_record('course', array('id'=>$cm->course), '*', MUST_EXIST);

$context = context_module::instance($cm->id);

require_login($course, false, $cm);

// Check the user has the required capabilities to modify an override.
require_capability('mod/assignmentques:manageoverrides', $context);

if ($override->groupid) {
    if (!groups_group_visible($override->groupid, $course, $cm)) {
        print_error('invalidoverrideid', 'assignmentques');
    }
} else {
    if (!groups_user_groups_visible($course, $override->userid, $cm)) {
        print_error('invalidoverrideid', 'assignmentques');
    }
}

$url = new moodle_url('/mod/assignmentques/overridedelete.php', array('id'=>$override->id));
$confirmurl = new moodle_url($url, array('id'=>$override->id, 'confirm'=>1));
$cancelurl = new moodle_url('/mod/assignmentques/overrides.php', array('cmid'=>$cm->id));

if (!empty($override->userid)) {
    $cancelurl->param('mode', 'user');
}

// If confirm is set (PARAM_BOOL) then we have confirmation of intention to delete.
if ($confirm) {
    require_sesskey();

    // Set the course module id before calling assignmentques_delete_override().
    $assignmentques->cmid = $cm->id;
    assignmentques_delete_override($assignmentques, $override->id);

    redirect($cancelurl);
}

// Prepare the page to show the confirmation form.
$stroverride = get_string('override', 'assignmentques');
$title = get_string('deletecheck', null, $stroverride);

$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->navbar->add($title);
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($assignmentques->name, true, array('context' => $context)));

if ($override->groupid) {
    $group = $DB->get_record('groups', array('id' => $override->groupid), 'id, name');
    $confirmstr = get_string("overridedeletegroupsure", "assignmentques", $group->name);
} else {
    $namefields = get_all_user_name_fields(true);
    $user = $DB->get_record('user', array('id' => $override->userid),
            'id, ' . $namefields);
    $confirmstr = get_string("overridedeleteusersure", "assignmentques", fullname($user));
}

echo $OUTPUT->confirm($confirmstr, $confirmurl, $cancelurl);

echo $OUTPUT->footer();
