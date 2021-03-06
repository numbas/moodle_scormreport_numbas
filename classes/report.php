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
 * Core Report class of basic reporting plugin
 * @package   scormreport
 * @subpackage numbas
 * @author    Dan Marsden and Ankit Kumar Agarwal
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace scormreport_numbas;

defined('MOODLE_INTERNAL') || die();

function fix_choice_answer($s) {
    $bits = explode('[,]',$s);
    foreach($bits as $i => $b) {
        $bits[$i] = preg_replace('/(\d+)\[\.\](\d+)/','($1,$2)',$b);
    }
    return implode(', ',$bits);
}

class Interaction {
    public $N;

    function __construct($N) {
        $this->$N = $N;
        $this->elements = array();
    }
}

class Question {
    function __construct() {
        $this->parts = array();
    }
}

class Part {
    public $id = '';
    public $type = '';
    public $student_answer = '';
    public $correct_answer = '';
    public $score = '';
    public $marks = '';

    function __construct() {
        $this->gaps = array();
        $this->steps = array();
    }
}

class report extends \mod_scorm\report {
    /**
     * displays the full report
     * @param \stdClass $scorm full SCORM object
     * @param \stdClass $cm - full course_module object
     * @param \stdClass $course - full course object
     * @param string $download - type of download being requested
     */
    public function display($scorm, $cm, $course, $download) {
        global $CFG, $DB, $OUTPUT, $PAGE;

        $this->scorm = $scorm;
        $this->cm = $cm;
        $this->course = $course;

        $action = optional_param('action', '', PARAM_ALPHA);
        switch($action) {
        case 'viewattempt':
            $this->view_attempt();
            break;
        default:
            $this->show_table();
        }
    }

