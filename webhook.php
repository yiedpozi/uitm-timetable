<?php

require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';

try {

    $telegram = new Longman\TelegramBot\Telegram($config['token'], $config['username']);
    $telegram->addCommandsPath($config['folder_path']['command']);
    $telegram->enableMySql($config['mysql']);
    $telegram->handle();

} catch (Longman\TelegramBot\Exception\TelegramException $e) {
    Longman\TelegramBot\TelegramLog::error($e);
} catch (Longman\TelegramBot\Exception\TelegramLogException $e) {}
