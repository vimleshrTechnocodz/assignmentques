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
 * Unit tests for the mod_assignmentques_display_options class.
 *
 * @package    mod_assignmentques
 * @category   phpunit
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');


/**
 * Unit tests for {@link mod_assignmentques_display_options}.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_assignmentques_display_options_testcase extends basic_testcase {
    public function test_num_attempts_access_rule() {
        $assignmentques = new stdClass();
        $assignmentques->decimalpoints = 2;
        $assignmentques->questiondecimalpoints = -1;
        $assignmentques->reviewattempt          = 0x11110;
        $assignmentques->reviewcorrectness      = 0x10000;
        $assignmentques->reviewmarks            = 0x01110;
        $assignmentques->reviewspecificfeedback = 0x10000;
        $assignmentques->reviewgeneralfeedback  = 0x01000;
        $assignmentques->reviewrightanswer      = 0x00100;
        $assignmentques->reviewoverallfeedback  = 0x00010;

        $options = mod_assignmentques_display_options::make_from_assignmentques($assignmentques,
            mod_assignmentques_display_options::DURING);

        $this->assertEquals(true, $options->attempt);
        $this->assertEquals(mod_assignmentques_display_options::VISIBLE, $options->correctness);
        $this->assertEquals(mod_assignmentques_display_options::MAX_ONLY, $options->marks);
        $this->assertEquals(mod_assignmentques_display_options::VISIBLE, $options->feedback);
        // The next two should be controlled by the same settings as ->feedback.
        $this->assertEquals(mod_assignmentques_display_options::VISIBLE, $options->numpartscorrect);
        $this->assertEquals(mod_assignmentques_display_options::VISIBLE, $options->manualcomment);
        $this->assertEquals(2, $options->markdp);

        $assignmentques->questiondecimalpoints = 5;
        $options = mod_assignmentques_display_options::make_from_assignmentques($assignmentques,
            mod_assignmentques_display_options::IMMEDIATELY_AFTER);

        $this->assertEquals(mod_assignmentques_display_options::MARK_AND_MAX, $options->marks);
        $this->assertEquals(mod_assignmentques_display_options::VISIBLE, $options->generalfeedback);
        $this->assertEquals(mod_assignmentques_display_options::HIDDEN, $options->feedback);
        // The next two should be controlled by the same settings as ->feedback.
        $this->assertEquals(mod_assignmentques_display_options::HIDDEN, $options->numpartscorrect);
        $this->assertEquals(mod_assignmentques_display_options::HIDDEN, $options->manualcomment);
        $this->assertEquals(5, $options->markdp);

        $options = mod_assignmentques_display_options::make_from_assignmentques($assignmentques,
            mod_assignmentques_display_options::LATER_WHILE_OPEN);

        $this->assertEquals(mod_assignmentques_display_options::VISIBLE, $options->rightanswer);
        $this->assertEquals(mod_assignmentques_display_options::HIDDEN, $options->generalfeedback);

        $options = mod_assignmentques_display_options::make_from_assignmentques($assignmentques,
            mod_assignmentques_display_options::AFTER_CLOSE);

        $this->assertEquals(mod_assignmentques_display_options::VISIBLE, $options->overallfeedback);
        $this->assertEquals(mod_assignmentques_display_options::HIDDEN, $options->rightanswer);
    }
}