    private function view_attempt() {
        global $CFG, $DB, $OUTPUT, $PAGE;

        $scormversion = strtolower(clean_param($this->scorm->version, PARAM_SAFEDIR));   // Just to be safe.
        require_once($CFG->dirroot.'/mod/scorm/datamodels/'.$scormversion.'lib.php');

        $userid = required_param('user', PARAM_INT);
        $attempt = required_param('attempt', PARAM_INT);

        $scoes = $DB->get_records('scorm_scoes', array("scorm" => $this->scorm->id), 'sortorder, id');
        foreach ($scoes as $sco) {
            if ($sco->launch != '') {
                if ($trackdata = scorm_get_tracks($sco->id, $userid, $attempt)) {
                    if ($trackdata->status == '') {
                        $trackdata->status = 'notattempted';
                    }
                } else {
                    $trackdata = new stdClass();
                    $trackdata->status = 'notattempted';
                    $trackdata->total_time = '';
                }
                $interactions = array();
                $re_interaction = "/^cmi.interactions.(\d+).(id|description|learner_response|correct_responses.0.pattern|weighting|result|type)/";
                $suspend_data = array();
                foreach($trackdata as $element => $value) {
                    if(preg_match($re_interaction,$element,$matches)) {
                        $n = $matches[1];
                        if(!array_key_exists($n,$interactions)) {
                            $interactions[$n] = new Interaction($n);
                        }
                        $interaction = $interactions[$n];
                        $ielement = $matches[2];
                        $interaction->elements[$ielement] = $value;
                    }
                    if($element == 'cmi.suspend_data') {
                        $suspend_data = json_decode($value,TRUE);
                    }
                }
                $questions = array();
                ksort($interactions, SORT_NUMERIC);
                foreach($interactions as $n => $interaction) {
                    if(!array_key_exists('id',$interaction->elements)) {
                        continue;
                    }
                    if(preg_match("/^q(\d+)p(\d+)(?:g(\d+)|s(\d+))?$/",$interaction->elements['id'],$pathm)) {
                        $qn = $pathm[1];
                        if(!array_key_exists($qn,$questions)) {
                            $questions[$qn] = new Question();
                        }
                        $question = $questions[$qn];
                        $pn = $pathm[2];
                        if(!array_key_exists($pn,$question->parts)) {
                            $question->parts[$pn] = new Part();
                        }
                        $part = $question->parts[$pn];
                        if(array_key_exists(3,$pathm) && $pathm[3]!=='') {
                            $gn = $pathm[3];
                            if(!array_key_exists($gn,$part->gaps)) {
                                $part->gaps[$gn] = new Part();
                            }
                            $part = $part->gaps[$gn];
                        } else if(array_key_exists(4,$pathm) && $pathm[4]!=='') {
                            $sn = $pathm[3];
                            if(!array_key_exists($sn,$part->steps)) {
                                $part->steps[$sn] = new Part();
                            }
                            $part = $part->steps[$n];
                        }
                        $element_map = array(
                            'N' => 'N',
                            'id' => 'id',
                            'description' => 'type',
                            'learner_response' => 'student_answer',
                            'correct_responses.0.pattern' => 'correct_answer',
                            'result' => 'score',
                            'weighting' => 'marks'
                        );
                        foreach($element_map as $from => $to) {
                            if(array_key_exists($from,$interaction->elements)) {
                                $part->$to = $interaction->elements[$from];
                            }
                        }
                        switch($part->type) {
                        case 'information':
                        case 'gapfill':
                            $part->student_answer = '';
                            $part->correct_answer = '';
                            break;
                        case 'numberentry':
                            if(preg_match('/^(-?\d+(?:\.\d+)?)\[:\](-?\d+(?:\.\d+)?)$/',$part->correct_answer,$m)) {
                                if($m[1]==$m[2]) {
                                    $part->correct_answer = $m[1];
                                } else {
                                    $part->correct_answer = "${m[1]} to ${m[2]}";
                                }
                            }
                            break;
                        case '1_n_2':
                        case 'm_n_2':
                        case 'm_n_x':
                            $part->student_answer = fix_choice_answer($part->student_answer);
                            $part->correct_answer = fix_choice_answer($part->correct_answer);
                            break;
                        }
                        switch($interaction->elements['type']) {
                        case 'fill-in':
                            $part->correct_answer = preg_replace('/^\{case_matters=(true|false)\}/','',$part->correct_answer,1);
                            if(preg_match('/^-?\d+(\.\d+)\[:\]-?\d+(\.\d+)$/',$part->correct_answer)) {
                                $bits = explode('[:]',$part->correct_answer);
                                if($bits[0]==$bits[1]) {
                                    $part->correct_answer = $bits[0];
                                } else {
                                    $part->correct_answer = str_replace('[:]',' to ',$part->correct_answer);
                                }
                            }
                            break;
                        }
                    }
                }
                $part_type_names = array(
                    'information' => 'Information only',
                    'extension' => 'Extension',
                    '1_n_2' => 'Choose one from a list',
                    'm_n_2' => 'Choose several from a list',
                    'm_n_x' => 'Match choices with answers',
                    'numberentry' => 'Number entry',
                    'matrix' => 'Matrix entry',
                    'patternmatch' => 'Match text pattern',
                    'jme' => 'Mathematical expression',
                    'gapfill' => 'Gap-fill'
                );
                ksort($questions, SORT_NUMERIC);
                foreach($questions as $qn => $question) {
                    $rows = array();
                    $qs = $suspend_data['questions'][$qn];
                    $qname = $qs['name'];

                    $header = \html_writer::start_tag('h3');
                    $header .= get_string('questionx', 'scormreport_numbas', $qn+1);
                    $header .= ' - ' . $qname;
                    $header .=  \html_writer::end_tag('h3');
                    echo $header;

                    $table = new \flexible_table('mod-scorm-report');
                    $columns = array('part', 'type', 'student_answer', 'correct_answer', 'score', 'marks');
                    $headers = array(
                        get_string('part', 'scormreport_numbas'), 
                        get_string('type', 'scormreport_numbas'), 
                        get_string('studentsanswer', 'scormreport_numbas'), 
                        get_string('correctanswer', 'scormreport_numbas'), 
                        get_string('score', 'scormreport_numbas'), 
                        get_string('marks', 'scormreport_numbas')
                    );
                    $table->define_baseurl($PAGE->url);
                    $table->define_columns($columns);
                    $table->define_headers($headers);
                    $table->set_attribute('id', 'attempt');
                    $table->setup();
                    ksort($question->parts, SORT_NUMERIC);
                    foreach($question->parts as $pn => $part) {
                        if(!array_key_exists($pn,$qs['parts'])) {
                            continue;
                        }
                        $ps = $suspend_data['questions'][$qn]['parts'][$pn];
                        $rows[] = array(
                            'suspend' => $ps,
                            'part' => $part
                        );
                        ksort($part->gaps, SORT_NUMERIC);
                        foreach($part->gaps as $gn => $gap) {
                            if(array_key_exists('gaps',$ps) && array_key_exists($gn,$ps['gaps'])) {
                                $gs = $ps['gaps'][$gn];
                            } else {
                                $gs = array('name' => $ps['name'] . " Gap $gn");
                            }
                            $rows[] = array(
                                'suspend' => $gs,
                                'part' => $gap,
                                'indent' => 1
                            );
                        }
                        ksort($part->steps, SORT_NUMERIC);
                        foreach($part->steps as $sn => $step) {
                            if(!array_key_exists($sn,$ps['steps'])) {
                                continue;
                            }
                            $ss = $ps['steps'][$sn];
                            $rows[] = array(
                                'suspend' => $ss,
                                'part' => $step,
                                'indent' => 1
                            );
                        }
                    }
                    foreach($rows as $row) {
                        $ps = $row['suspend'];
                        $part = $row['part'];
                        $name = $ps ? $ps['name'] : '';
                        if(substr($name,0,strlen($qname))==$qname) {
                            $name = substr($name,strlen($qname));
                        }
                        if(array_key_exists('indent',$row)) {
                            $name = '&nbsp;&nbsp;' . $name;
                        }
                        $type = $part->type;
                        if(array_key_exists($type,$part_type_names)) {
                            $type = $part_type_names[$type];
                        }
                        $table->add_data(array(
                            $name,
                            $type,
                            '<code>' . $part->student_answer . '</code>',
                            '<code>' . $part->correct_answer . '</code>',
                            $part->score, 
                            $part->marks
                        ));
                    }
                    $table->finish_output();
                }
            }
        }

    }

