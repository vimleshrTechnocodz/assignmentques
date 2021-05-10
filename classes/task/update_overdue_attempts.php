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
 * Update Overdue Attempts Task
 *
 * @package    mod_assignmentques
 * @copyright  2017 Michael Hughes
 * @author Michael Hughes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_assignmentques\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');

/**
 * Update Overdue Attempts Task
 *
 * @package    mod_assignmentques
 * @copyright  2017 Michael Hughes
 * @author Michael Hughes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
class update_overdue_attempts extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('updateoverdueattemptstask', 'mod_assignmentques');
    }

    /**
     *
     * Close off any overdue attempts.
     */
    public function execute() {
        global $CFG;

        require_once($CFG->dirroot . '/mod/assignmentques/cronlib.php');
        $timenow = time();
        $overduehander = new \mod_assignmentques_overdue_attempt_updater();

        $processto = $timenow - get_config('assignmentques', 'graceperiodmin');

        mtrace('  Looking for assignmentques overdue assignmentques attempts...');

        list($count, $assignmentquescount) = $overduehander->update_overdue_attempts($timenow, $processto);

        mtrace('  Considered ' . $count . ' attempts in ' . $assignmentquescount . ' assignmentqueszes.');
    }
}
