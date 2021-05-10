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
 * Implementaton of the assignmentquesaccess_password plugin.
 *
 * @package    assignmentquesaccess
 * @subpackage password
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assignmentques/accessrule/accessrulebase.php');


/**
 * A rule implementing the password check.
 *
 * @copyright  2009 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignmentquesaccess_password extends assignmentques_access_rule_base {

    public static function make(assignmentques $assignmentquesobj, $timenow, $canignoretimelimits) {
        if (empty($assignmentquesobj->get_assignmentques()->password)) {
            return null;
        }

        return new self($assignmentquesobj, $timenow);
    }

    public function description() {
        return get_string('requirepasswordmessage', 'assignmentquesaccess_password');
    }

    public function is_preflight_check_required($attemptid) {
        global $SESSION;
        return empty($SESSION->passwordcheckedassignmentqueszes[$this->assignmentques->id]);
    }

    public function add_preflight_check_form_fields(mod_assignmentques_preflight_check_form $assignmentquesform,
            MoodleQuickForm $mform, $attemptid) {

        $mform->addElement('header', 'passwordheader', get_string('password'));
        $mform->addElement('static', 'passwordmessage', '',
                get_string('requirepasswordmessage', 'assignmentquesaccess_password'));

        // Don't use the 'proper' field name of 'password' since that get's
        // Firefox's password auto-complete over-excited.
        $mform->addElement('password', 'assignmentquespassword',
                get_string('assignmentquespassword', 'assignmentquesaccess_password'), array('autofocus' => 'true'));
    }

    public function validate_preflight_check($data, $files, $errors, $attemptid) {

        $enteredpassword = $data['assignmentquespassword'];
        if (strcmp($this->assignmentques->password, $enteredpassword) === 0) {
            return $errors; // Password is OK.

        } else if (isset($this->assignmentques->extrapasswords)) {
            // Group overrides may have additional passwords.
            foreach ($this->assignmentques->extrapasswords as $password) {
                if (strcmp($password, $enteredpassword) === 0) {
                    return $errors; // Password is OK.
                }
            }
        }

        $errors['assignmentquespassword'] = get_string('passworderror', 'assignmentquesaccess_password');
        return $errors;
    }

    public function notify_preflight_check_passed($attemptid) {
        global $SESSION;
        $SESSION->passwordcheckedassignmentqueszes[$this->assignmentques->id] = true;
    }

    public function current_attempt_finished() {
        global $SESSION;
        // Clear the flag in the session that says that the user has already
        // entered the password for this assignmentques.
        if (!empty($SESSION->passwordcheckedassignmentqueszes[$this->assignmentques->id])) {
            unset($SESSION->passwordcheckedassignmentqueszes[$this->assignmentques->id]);
        }
    }
}
