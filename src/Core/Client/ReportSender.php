<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Client;

use Webium\ComposerReport\Core\Security\Secret;

interface ReportSender
{
    public function send(
        string $endpoint,
        string $project,
        #[\SensitiveParameter] Secret $token,
        string $json,
        float $timeout,
    ): SendResult;
}
