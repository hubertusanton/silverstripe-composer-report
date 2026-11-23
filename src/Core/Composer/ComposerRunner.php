<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Composer;

interface ComposerRunner
{
    /**
     * Null when Composer can run, otherwise a readable reason.
     */
    public function unavailableReason(): ?string;

    /**
     * Problems with the process environment (e.g. no usable COMPOSER_HOME),
     * for the payload's errors list.
     *
     * @return list<string>
     */
    public function environmentWarnings(): array;

    public function version(): CommandResult;

    public function outdated(bool $directOnly): CommandResult;

    public function audit(): CommandResult;
}
