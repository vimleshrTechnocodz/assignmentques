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
 * Definition of log events for the assignmentques module.
 *
 * @package    mod_assignmentques
 * @category   log
 * @copyright  2010 Petr Skoda (http://skodak.org)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$logs = array(
    array('module'=>'assignmentques', 'action'=>'add', 'mtable'=>'assignmentques', 'field'=>'name'),
    array('module'=>'assignmentques', 'action'=>'update', 'mtable'=>'assignmentques', 'field'=>'name'),
    array('module'=>'assignmentques', 'action'=>'view', 'mtable'=>'assignmentques', 'field'=>'name'),
    array('module'=>'assignmentques', 'action'=>'report', 'mtable'=>'assignmentques', 'field'=>'name'),
    array('module'=>'assignmentques', 'action'=>'attempt', 'mtable'=>'assignmentques', 'field'=>'name'),
    array('module'=>'assignmentques', 'action'=>'submit', 'mtable'=>'assignmentques', 'field'=>'name'),
    array('module'=>'assignmentques', 'action'=>'review', 'mtable'=>'assignmentques', 'field'=>'name'),
    array('module'=>'assignmentques', 'action'=>'editquestions', 'mtable'=>'assignmentques', 'field'=>'name'),
    array('module'=>'assignmentques', 'action'=>'preview', 'mtable'=>'assignmentques', 'field'=>'name'),
    array('module'=>'assignmentques', 'action'=>'start attempt', 'mtable'=>'assignmentques', 'field'=>'name'),
    array('module'=>'assignmentques', 'action'=>'close attempt', 'mtable'=>'assignmentques', 'field'=>'name'),
    array('module'=>'assignmentques', 'action'=>'continue attempt', 'mtable'=>'assignmentques', 'field'=>'name'),
    array('module'=>'assignmentques', 'action'=>'edit override', 'mtable'=>'assignmentques', 'field'=>'name'),
    array('module'=>'assignmentques', 'action'=>'delete override', 'mtable'=>'assignmentques', 'field'=>'name'),
    array('module'=>'assignmentques', 'action'=>'view summary', 'mtable'=>'assignmentques', 'field'=>'name'),
);