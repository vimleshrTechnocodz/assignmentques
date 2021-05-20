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
 * This page allows the teacher to enter a manual grade for a particular question.
 * This page is expected to only be used in a popup window.
 *
 * @package   mod_assignmentques
 * @copyright gustav delius 2006
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once('locallib.php');

$attemptid = required_param('attempt', PARAM_INT);
$slot = required_param('slot', PARAM_INT); // The question number in the attempt.
$forcecomment = optional_param('forcecomment',null, PARAM_INT);
$status = optional_param('status',null, PARAM_RAW);
$commentdata = optional_param('commentdata',null, PARAM_RAW);
$cmid = optional_param('cmid', null, PARAM_INT);

$PAGE->set_url('/mod/assignmentques/comment.php', array('attempt' => $attemptid, 'slot' => $slot));

$attemptobj = assignmentques_create_attempt_handling_errors($attemptid, $cmid);
$student = $DB->get_record('user', array('id' => $attemptobj->get_userid()));

// Can only grade finished attempts.
if (!$attemptobj->is_finished()) {
    //print_error('attemptclosed', 'assignmentques');
}

// Check login and permissions.
require_login($attemptobj->get_course(), false, $attemptobj->get_cm());
$attemptobj->require_capability('mod/assignmentques:grade');

// Print the page header.
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('manualgradequestion', 'assignmentques', array(
        'question' => format_string($attemptobj->get_question_name($slot)),
        'assignmentques' => format_string($attemptobj->get_assignmentques_name()), 'user' => fullname($student))));
$PAGE->set_heading($attemptobj->get_course()->fullname);
$output = $PAGE->get_renderer('mod_assignmentques');
echo $output->header();

// Prepare summary information about this question attempt.
$summarydata = array();

// Student name.
$userpicture = new user_picture($student);
$userpicture->courseid = $attemptobj->get_courseid();
$summarydata['user'] = array(
    'title'   => $userpicture,
    'content' => new action_link(new moodle_url('/user/view.php', array(
            'id' => $student->id, 'course' => $attemptobj->get_courseid())),
            fullname($student, true)),
);

// Assignmentques name.
$summarydata['assignmentquesname'] = array(
    'title'   => get_string('modulename', 'assignmentques'),
    'content' => format_string($attemptobj->get_assignmentques_name()),
);

// Question name.
$summarydata['questionname'] = array(
    'title'   => get_string('question', 'assignmentques'),
    'content' => $attemptobj->get_question_name($slot),
);

