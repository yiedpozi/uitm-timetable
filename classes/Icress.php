<?php

namespace Yiedpozi\UitmTimetable;

require __DIR__ . '/../config.php';

use Yiedpozi\UitmTimetable\Request;

class Icress {

    private $icress_url = 'http://icress.uitm.edu.my/jadual';

    // Credits to https://github.com/afzafri/UiTM-Timetable-Generator
    private $non_selangor_campus_ids = array('AR', 'SI', 'JK', 'MA', 'SG', 'SP', 'AG', 'KP', 'BT', 'SA', 'KK', 'DU');

    private $config;

    public function __construct() {
        $this->config = require __DIR__ . '/../config.php';
    }

    // Get timetable for all specified subjects and groups
    public function all($campus_id, array $subjects) {

        $result = [];
        foreach ($subjects as $subject) {
            $group = NULL;

            // subject|class
            if (strpos($subject, '|')) {
                list($subject, $group) = array_map('trim', explode('|', $subject));
            }

            // Skip if doesn't have group specified
            if (!$group) {
                continue;
            }

            // Empty the previous data first to prevent duplicate data when subject not found
            $data = NULL;

            // For Selangor campus, we will check the subjects referrer to get faculty ID
            if (strtolower($campus_id) == 'kampus selangor') {
                $selangor_faculties = $this->get_selangor_faculties();
                $selangor_subjects_referrer = $this->get_selangor_subjects_referrer();

                // We will go through each Selangor faculty to check for the subjects.
                foreach ($selangor_subjects_referrer as $selangor_faculty_id => $selangor_subjects) {
                    // If the subject is available on current $selangor_faculty_id,
                    // we will check ICReSS to check if the specified group is also exists for the specified subject
                    if (in_array($subject, $selangor_subjects)) {
                        $data = $this->get($selangor_faculty_id, $subject, $group);

                        // When we found the correct timetable, we will break the loop and continue for next flow.
                        if ($data) {
                            break;
                        }
                    }
                }
            } else {
                $data = $this->get($campus_id, $subject, $group);
            }

            // Skip if timetable for specified subject not found
            if (empty($data)) {
                continue;
            }

            $result = array_merge($result, $data);

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

        $filename = 'FACULTIES.dat';
        $faculties = $this->get_data($filename);

        // If file not exist, get data from ICReSS site
        if (!$faculties) {
            $url   = "{$this->icress_url}/jadual/jadual.asp";
            $regex = '/<option value.*?>(.*?)<\/option>/si';

            $faculties = $this->put_data($filename, $url, $regex);
        }

        if (!$faculties) {
            return false;
        }

        $campuses = [];
        $selangor = [];

        // Credits to https://github.com/afzafri/UiTM-Timetable-Generator for the logic
        foreach ($faculties as $faculty) {
            $faculty_id = substr($faculty, 0, 2);

            if (in_array($faculty_id, $this->non_selangor_campus_ids)) {
                // Non-selangor campus
                $campuses[] = strtoupper($faculty);
            } else {
                // For selangor campus, we only store the ID, so we only check for the ID later
                $selangor[] = strtoupper($faculty_id);
            }

        }

        $filename = 'SELANGOR_FACULTIES.dat';
        $path = $this->config['folder_path']['data'].'/'.$filename;
        if (!file_exists($path) || $this->is_file_expired($filename)) {
            file_put_contents($path, json_encode($selangor));
        }

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
        $subjects = $this->get_data($filename);

        // If file not exist, get data from ICReSS site
        if (!$subjects) {
            $url  = "{$this->icress_url}/{$campus_id}/{$campus_id}.html";
            $regex = '/<a href.*?>(.*?)<\/a>/si';

            $subjects = $this->put_data($filename, $url, $regex);
        }

        if (!$subjects) {
            return false;
        }

        return array_map(function($subject) {
            return strip_tags($subject);
        }, $subjects);

    }

    // Get Selangor subjects
    private function get_selangor_subjects() {

        $selangor_faculties = $this->get_selangor_faculties();

        $subjects = [];

        // Go through each faculty, get the subjects
        foreach ($selangor_faculties as $faculty_id) {
            $filename = $faculty_id.'.dat';
            $faculty = $this->get_data($filename);

            // If file not exist, get data from ICReSS site
            if (!$faculty) {
                $url  = "{$this->icress_url}/{$faculty_id}/{$faculty_id}.html";
                $regex = '/<a href.*?>(.*?)<\/a>/si';

                $faculty = $this->put_data($filename, $url, $regex);
            }

            if ($faculty) {
                $subjects[$faculty_id] = $faculty;
            }
        }

        return $subjects;

    }

    // Get Selangor faculties
    private function get_selangor_faculties() {

        // We will check if we already store list of faculties for Selangor campuses
        // If not exist, we will get it from ICReSS first
        $faculties = $this->get_data('SELANGOR_FACULTIES.dat');

        if (!$faculties) {
            return $this->get_campuses();
        }

        return $faculties;

    }

    // Store list of faculties with available subjects for Selangor campuses
    private function get_selangor_subjects_referrer() {

        // We will check if we already store list of faculties with available subjects for Selangor campuses
        $filename = 'SELANGOR_REFERRER.dat';
        $subjects_referrer = $this->get_data($filename);

        if ($subjects_referrer) {
            return $subjects_referrer;
        }

        $faculties = $this->get_selangor_faculties();
        $subjects_referrer = [];

        // If not exist, we will get it from ICReSS first.
        // We will go through each faculty and get list of subjects.
        foreach ($faculties as $faculty_id) {
            list($code, $response) = Request::get("{$this->icress_url}/{$faculty_id}/{$faculty_id}.html");

            if ($code !== 200) {
                return;
            }

            preg_match_all('/<a href.*?>(.*?)<\/a>/si', $response, $result);

            $subjects_referrer[$faculty_id] = array_map(function($value) {
                return strip_tags($value);
            }, $result[0]);
        }

        $path = $this->config['folder_path']['data'].'/'.$filename;
        if (!file_exists($path) || $this->is_file_expired($filename)) {
            file_put_contents($path, json_encode($subjects_referrer));
        }

        return $subjects_referrer;

    }

    private function get_data($filename) {

        $path = $this->config['folder_path']['data'].'/'.$filename;
        if (file_exists($path) && !$this->is_file_expired($filename)) {
            return json_decode(file_get_contents($path), true);
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

        file_put_contents($this->config['folder_path']['data'].'/'.$filename, json_encode($response));

        return $response;

    }

    private function is_file_expired($filename) {

        $file = $this->config['folder_path']['data'].'/'.$filename;
        $expires = $this->config['cache_expires'] ?: 3600;

        return filemtime($file) < (time() - $expires);

    }

}
