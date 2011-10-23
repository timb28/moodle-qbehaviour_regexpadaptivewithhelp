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
 * Question behaviour for regexp question type (with help).
 *
 * @package    qbehaviour
 * @subpackage regexpadaptivewithhelp
 * @copyright  2011 Tim Hunt & Joseph R�zeau
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once(dirname(__FILE__) . '/../adaptive/behaviour.php');

    class qbehaviour_regexpadaptivewithhelp extends qbehaviour_adaptive {
    const IS_ARCHETYPAL = false;

    public function required_question_definition_type() {
        return 'question_automatically_gradable';
    }

    /**
     * Get the most recently submitted step.
     * @return question_attempt_step
     */
    public function get_graded_step() {
        foreach ($this->qa->get_reverse_step_iterator() as $step) {
            if ($step->has_behaviour_var('_try')) {
                return $step;
            }
        }
    }

    public function get_expected_data() {
        $expected = parent::get_expected_data();
        if ($this->qa->get_state()->is_active()) {
            $expected['helpme'] = PARAM_BOOL;
        }
        return $expected;
    }

    public function process_action(question_attempt_pending_step $pendingstep) {
        if ($pendingstep->has_behaviour_var('helpme')) {
            return $this->process_helpme($pendingstep);
        } else {
            return parent::process_action($pendingstep);
        }
    }
    
    public function process_submit(question_attempt_pending_step $pendingstep) {
        $status = $this->process_save($pendingstep);

        $response = $pendingstep->get_qt_data();
        if (!$this->question->is_gradable_response($response)) {
            $pendingstep->set_state(question_state::$invalid);
            if ($this->qa->get_state() != question_state::$invalid) {
                $status = question_attempt::KEEP;
            }
            return $status;
        }

        $prevstep = $this->qa->get_last_step_with_behaviour_var('_try');
        $prevresponse = $prevstep->get_qt_data();
        $prevtries = $this->qa->get_last_behaviour_var('_try', 0);
        $prevbest = $pendingstep->get_fraction();
        if (is_null($prevbest)) {
            $prevbest = 0;
        }

        // added 'helpme' condition so question attempt would not be DISCARDED when student asks for help        
        if ($this->question->is_same_response($response, $prevresponse) && !$pendingstep->has_behaviour_var('helpme') ) {
            return question_attempt::DISCARD;
        }
        
        list($fraction, $state) = $this->question->grade_response($response);

        $pendingstep->set_fraction(max($prevbest, $this->adjusted_fraction($fraction, $prevtries)));
        if ($prevstep->get_state() == question_state::$complete) {
            $pendingstep->set_state(question_state::$complete);
        } else if ($state == question_state::$gradedright) {
            $pendingstep->set_state(question_state::$complete);
        } else {
            $pendingstep->set_state(question_state::$todo);
        }
        $pendingstep->set_behaviour_var('_try', $prevtries + 1);
        $pendingstep->set_behaviour_var('_rawfraction', $fraction);
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));

        return question_attempt::KEEP;
    }
    
    public function summarise_action(question_attempt_step $step) {
        if ($step->has_behaviour_var('helpme')) {
            return $this->summarise_helpme($step);
        } else {
            return parent::summarise_action($step);
        }
    }

    public function summarise_helpme(question_attempt_step $step) {
        return get_string('submittedwithhelp', 'qbehaviour_regexpadaptivewithhelp',
                $this->question->summarise_response_withhelp($step->get_qt_data()));
    }

    protected function adjusted_fraction($fraction, $prevtries, $helpnow = 0) {
        $numhelps = $this->qa->get_last_behaviour_var('_helps') + $helpnow;
        return $fraction - $this->question->penalty * ($prevtries - $numhelps) -
                $this->question->penalty * $numhelps;
    }

    public function process_helpme(question_attempt_pending_step $pendingstep) {
        $keep = $this->process_submit($pendingstep);

        if ($keep == question_attempt::KEEP && $pendingstep->get_state() != question_state::$invalid) {
            $prevtries = $this->qa->get_last_behaviour_var('_try', 0);
            $prevhelps = $this->qa->get_last_behaviour_var('_help', 0);
            $prevbest = $this->qa->get_fraction();
            if (is_null($prevbest)) {
                $prevbest = 0;
            }
            $fraction = $pendingstep->get_behaviour_var('_rawfraction');

            $pendingstep->set_fraction(max($prevbest, $this->adjusted_fraction($fraction, $prevtries, 1)));
            $pendingstep->set_behaviour_var('_helps', $prevhelps + 1);
        }

        return $keep;
    }

    /* 
     * $dp = display options decimal places (for penalty)
    */
    public function get_extra_help_if_requested($dp) {
        // Try to find the last graded step.
        $gradedstep = $this->get_graded_step($this->qa);
        $isstateimprovable = $this->qa->get_behaviour()->is_state_improvable($this->qa->get_state());
        if (is_null($gradedstep) || !$gradedstep->has_behaviour_var('helpme')) {
            return '';
        }
        $output = '';
        $addedletter = $this->get_added_letter($gradedstep);
        if ($addedletter) {
            $output.= get_string('addedletter', 'qbehaviour_regexpadaptivewithhelp', $addedletter);
        }
        $penalty = $this->question->penalty;
        if ($isstateimprovable && $penalty > 0) {
            $nbtries = $gradedstep->get_behaviour_var('_try');
            $helppenalty = '';
            $totalpenalties = '';
            $helppenalty = $this->get_help_penalty($penalty, $dp, 'helppenalty');
            $totalpenalties = $this->get_help_penalty($nbtries * $penalty, $dp, 'totalpenalties');
            $output.= $helppenalty. $totalpenalties;
        }
        return $output;
    }
    
    public function get_help_penalty($penalty, $dp, $penaltystring) {
        $helppenalty = format_float($penalty, $dp);
        // if total of help penalties >= 1 then display total in red
        if ($helppenalty >= 1) {
        	$helppenalty = '<span class="flagged-tag">' .$helppenalty . '<span>';
        }
        $output = '';
        $output.= get_string($penaltystring, 'qbehaviour_regexpadaptivewithhelp', $helppenalty).' ';
        return $output;
    }
    
    public function get_added_letter($gradedstep) {
        /// Use text services (useful for string functions for non-ascii alphabets)
        $textlib = textlib_get_instance();
    	$data = $gradedstep->get_qt_data();
        $answer = $data['answer'];
        $closest = find_closest($this->question, $answer, $ispreview=false, $correct_response=false, $hintadded = true);
        $addedletter = '';
        if ($answer != $closest[0]) {
            $addedletter = $textlib->substr($closest[0], -1);
        }
        return $addedletter;
    }
    
}