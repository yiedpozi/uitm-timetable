<?php

namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\ChatAction;
use Yiedpozi\UitmTimetable\Timetable;

class GenerateCommand extends UserCommand {

    protected $name = 'generate';
    protected $description = 'Generate UiTM timetable.';
    protected $usage = '/generate';
    protected $version = '1.0.0';
    protected $private_only = true;

    protected $conversation;

    public function execute(): ServerResponse {

        $message      = $this->getMessage();
        $user_id      = $message->getFrom()->getId();
        $chat_id      = $message->getChat()->getId();
        $current_text = trim($message->getText(true));

        // We will reset this variable in conversation, so the conversation can go step by step
        $text = $current_text;

        $data = [
            'chat_id'      => $chat_id,
            // Remove any keyboard by default
            'reply_markup' => Keyboard::remove(['selective' => true]),
        ];

        Request::sendChatAction([
            'chat_id' => $data['chat_id'],
            'action'  => ChatAction::TYPING,
        ]);

        $this->conversation = new Conversation($user_id, $chat_id, $this->getName());

        // Load any existing notes from this conversation
        $notes = &$this->conversation->notes;
        !is_array($notes) && $notes = [];

        // Load the current state of the conversation
        $state = $notes['state'] ?? 0;

        $result = Request::emptyResponse();

        // State machine
        // Every time a step is achieved the state is updated
        switch ($state) {
            case 0:
                // If user does not enter any input, start from this
                if ($text === '') {
                    $notes['state'] = 0;
                    $this->conversation->update();

                    $faculties_list = include './faculties_list.php';

                    $keyboards = new Keyboard(...$faculties_list);

                    $data['text'] = 'Select your faculty.';
                    $data['reply_markup'] = $keyboards->setResizeKeyboard(true)
                                                      ->setOneTimeKeyboard(true)
                                                      ->setSelective(false);

                    $result = Request::sendMessage($data);
                    break;
                }

                // If user already enter input, get current text and go to next step
                // Check if faculty ID entered by user is in full format (IS - FAKULTI PENGURUSAN MAKLUMAT)
                // If followed full format, get first 2 letter
                // 
                if (strlen($text) > 2 && substr($text, 2, 3) == ' - ') {
                    $notes['faculty'] = substr($text, 0, 2);
                } elseif (strlen($text) == 2) {
                    $notes['faculty'] = $text;
                } else {
                    $data['text'] = 'Wrong faculty input format. Please enter faculty ID (eg: IS for Fakulti Pengurusan Maklumat) or choose from list provided.';
                    $result = Request::sendMessage($data);

                    $this->conversation->stop();

                    return $result;
                }

                $text = '';

            case 1:
                if ($text === '') {
                    // Check if subjects found based on faculty ID
                    if (!$subjects = $this->get_subject($notes['faculty'])) {
                        $data['text'] = 'Sorry, there are no faculty found based on your input.';
                        $result = Request::sendMessage($data);

                        $this->conversation->stop();

                        return $result;
                    }

                    $notes['state'] = 1;
                    $this->conversation->update();

                    $data['text'] = "📕 Subjects List\n\n";
                    $data['text'] .= "```\n";

                    $i = 0;
                    foreach ($subjects as $subject) {
                        // Break line every 3 subject
                        if ($i && $i % 3 == 0) {
                            $data['text'] .= "|\n";
                        }
                        $data['text'] .= '| '.str_pad($subject, 9, ' ', STR_PAD_BOTH);

                        $i++;
                    }

                    $data['text'] .= '|```';

                    $data['parse_mode'] = 'Markdown';
                    Request::sendMessage($data);

                    $data['text'] = "👇👇👇👇👇👇👇👇👇👇👇👇👇👇👇👇\n\n".
                                    "Enter your subject and group using this format:\n\n".
                                    "<strong>subject|class</strong>\n".
                                    "*One line per subject\n\n".
                                    "Eg:\n".
                                    "ENT530|IM2443A\n".
                                    "IMS605|IM2455A";

                    $data['parse_mode'] = 'html';
                    $result = Request::sendMessage($data);
                    break;
                }

                $notes['subjects'] = explode("\n", $text);
                $text = '';

            case 2:
                $this->conversation->update();
                unset($notes['state']);

                $timetable = (new Timetable($user_id, $notes['faculty'], $notes['subjects']))->generate_html();

                $this->conversation->stop();

                if (!empty($timetable)) {
                    $data['text'] = $timetable;
                    $data['parse_mode'] = 'html';
                    $result = Request::sendMessage($data);
                    break;
                }

                $data['text'] = 'Sorry, there are no timetable found based on your input. Please use correct data based on format provided.';
                $result = Request::sendMessage($data);
                break;

        }

        return $result;

    }

    private function get_faculties_list() {
        return include './faculties_list.php';
    }

    private function get_subject($faculty_id) {

        $url = "http://icress.uitm.edu.my/jadual/{$faculty_id}/{$faculty_id}.html";

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

        preg_match_all('/<a href.*?>(.*?)<\/a>/si', $response, $url);

        $subjects = array_map(function($subject) {
            return strip_tags($subject);
        }, $url[0]);

        return $subjects;

    }

}
