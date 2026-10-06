<?php

namespace App\Contracts;

interface PushNotification
{
    /**
     * @return array{title: string, body: string, url: string, type: string}
     */
    public function pushPayload(): array;
}