    /** Portions of this function copied from the scormreport_interactions module by Dan Marsden and Ankit Kumar Agarwal
     */
    private function show_table() {
        global $CFG, $DB, $OUTPUT, $PAGE;

        $contextmodule = \context_module::instance($this->cm->id);
        $attemptid = optional_param_array('attemptid', array(), PARAM_RAW);

        // Find out current groups mode.
        $currentgroup = groups_get_activity_group($this->cm, true);


        $pagesize = 20;

        $nostudents = false;

        // All users who can attempt scoes.
        if (!$students = get_users_by_capability($contextmodule, 'mod/scorm:savetrack', 'u.id', '', '', '', '', '', false)) {
            echo $OUTPUT->notification(get_string('nostudentsyet'));
            $nostudents = true;
            $allowedlist = '';
        } else {
            $allowedlist = array_keys($students);
        }
        unset($students);

        if ( !$nostudents ) {
            // Now check if asked download of data.
            $coursecontext = \context_course::instance($this->course->id);

            // Define table columns.
            $columns = array();
            $headers = array();
            $columns[] = 'fullname';
            $headers[] = get_string('name');

            $extrafields = get_extra_user_fields($coursecontext);
            foreach ($extrafields as $field) {
                $columns[] = $field;
                $headers[] = get_user_field_name($field);
            }
            $columns[] = 'attempt';
            $headers[] = get_string('attempt', 'scorm');
            $columns[] = 'start';
            $headers[] = get_string('started', 'scorm');
            $columns[] = 'finish';
            $headers[] = get_string('last', 'scorm');
            $columns[] = 'score';
            $headers[] = get_string('score', 'scorm');

            $params = array();
            list($usql, $params) = $DB->get_in_or_equal($allowedlist, SQL_PARAMS_NAMED);
            // Construct the SQL.
            $select = 'SELECT DISTINCT '.$DB->sql_concat('u.id', '\'#\'', 'COALESCE(st.attempt, 0)').' AS uniqueid, ';
            $select .= 'st.scormid AS scormid, st.attempt AS attempt, ' .
                    \user_picture::fields('u', array('idnumber'), 'userid') .
                    get_extra_user_fields_sql($coursecontext, 'u', '', array('email', 'idnumber')) . ' ';

            // This part is the same for all cases - join users and scorm_scoes_track tables.
            $from = 'FROM {user} u ';
            $from .= 'LEFT JOIN {scorm_scoes_track} st ON st.userid = u.id AND st.scormid = '.$this->scorm->id;
            // Show only students with attempts.
            $where = ' WHERE u.id ' .$usql. ' AND st.userid IS NOT NULL';

            $countsql = 'SELECT COUNT(DISTINCT('.$DB->sql_concat('u.id', '\'#\'', 'COALESCE(st.attempt, 0)').')) AS nbresults, ';
            $countsql .= 'COUNT(DISTINCT('.$DB->sql_concat('u.id', '\'#\'', 'st.attempt').')) AS nbattempts, ';
            $countsql .= 'COUNT(DISTINCT(u.id)) AS nbusers ';
            $countsql .= $from.$where;

            $table = new \flexible_table('mod-scorm-report');

            $table->define_columns($columns);
            $table->define_headers($headers);
            $table->define_baseurl($PAGE->url);

            $table->sortable(true);

            // This is done to prevent redundant data, when a user has multiple attempts.
            $table->column_suppress('fullname');
            foreach ($extrafields as $field) {
                $table->column_suppress($field);
            }

            $table->no_sorting('start');
            $table->no_sorting('finish');
            $table->no_sorting('score');

            $table->column_class('fullname', 'bold');
            $table->column_class('score', 'bold');

            $table->set_attribute('cellspacing', '0');
            $table->set_attribute('id', 'attempts');
            $table->set_attribute('class', 'generaltable generalbox');

            // Start working -- this is necessary as soon as the niceties are over.
            $table->setup();

            $sort = $table->get_sql_sort();
            // Fix some weird sorting.
            if (empty($sort)) {
                $sort = ' ORDER BY uniqueid';
            } else {
                $sort = ' ORDER BY '.$sort;
            }

            // Add extra limits due to initials bar.
            list($twhere, $tparams) = $table->get_sql_where();
            if ($twhere) {
                $where .= ' AND '.$twhere; // Initial bar.
                $params = array_merge($params, $tparams);
            }

            if (!empty($countsql)) {
                $count = $DB->get_record_sql($countsql, $params);
                $totalinitials = $count->nbresults;
                if ($twhere) {
                    $countsql .= ' AND '.$twhere;
                }
                $count = $DB->get_record_sql($countsql, $params);
                $total  = $count->nbresults;
            }

            $table->pagesize($pagesize, $total);

            echo \html_writer::start_div('scormattemptcounts');
            if ( $count->nbresults == $count->nbattempts ) {
                echo get_string('reportcountattempts', 'scorm', $count);
            } else if ( $count->nbattempts > 0 ) {
                echo get_string('reportcountallattempts', 'scorm', $count);
            } else {
                echo $count->nbusers.' '.get_string('users');
            }
            echo \html_writer::end_div();

            // Fetch the attempts.
            $attempts = $DB->get_records_sql($select.$from.$where.$sort, $params,
            $table->get_page_start(), $table->get_page_size());
            echo \html_writer::start_div('', array('id' => 'scormtablecontainer'));
            $table->initialbars($totalinitials > 20); // Build table rows.
            if ($attempts) {
                foreach ($attempts as $scouser) {
                    $row = array();
                    if (!empty($scouser->attempt)) {
                        $timetracks = scorm_get_sco_runtime($this->scorm->id, false, $scouser->userid, $scouser->attempt);
                    } else {
                        $timetracks = '';
                    }
                    $url = new \moodle_url('/user/view.php', array('id' => $scouser->userid, 'course' => $this->course->id));
                    $row[] = \html_writer::link($url, fullname($scouser));
                    foreach ($extrafields as $field) {
                        $row[] = s($scouser->{$field});
                    }
                    if (empty($timetracks->start)) {
                        $row[] = '-';
                        $row[] = '-';
                        $row[] = '-';
                        $row[] = '-';
                    } else {
                        $url = new \moodle_url($PAGE->url,
                            array(
                                'action' => 'viewattempt',
                                'user' => $scouser->userid,
                                'attempt' => $scouser->attempt
                            )
                        );
                        $row[] = \html_writer::link($url, $scouser->attempt);
                        $row[] = userdate($timetracks->start);
                        $row[] = userdate($timetracks->finish);
                        $row[] = scorm_grade_user_attempt($this->scorm, $scouser->userid, $scouser->attempt);
                    }

                    $table->add_data($row);
                }
            }
            $table->finish_output();
            echo \html_writer::end_div();
        } else {
            echo $OUTPUT->notification(get_string('noactivity', 'scorm'));
        }
    }
}
