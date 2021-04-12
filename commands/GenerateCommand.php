<?php

namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\ChatAction;
use Yiedpozi\UitmTimetable\Timetable;
use Yiedpozi\UitmTimetable\Icress;

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

                    $campuses = (new Icress())->get_campuses();

                    if ( !$campuses ) {
                        $data['text'] = 'Sorry! We are unable to retrieve the data. Please try again.';
                        $result = Request::sendMessage($data);

                        $this->conversation->stop();

                        return $result;
                    }

                    $keyboards = new Keyboard(...$campuses);

                    $data['text'] = 'Select your campus.';
                    $data['reply_markup'] = $keyboards->setResizeKeyboard(true)
                                                      ->setOneTimeKeyboard(true)
                                                      ->setSelective(false);

                    $result = Request::sendMessage($data);
                    break;
                }

                // If user already enter input, get current text and go to next step
                // Check if campus ID entered by user is in full format (KK-KAMPUS SABAH)
                // If followed full format, get first 2 letter
                // Special case for Selangor campus
                if (strtolower($text) == 'kampus selangor' || strlen($text) == 2) {
                    $notes['campus'] = strtolower($text);
                } elseif (strlen($text) > 2 && substr($text, 2, 1) == '-') {
                    $notes['campus'] = substr($text, 0, 2);
                } else {
                    $data['text'] = 'Wrong campus input format. Please enter campus ID, eg; MA or MA-(UiTM Kelantan [HEA/JW/05-2007), or choose from list provided.';
                    $result = Request::sendMessage($data);

                    $this->conversation->stop();

                    return $result;
                }

                $text = '';

            case 1:
                if ($text === '') {
                    // Check if subjects found based on campus ID
                    if (!$subjects = (new Icress())->get_subjects($notes['campus'])) {
                        $data['text'] = 'Sorry, there are no subjects found based on your input.';
                        $result = Request::sendMessage($data);

                        $this->conversation->stop();

                        return $result;
                    }

                    $notes['state'] = 1;
                    $this->conversation->update();

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

                $timetable = (new Timetable($notes['campus'], $notes['subjects']))->generate_html();

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

}