// Process any data that was submitted.
if (data_submitted() && confirm_sesskey()) {
    if($forcecomment){
        $course = $attemptobj->get_course();
        $cm =  $attemptobj->get_cm();
        $attempt = $attemptobj->get_attempt();        
        $assignmentques = $attemptobj->get_assignmentques();
        $assignmentquesid = $assignmentques->id;  
        $a->courseid=$course->id;
        $a->assignmentquesreviewurl = $CFG->wwwroot . '/mod/assignmentques/attempt.php?attempt=' . $attempt->id.'&cmid='.$cm->id;
        $a->assignmentquesname=$assignmentques->name;
        $a->assignmentquescmid=$cm->id;
        $a->assignmentquesid=$assignmentquesid;
        $a->attemptid=$attempt->id; 

        $a->coursename=$course->fullname; 
        $a->submissiontime=date('Y-m-d h:i:s'); 
        $a->assignmentquesurl=$a->assignmentquesreviewurl; 
        $context = get_context_instance(CONTEXT_COURSE,$course->id);
        $roles = get_user_roles($context, $USER->id);  
        if($status=='needcorrection'){
            $a->fullmessagehtml='<p>
            <strong>Question Status: </strong>'.get_string('needcorrection','assignmentques').'<br/>
            Feedback by teacher for '.$course->fullname.', question no. '.$slot.' <a href="'.$a->assignmentquesreviewurl.'">'.$assignmentques->name.'</a></p>';
            $recipient = \core_user::get_user($attemptobj->get_userid());
            $notiCheck = assignmentques_send_confirmation($recipient, $a);
        }elseif($status=='passedtoiqa'){            
            $a->fullmessagehtml='<p>
            <strong>Question Status: </strong>'.get_string('passedtoiqa','assignmentques').'<br/>
            Feedback by teacher for '.$course->fullname.', question no. '.$slot.' <a href="'.$a->assignmentquesreviewurl.'">'.$assignmentques->name.'</a></p>';
            $recipient = \core_user::get_user($attemptobj->get_userid());
            $notiCheck = assignmentques_send_confirmation($recipient, $a);
            $role=current($roles);
            if($role->roleid==3 or $role->roleid==4){
                $rolcon = array('roleid'=>1,'contextid'=>$context->id);
                $roleusers = $DB->get_records('role_assignments', $rolcon);               
                foreach($roleusers as $roleuser){
                    $recipient = \core_user::get_user($roleuser->userid);
                    assignmentques_send_confirmation($recipient, $a);
                }
            }
        }elseif($status=='needcorrectioniqa'){
            $a->fullmessagehtml='<p>
            <strong>Question Status: </strong>'.get_string('needcorrectioniqa','assignmentques').'<br/>
            Feedback by IQA of '.$course->fullname.', question no. '.$slot.' <a href="'.$a->assignmentquesreviewurl.'">'.$assignmentques->name.'</a></p>';
            
            $commentcon = array('attempt'=>$attempt->id,'slot'=>$slot);            
            $teachers = $DB->get_records('assignmentques_comment', $commentcon);
            
            $recipient = \core_user::get_user($attemptobj->get_userid());
            assignmentques_send_confirmation($recipient, $a);

            $usercheker=array();
            foreach($teachers as $teacher){
                if($teacher->userid!=$USER->id and in_array($teacher->userid, $usercheker) == false){
                    $usercheker[]=$teacher->userid;
                    $teacherNoti = \core_user::get_user($teacher->userid); 
                    assignmentques_send_confirmation($teacherNoti, $a);
                }                
            }
        }elseif($status=='iqaagreeonpass'){
            $a->fullmessagehtml='<p>
            <strong>Question Status: </strong>'.get_string('iqaagreeonpass','assignmentques').'<br/>
            Feedback by IQA of '.$course->fullname.', question no. '.$slot.' <a href="'.$a->assignmentquesreviewurl.'">'.$assignmentques->name.'</a></p>';
            
            $commentcon = array('attempt'=>$attempt->id,'slot'=>$slot);            
            $teachers = $DB->get_records('assignmentques_comment', $commentcon);
            
            $recipient = \core_user::get_user($attemptobj->get_userid());
            assignmentques_send_confirmation($recipient, $a);

            $usercheker=array();
            foreach($teachers as $teacher){
                if($teacher->userid!=$USER->id and in_array($teacher->userid, $usercheker) == false){
                    $usercheker[]=$teacher->userid;
                    $teacherNoti = \core_user::get_user($teacher->userid); 
                    assignmentques_send_confirmation($teacherNoti, $a);
                }                
            }
        }
        
        //print_r($notiCheck);
        //return;    
        $commentTime=time();
        $uniqueid=$attemptobj->get_attempt()->uniqueid;
        $slotNumber=$slot;
        $conditions = array('questionusageid'=>$uniqueid,'slot'=>$slot);
        $sequence = $attemptobj->get_question_attempt($slot)->get_sequence_check_count();
        $currentUserID=$USER->id;
        $questionAttempt=$DB->get_record('question_attempts', $conditions);
        if($questionAttempt){
            $attempt_steps=new stdClass();
            $attempt_steps->questionattemptid=$questionAttempt->id;
            $attempt_steps->sequencenumber=$sequence;
            $attempt_steps->state='complete';
            $attempt_steps->timecreated=$commentTime;
            $attempt_steps->userid=$currentUserID;
            $insId = $DB->insert_record('question_attempt_steps', $attempt_steps);
            if($insId){                
                $attempt_step_data = new stdClass();
                $attempt_step_data->attemptstepid=$insId;
                $attempt_step_data->name='-comment';
                $attempt_step_data->value=$commentdata;
                $insDataId = $DB->insert_record('question_attempt_step_data', $attempt_step_data);
                if($insDataId){
                    $assignmentques_comment = new stdClass();
                    $assignmentques_comment->question_attempt_step_dataid = $insDataId;
                    $assignmentques_comment->userid = $currentUserID;
                    $assignmentques_comment->comment = $commentdata;
                    $assignmentques_comment->attempt = $attemptid;
                    $assignmentques_comment->slot = $slotNumber;
                    $assignmentques_comment->timecreated = $commentTime;
                    $assignmentques_comment->status = $status;
                    $insCommentDataId = $DB->insert_record('assignmentques_comment', $assignmentques_comment);
                    if($insCommentDataId){
                        redirect($CFG->wwwroot.'/mod/assignmentques/review.php?attempt='.$attemptobj->get_attemptid(), get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
                    }else{
                        redirect($CFG->wwwroot.'/mod/assignmentques/review.php?attempt='.$attemptobj->get_attemptid(), get_string('notchangessaved'), null, \core\output\notification::NOTIFY_ERROR);
                    }
                }
                else{
                    redirect($CFG->wwwroot.'/mod/assignmentques/review.php?attempt='.$attemptobj->get_attemptid(), get_string('notchangessaved'), null, \core\output\notification::NOTIFY_ERROR);
                }
            }else{
                redirect($CFG->wwwroot.'/mod/assignmentques/review.php?attempt='.$attemptobj->get_attemptid(), get_string('notchangessaved'), null, \core\output\notification::NOTIFY_ERROR);
            }
        }else{
            redirect($CFG->wwwroot.'/mod/assignmentques/review.php?attempt='.$attemptobj->get_attemptid(), get_string('notchangessaved'), null, \core\output\notification::NOTIFY_ERROR);
        }
        die;
    }else if (optional_param('submit', false, PARAM_BOOL) && question_engine::is_manual_grade_in_range($attemptobj->get_uniqueid(), $slot)) {
        $transaction = $DB->start_delegated_transaction();       
        $attemptobj->process_submitted_actions(time());        
        $transaction->allow_commit();
        
        // Log this action.
        $params = array(
            'objectid' => $attemptobj->get_question_attempt($slot)->get_question()->id,
            'courseid' => $attemptobj->get_courseid(),
            'context' => context_module::instance($attemptobj->get_cmid()),
            'other' => array(
                'assignmentquesid' => $attemptobj->get_assignmentquesid(),
                'attemptid' => $attemptobj->get_attemptid(),
                'slot' => $slot
            )
        );
        $event = \mod_assignmentques\event\question_manually_graded::create($params);       
        $event->trigger(); 
        echo $output->notification(get_string('changessaved'), 'notifysuccess');
        close_window(2, true);
       
        die;
    }
}

// Print assignmentques information.
echo $output->review_summary_table($summarydata, 0);

// Print the comment form.
echo '<form method="post" class="mform" id="manualgradingform" action="' .
        $CFG->wwwroot . '/mod/assignmentques/comment.php">';
echo $attemptobj->render_question_for_commenting($slot);
?>
<div>
    <input type="hidden" name="attempt" value="<?php echo $attemptobj->get_attemptid(); ?>" />
    <input type="hidden" name="slot" value="<?php echo $slot; ?>" />
    <input type="hidden" name="slots" value="<?php echo $slot; ?>" />
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>" />
</div>
<fieldset class="hidden">
    <div>
        <div class="fitem fitem_actionbuttons fitem_fsubmit">
            <fieldset class="felement fsubmit">
                <input id="id_submitbutton" type="submit" name="submit" class="btn btn-primary" value="<?php
                        print_string('save', 'assignmentques'); ?>"/>
            </fieldset>
        </div>
    </div>
</fieldset>
<?php
echo '</form>';
$PAGE->requires->js_init_call('M.mod_assignmentques.init_comment_popup', null, false, assignmentques_get_js_module());

// End of the page.
echo $output->footer();
