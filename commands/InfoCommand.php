<?php

namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\ChatAction;

class InfoCommand extends UserCommand {

    protected $name = 'info';
    protected $description = 'UiTM Timetable information.';
    protected $usage = '/info';
    protected $version = '1.0.0';
    protected $private_only = true;

    public function execute(): ServerResponse {

        $message = $this->getMessage();
        $user_id = $message->getFrom()->getId();
        $chat_id = $message->getChat()->getId();
        $text    = trim($message->getText(true));

        $data = [
            'chat_id'      => $chat_id,
            // Remove any keyboard by default
            'reply_markup' => Keyboard::remove(['selective' => true]),
        ];

        Request::sendChatAction([
            'chat_id' => $data['chat_id'],
            'action'  => ChatAction::TYPING,
        ]);

        $data['text'] = "🎓 UiTM Timetable 🎓\n\n".
                        "📚 Credits to: ICReSS (http://icress.uitm.edu.my)\n".
                        "👍 Powered by https://yiedpozi.my\n\n";

        $data['parse_mode'] = 'html';

        return Request::sendMessage($data);

    }

}
