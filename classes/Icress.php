<?php

namespace Yiedpozi\UitmTimetable;

use Yiedpozi\UitmTimetable\Request;

class Icress {

    private $icress_url = 'http://icress.uitm.edu.my/jadual';

    // Credits to https://github.com/afzafri/UiTM-Timetable-Generator
    private $non_selangor_campus_ids = array('AR', 'SI', 'JK', 'MA', 'SG', 'SP', 'AG', 'KP', 'BT', 'SA', 'KK', 'DU');

    // Get timetable for all specified subjects and groups
    public function all($campus_id, array $subjects) {

        $result = [];
        foreach ($subjects as $subject) {
            $group = NULL;

            // subject|class
            if (strpos($subject, '|')) {
                list($subject, $group) = explode('|', $subject);
            }

            // Skip if doesn't have group specified
            if (!$group) {
                continue;
            }

            // For Selangor campus, PI, PB and HP subjects can be retrieved by using
            if (strtolower($campus_id) == 'kampus selangor' && $selangor_faculty_id = $this->get_selangor_faculty_by_subject($subject)) {
                $data = $this->get($selangor_faculty_id, $subject, $group);
            } else {
                $data = $this->get($campus_id, $subject, $group);
            }

            if ($data) {
                $result = array_merge($result, $data);
            }

            // Sort by class start time
            usort($result, function($a, $b) {
                return (strtotime($a['start']) > strtotime($b['start']));
            });
        }

        // Group and sort by day
        $result = array_group_by($result, 'day');
        array_reorder_keys($result, 'Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday');

        return $result;

    }

    // Get timetable for specified subject and group
    private function get($campus_id, $subject, $group) {

        list($code, $response) = Request::get("{$this->icress_url}/{$campus_id}/{$subject}.html");

        if ($code !== 200) {
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
                if (strtolower($this->format_data($column[0][0])) == strtolower($group)) {
                    $result[$i]['subject'] = $subject;
                    $result[$i]['group'] = $this->format_data($column[0][0]);
                    $result[$i]['start'] = $this->format_data($column[0][1]);
                    $result[$i]['end'] = $this->format_data($column[0][2]);
                    $result[$i]['day'] = $this->format_data($column[0][3]);
                    $result[$i]['mode'] = $this->format_data($column[0][4]);
                    $result[$i]['status'] = $this->format_data($column[0][5]);
                    $result[$i]['room'] = $this->format_data($column[0][6]);
                }
            }
        }

        return $result;

    }

    private function format_data($data) {
        return str_replace("\r\n", ' ', trim(strip_tags($data)));
    }

    public function get_campuses() {

        $filename = 'campuses.dat';
        $data = $this->get_data($filename);

        // If file not exist, get data from ICReSS site
        if (!$data) {
            $url   = "{$this->icress_url}/jadual/jadual.asp";
            $regex = '/<option value.*?>(.*?)<\/option>/si';
            $data  = $this->put_data($filename, $url, $regex);
        }

        if (!$data) {
            return false;
        }

        $campuses = [];
        $selangor = [];

        // Credits to https://github.com/afzafri/UiTM-Timetable-Generator for the logic
        foreach ($data as $campus) {
            $campus_id = substr($campus, 0, 2);

            if (in_array($campus_id, $this->non_selangor_campus_ids)) {
                // Non-selangor campus
                $campuses[] = strtoupper($campus);
            } else {
                // For selangor campus, we only store the ID, so we only check for the ID later
                $selangor[] = strtoupper($campus_id);
            }

        }

        file_put_contents('./data/SELANGOR_FACULTIES.dat', json_encode($selangor));

        // Add Selangor in list because current $campuses array exclude all selangor faculties
        $campuses[] = 'KAMPUS SELANGOR';

        return $campuses;

    }

    public function get_subjects($campus_id) {

        // Check if campus selected is Selangor
        if ($campus_id == 'kampus selangor') {
            return $this->get_selangor_subjects();
        }

        $filename = $campus_id.'.dat';
        $data = $this->get_data($filename);

        // If file not exist, get data from ICReSS site
        if (!$data) {
            $url  = "{$this->icress_url}/{$campus_id}/{$campus_id}.html";
            $regex = '/<a href.*?>(.*?)<\/a>/si';
            $data  = $this->put_data($filename, $url, $regex);
        }

        if (!$data) {
            return false;
        }

        $subjects = array_map(function($subject) {
            return strip_tags($subject);
        }, $data);

        return $subjects;

    }

    // Get Selangor faculty by subject
    private function get_selangor_faculty_by_subject($subject) {

        $selangor_subjects = $this->get_selangor_subjects();

        foreach ($selangor_subjects as $faculty_id => $subjects) {
            // Search in Selangor subjects array, if has the subject specified, then return the faculty ID
            if (in_array($subject, $subjects)) {
                return $faculty_id;
            }
        }

        return false;

    }

    // Get Selangor subjects
    // PI - Pusat Pemikiran Dan Kefahaman Islam
    // PB - Akademi Pengajian Bahasa
    // HP - Bahagian Hal Ehwal Pelajar (Co-Curriculum)
    // Special case as for other campuses, PI, PB and HP subjects already appeared on their faculty
    private function get_selangor_subjects() {

        $filename = './data/SELANGOR_FACULTIES.dat';
        if (!file_exists($filename)) {
            $this->get_campuses();
        }

        $selangor_faculty_ids = json_decode(file_get_contents($filename), true);

        $subjects = [];

        // Go through each faculty, get the subjects
        foreach ($selangor_faculty_ids as $faculty_id) {
            $filename = $faculty_id.'.dat';
            $data = $this->get_data($filename);

            // If file not exist, get data from ICReSS site
            if (!$data) {
                $url  = "{$this->icress_url}/{$faculty_id}/{$faculty_id}.html";
                $regex = '/<a href.*?>(.*?)<\/a>/si';
                $data  = $this->put_data($filename, $url, $regex);
            }

            if ($data) {
                $subjects[$faculty_id] = $data;
            }
        }

        return $subjects;

    }

    private function get_data($filename) {

        if (file_exists('./data/'.$filename)) {
            return json_decode(file_get_contents('./data/'.$filename), true);
        }

        return false;

    }

    private function put_data($filename, $url, $regex = NULL, $strip_tags = true) {

        list($code, $response) = Request::get($url);

        if ($code !== 200) {
            return false;
        }

        // In case we need to use regex to format the data received from ICReSS site
        if ($regex) {
            preg_match_all($regex, $response, $result);

            // Format data received from the page (remove HTML tag)
            if ($strip_tags) {
                $response = array_map(function($value) {
                    return strip_tags($value);
                }, $result[0]);
            }
        }

        file_put_contents('./data/'.$filename, json_encode($response));

        return $response;

    }

}
