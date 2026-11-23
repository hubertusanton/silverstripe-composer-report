<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Fakes;

use Webium\ComposerReport\Core\Client\ReportSender;
use Webium\ComposerReport\Core\Client\SendResult;
use Webium\ComposerReport\Core\Security\Secret;

/**
 * Records what would be sent and returns a prepared result.
 */
final class FakeReportSender implements ReportSender
{
    /**
     * @var list<array{endpoint: string, project: string, token: string, json: string, timeout: float}>
     */
    public array $sent = [];

    public function __construct(
        private readonly ?SendResult $result = null,
    ) {
    }

    public function send(
        string $endpoint,
        string $project,
        #[\SensitiveParameter] Secret $token,
        string $json,
        float $timeout,
    ): SendResult {
        $this->sent[] = [
            'endpoint' => $endpoint,
            'project'  => $project,
            'token'    => $token->reveal(),
            'json'     => $json,
            'timeout'  => $timeout,
        ];

        return $this->result ?? SendResult::delivered(202, 'Report sent to monitor (HTTP 202).');
    }
}
