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
 * This page handles editing and creation of assignmentques overrides
 *
 * @package   mod_assignmentques
 * @copyright 2010 Matt Petro
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot.'/mod/assignmentques/lib.php');
require_once($CFG->dirroot.'/mod/assignmentques/locallib.php');
require_once($CFG->dirroot.'/mod/assignmentques/override_form.php');


$cmid = optional_param('cmid', 0, PARAM_INT);
$overrideid = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', null, PARAM_ALPHA);
$reset = optional_param('reset', false, PARAM_BOOL);

$override = null;
if ($overrideid) {

    if (! $override = $DB->get_record('assignmentques_overrides', array('id' => $overrideid))) {
        print_error('invalidoverrideid', 'assignmentques');
    }
    if (! $assignmentques = $DB->get_record('assignmentques', array('id' => $override->assignmentques))) {
        print_error('invalidcoursemodule');
    }
    list($course, $cm) = get_course_and_cm_from_instance($assignmentques, 'assignmentques');

} else if ($cmid) {
    list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'assignmentques');
    $assignmentques = $DB->get_record('assignmentques', array('id' => $cm->instance), '*', MUST_EXIST);

} else {
    print_error('invalidcoursemodule');
}
$course = $DB->get_record('course', array('id'=>$cm->course), '*', MUST_EXIST);

$url = new moodle_url('/mod/assignmentques/overrideedit.php');
if ($action) {
    $url->param('action', $action);
}
if ($overrideid) {
    $url->param('id', $overrideid);
} else {
    $url->param('cmid', $cmid);
}

$PAGE->set_url($url);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);

// Add or edit an override.
require_capability('mod/assignmentques:manageoverrides', $context);

if ($overrideid) {
    // Editing an override.
    $data = clone $override;

    if ($override->groupid) {
        if (!groups_group_visible($override->groupid, $course, $cm)) {
            print_error('invalidoverrideid', 'assignmentques');
        }
    } else {
        if (!groups_user_groups_visible($course, $override->userid, $cm)) {
            print_error('invalidoverrideid', 'assignmentques');
        }
    }
} else {
    // Creating a new override.
    $data = new stdClass();
}

// Merge assignmentques defaults with data.
$keys = array('timeopen', 'timeclose', 'timelimit', 'attempts', 'password');
foreach ($keys as $key) {
    if (!isset($data->{$key}) || $reset) {
        $data->{$key} = $assignmentques->{$key};
    }
}

// If we are duplicating an override, then clear the user/group and override id
// since they will change.
if ($action === 'duplicate') {
    $override->id = null;
    $override->userid = null;
    $override->groupid = null;
}

// True if group-based override.
$groupmode = !empty($data->groupid) || ($action === 'addgroup' && empty($overrideid));

$overridelisturl = new moodle_url('/mod/assignmentques/overrides.php', array('cmid'=>$cm->id));
if (!$groupmode) {
    $overridelisturl->param('mode', 'user');
}

// Setup the form.
$mform = new assignmentques_override_form($url, $cm, $assignmentques, $context, $groupmode, $override);
$mform->set_data($data);

if ($mform->is_cancelled()) {
    redirect($overridelisturl);

} else if (optional_param('resetbutton', 0, PARAM_ALPHA)) {
    $url->param('reset', true);
    redirect($url);

} else if ($fromform = $mform->get_data()) {
    // Process the data.
    $fromform->assignmentques = $assignmentques->id;

    // Replace unchanged values with null.
    foreach ($keys as $key) {
        if ($fromform->{$key} == $assignmentques->{$key}) {
            $fromform->{$key} = null;
        }
    }

    // See if we are replacing an existing override.
    $userorgroupchanged = false;
    if (empty($override->id)) {
        $userorgroupchanged = true;
    } else if (!empty($fromform->userid)) {
        $userorgroupchanged = $fromform->userid !== $override->userid;
    } else {
        $userorgroupchanged = $fromform->groupid !== $override->groupid;
    }

    if ($userorgroupchanged) {
        $conditions = array(
                'assignmentques' => $assignmentques->id,
                'userid' => empty($fromform->userid)? null : $fromform->userid,
                'groupid' => empty($fromform->groupid)? null : $fromform->groupid);
        if ($oldoverride = $DB->get_record('assignmentques_overrides', $conditions)) {
            // There is an old override, so we merge any new settings on top of
            // the older override.
            foreach ($keys as $key) {
                if (is_null($fromform->{$key})) {
                    $fromform->{$key} = $oldoverride->{$key};
                }
            }
            // Set the course module id before calling assignmentques_delete_override().
            $assignmentques->cmid = $cm->id;
            assignmentques_delete_override($assignmentques, $oldoverride->id);
        }
    }

    // Set the common parameters for one of the events we may be triggering.
    $params = array(
        'context' => $context,
        'other' => array(
            'assignmentquesid' => $assignmentques->id
        )
    );
    if (!empty($override->id)) {
        $fromform->id = $override->id;
        $DB->update_record('assignmentques_overrides', $fromform);

        // Determine which override updated event to fire.
        $params['objectid'] = $override->id;
        if (!$groupmode) {
            $params['relateduserid'] = $fromform->userid;
            $event = \mod_assignmentques\event\user_override_updated::create($params);
        } else {
            $params['other']['groupid'] = $fromform->groupid;
            $event = \mod_assignmentques\event\group_override_updated::create($params);
        }

        // Trigger the override updated event.
        $event->trigger();
    } else {
        unset($fromform->id);
        $fromform->id = $DB->insert_record('assignmentques_overrides', $fromform);

        // Determine which override created event to fire.
        $params['objectid'] = $fromform->id;
        if (!$groupmode) {
            $params['relateduserid'] = $fromform->userid;
            $event = \mod_assignmentques\event\user_override_created::create($params);
        } else {
            $params['other']['groupid'] = $fromform->groupid;
            $event = \mod_assignmentques\event\group_override_created::create($params);
        }

        // Trigger the override created event.
        $event->trigger();
    }

    assignmentques_update_open_attempts(array('assignmentquesid'=>$assignmentques->id));
    if ($groupmode) {
        // Priorities may have shifted, so we need to update all of the calendar events for group overrides.
        assignmentques_update_events($assignmentques);
    } else {
        // User override. We only need to update the calendar event for this user override.
        assignmentques_update_events($assignmentques, $fromform);
    }

    if (!empty($fromform->submitbutton)) {
        redirect($overridelisturl);
    }

    // The user pressed the 'again' button, so redirect back to this page.
    $url->remove_params('cmid');
    $url->param('action', 'duplicate');
    $url->param('id', $fromform->id);
    redirect($url);

}

// Print the form.
$pagetitle = get_string('editoverride', 'assignmentques');
$PAGE->navbar->add($pagetitle);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($pagetitle);
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($assignmentques->name, true, array('context' => $context)));

$mform->display();

echo $OUTPUT->footer();
