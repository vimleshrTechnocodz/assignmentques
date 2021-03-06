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
 * Unit tests for the assignmentquesaccess_numattempts plugin.
 *
 * @package    assignmentquesaccess
 * @subpackage numattempts
 * @category   phpunit
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assignmentques/accessrule/numattempts/rule.php');


/**
 * Unit tests for the assignmentquesaccess_numattempts plugin.
 *
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignmentquesaccess_numattempts_testcase extends basic_testcase {
    public function test_num_attempts_access_rule() {
        $assignmentques = new stdClass();
        $assignmentques->attempts = 3;
        $cm = new stdClass();
        $cm->id = 0;
        $assignmentquesobj = new assignmentques($assignmentques, $cm, null);
        $rule = new assignmentquesaccess_numattempts($assignmentquesobj, 0);
        $attempt = new stdClass();

        $this->assertEquals($rule->description(),
            get_string('attemptsallowedn', 'assignmentquesaccess_numattempts', 3));

        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->prevent_new_attempt(2, $attempt));
        $this->assertEquals($rule->prevent_new_attempt(3, $attempt),
            get_string('nomoreattempts', 'assignmentques'));
        $this->assertEquals($rule->prevent_new_attempt(666, $attempt),
            get_string('nomoreattempts', 'assignmentques'));

        $this->assertFalse($rule->is_finished(0, $attempt));
        $this->assertFalse($rule->is_finished(2, $attempt));
        $this->assertTrue($rule->is_finished(3, $attempt));
        $this->assertTrue($rule->is_finished(666, $attempt));

        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->end_time($attempt));
        $this->assertFalse($rule->time_left_display($attempt, 0));
    }
}
