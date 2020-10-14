<?php

require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';

try {

    $telegram = new Longman\TelegramBot\Telegram($config['token'], $config['username']);

    // Set webhook
    $result = $telegram->setWebhook($config['webhook_url']);
    if ($result->isOk()) {
        echo $result->getDescription();
    }

} catch (Longman\TelegramBot\Exception\TelegramException $e) {
    throw new Exception($e->getMessage());
}
