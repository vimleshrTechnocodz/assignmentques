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
 * Unit tests for the assignmentquesaccess_ipaddress plugin.
 *
 * @package    assignmentquesaccess
 * @subpackage ipaddress
 * @category   phpunit
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assignmentques/accessrule/ipaddress/rule.php');


/**
 * Unit tests for the assignmentquesaccess_ipaddress plugin.
 *
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignmentquesaccess_ipaddress_testcase extends basic_testcase {
    public function test_ipaddress_access_rule() {
        $assignmentques = new stdClass();
        $attempt = new stdClass();
        $cm = new stdClass();
        $cm->id = 0;

        // Test the allowed case by getting the user's IP address. However, this
        // does not always work, for example using the mac install package on my laptop.
        $assignmentques->subnet = getremoteaddr(null);
        if (!empty($assignmentques->subnet)) {
            $assignmentquesobj = new assignmentques($assignmentques, $cm, null);
            $rule = new assignmentquesaccess_ipaddress($assignmentquesobj, 0);

            $this->assertFalse($rule->prevent_access());
            $this->assertFalse($rule->description());
            $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
            $this->assertFalse($rule->is_finished(0, $attempt));
            $this->assertFalse($rule->end_time($attempt));
            $this->assertFalse($rule->time_left_display($attempt, 0));
        }

        $assignmentques->subnet = '0.0.0.0';
        $assignmentquesobj = new assignmentques($assignmentques, $cm, null);
        $rule = new assignmentquesaccess_ipaddress($assignmentquesobj, 0);

        $this->assertNotEmpty($rule->prevent_access());
        $this->assertEmpty($rule->description());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));
        $this->assertFalse($rule->end_time($attempt));
        $this->assertFalse($rule->time_left_display($attempt, 0));
    }
}
