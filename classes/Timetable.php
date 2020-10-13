<?php

namespace Yiedpozi\UitmTimetable;

$GLOBALS['config'] = require __DIR__ . '/../config.php';

class Timetable {

    private $faculty_id;
    private $subjects;

    public function __construct($faculty_id, array $subjects) {

        $this->faculty_id = $faculty_id;
        $this->subjects   = $subjects;

    }

    public function generate_html() {

        $result = '';
        $timetable = (new Icress())->all($this->faculty_id, $this->subjects);

        if (empty($timetable)) {
            return false;
        }

        $result = "<strong>📚 TIMETABLE</strong>\n\n";

        foreach ($timetable as $days => $schedules) {
            $result .= "<strong>📅 ".strtoupper($days)."</strong>\n";

            foreach ($schedules as $schedule) {
                $result .= $schedule["subject"]." 🕛 ".$schedule["start"]." - ".$schedule['end']."\n";
            }

            $result .= "\n";
        }

        return $result;

    }

}
