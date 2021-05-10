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
 * Implementaton of the assignmentquesaccess_openclosedate plugin.
 *
 * @package    assignmentquesaccess
 * @subpackage openclosedate
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assignmentques/accessrule/accessrulebase.php');


/**
 * A rule enforcing open and close dates.
 *
 * @copyright  2009 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignmentquesaccess_openclosedate extends assignmentques_access_rule_base {

    public static function make(assignmentques $assignmentquesobj, $timenow, $canignoretimelimits) {
        // This rule is always used, even if the assignmentques has no open or close date.
        return new self($assignmentquesobj, $timenow);
    }

    public function description() {
        $result = array();
        if ($this->timenow < $this->assignmentques->timeopen) {
            $result[] = get_string('assignmentquesnotavailable', 'assignmentquesaccess_openclosedate',
                    userdate($this->assignmentques->timeopen));
            if ($this->assignmentques->timeclose) {
                $result[] = get_string('assignmentquescloseson', 'assignmentques', userdate($this->assignmentques->timeclose));
            }

        } else if ($this->assignmentques->timeclose && $this->timenow > $this->assignmentques->timeclose) {
            $result[] = get_string('assignmentquesclosed', 'assignmentques', userdate($this->assignmentques->timeclose));

        } else {
            if ($this->assignmentques->timeopen) {
                $result[] = get_string('assignmentquesopenedon', 'assignmentques', userdate($this->assignmentques->timeopen));
            }
            if ($this->assignmentques->timeclose) {
                $result[] = get_string('assignmentquescloseson', 'assignmentques', userdate($this->assignmentques->timeclose));
            }
        }

        return $result;
    }

    public function prevent_access() {
        $message = get_string('notavailable', 'assignmentquesaccess_openclosedate');

        if ($this->timenow < $this->assignmentques->timeopen) {
            return $message;
        }

        if (!$this->assignmentques->timeclose) {
            return false;
        }

        if ($this->timenow <= $this->assignmentques->timeclose) {
            return false;
        }

        if ($this->assignmentques->overduehandling != 'graceperiod') {
            return $message;
        }

        if ($this->timenow <= $this->assignmentques->timeclose + $this->assignmentques->graceperiod) {
            return false;
        }

        return $message;
    }

    public function is_finished($numprevattempts, $lastattempt) {
        return $this->assignmentques->timeclose && $this->timenow > $this->assignmentques->timeclose;
    }

    public function end_time($attempt) {
        if ($this->assignmentques->timeclose) {
            return $this->assignmentques->timeclose;
        }
        return false;
    }

    public function time_left_display($attempt, $timenow) {
        // If this is a teacher preview after the close date, do not show
        // the time.
        if ($attempt->preview && $timenow > $this->assignmentques->timeclose) {
            return false;
        }
        // Otherwise, return to the time left until the close date, providing that is
        // less than ASSIGNMENTQUES_SHOW_TIME_BEFORE_DEADLINE.
        $endtime = $this->end_time($attempt);
        if ($endtime !== false && $timenow > $endtime - ASSIGNMENTQUES_SHOW_TIME_BEFORE_DEADLINE) {
            return $endtime - $timenow;
        }
        return false;
    }
}
