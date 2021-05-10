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
 * Legacy Cron Assignmentques Access Rules Task
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
 * Legacy Cron Assignmentques Access Rules Task
 *
 * @package    mod_assignmentques
 * @copyright  2017 Michael Hughes
 * @author Michael Hughes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
class legacy_assignmentques_accessrules_cron extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('legacyassignmentquesaccessrulescron', 'mod_assignmentques');
    }

    /**
     * Execute all assignmentquesaccess subplugins legacy cron tasks.
     */
    public function execute() {
        cron_execute_plugin_type('assignmentquesaccess', 'assignmentques access rules');
    }
}
