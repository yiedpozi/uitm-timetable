<?php

namespace Yiedpozi\UitmTimetable;

$GLOBALS['config'] = require __DIR__ . '/../config.php';

use Dompdf\Dompdf;

class Timetable {

    private $icress_url = 'http://icress.uitm.edu.my/jadual';

    private $user_id;
    private $faculty_id;
    private $subjects;

    private $pb_faculty_id = 'PB';

    public function __construct($user_id, $faculty_id, array $subjects) {

        $this->user_id    = $user_id;
        $this->faculty_id = $faculty_id;
        $this->subjects   = $subjects;

    }

    public function generate_html() {

        $result = '';
        $timetable = $this->generate();

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

    // Generate timetable for all specified subjects and groups
    private function generate() {

        $result = [];
        foreach ($this->subjects as $subject) {
            $group = NULL;
            if (strpos($subject, '|')) {
                list($subject, $group) = explode('|', $subject);
            }

            $faculty_id = $this->get_faculty_id($subject);

            $data = $this->get($faculty_id, $subject, $group);

            if ($data) {
                $result = array_merge($result, $data);
            }
        }

        $result = array_group_by($result, 'day');
        array_reorder_keys($result, 'Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday');

        return $result;

    }

    // Get faculty ID based on subject
    private function get_faculty_id($subject) {

        $pb_subjects = $this->get_pb_subjects();

        // If Akademi Pengajian Bahasa subject, return PB (Pengajian Bahasa) as faculty ID
        if (in_array($subject, $pb_subjects)) {
            return $this->pb_faculty_id;
        }

        return $this->faculty_id;

    }

    private function get_pb_subjects() {

        // If already cache
        if (file_exists("./data/{$this->pb_faculty_id}.dat")) {
            return json_decode(file_get_contents("./data/{$this->pb_faculty_id}.dat"), true);
        }

        $url = "{$this->icress_url}/{$this->pb_faculty_id}/{$this->pb_faculty_id}.html";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $is_error = curl_error($ch);
        curl_close($ch);

        if ($status !== 200) {
            return;
        }

        // Get links, then get each rows
        preg_match_all('/<a href.*?>(.*?)<\/a>/si', $response, $links);

        $result = [];

        if (!empty($links[0])) {
            foreach ($links[0] as $link) {
                $result[] = strip_tags($link);
            }
        }

        file_put_contents("./data/{$this->pb_faculty_id}.dat", json_encode($result));

        return $result;

    }

    // Get timetable for specified subject and group
    private function get($faculty_id, $subject, $group = NULL) {

        $url = "{$this->icress_url}/{$faculty_id}/{$subject}.html";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $is_error = curl_error($ch);
        curl_close($ch);

        if ($status !== 200) {
            return;
        }

        // Get table, then get each rows
        preg_match_all('/<table.*?>(.*?)<\/table>/si', $response, $table);
        preg_match_all('/<tr>(.*?)<\/tr>/si', implode('', $table[0]), $rows);

        // Remove table header
        unset($rows[0][0]);

        // Re-arrange array index
        $rows[0] = array_values($rows[0]);

        $result = [];

        if (count($rows[0]) > 0) {
            for ($i=0; $i < count($rows[0]); $i++) {
                preg_match_all('/<td>(.*?)<\/td>/si', $rows[0][$i], $column);

                // Include result only if group is same with specified on parameter
                if ($group && $this->format_column($column[0][0]) == $group) {
                    $result[$i]['subject'] = $subject;
                    $result[$i]['group'] = $this->format_column($column[0][0]);
                    $result[$i]['start'] = $this->format_column($column[0][1]);
                    $result[$i]['end'] = $this->format_column($column[0][2]);
                    $result[$i]['day'] = $this->format_column($column[0][3]);
                    $result[$i]['mode'] = $this->format_column($column[0][4]);
                    $result[$i]['status'] = $this->format_column($column[0][5]);
                    $result[$i]['room'] = $this->format_column($column[0][6]);
                }
            }
        }

        return $result;

    }

    private function format_column($data) {
        return str_replace("\r\n", ' ', trim(strip_tags($data)));
    }

}
