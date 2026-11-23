<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Client;

use Webium\ComposerReport\Core\Composer\CommandResult;
use Webium\ComposerReport\Core\Composer\ComposerOutputParser;
use Webium\ComposerReport\Core\Composer\ComposerRunner;
use Webium\ComposerReport\Core\Composer\ParsedSection;
use Webium\ComposerReport\Core\Payload\EnvironmentInfo;
use Webium\ComposerReport\Core\Payload\PayloadBuilder;
use Webium\ComposerReport\Core\Payload\PayloadValidator;
use Webium\ComposerReport\Core\Security\TextSanitizer;

/**
 * Runs Composer, builds and checks the payload, and sends it. Once the
 * config is valid the payload is always sent, also when Composer failed
 * (the sections are then null and errors explains why).
 */
final class ReportClient
{
    public function __construct(
        private readonly ComposerRunner $runner,
        private readonly ReportSender $sender,
        private readonly PayloadBuilder $builder,
        private readonly PayloadValidator $validator,
        private readonly ComposerOutputParser $parser,
        private readonly TextSanitizer $sanitizer,
    ) {
    }

    /**
     * @return int one of the ExitCode constants
     */
    public function run(ClientConfig $config, bool $dryRun, OutputSink $out, \DateTimeImmutable $now): int
    {
        $configErrors = $config->validate($dryRun);

        if ($configErrors !== []) {
            foreach ($configErrors as $error) {
                $out->error($this->clean($error, $config));
            }

            return ExitCode::CONFIG_ERROR;
        }

        [$outdated, $audit, $composerVersion, $errors] = $this->runComposer($config, $out);

        $payload = $this->builder->build(
            (string) $config->project,
            EnvironmentInfo::detect($composerVersion),
            $outdated,
            $audit,
            $errors,
            $now,
        );
        // All errors, including those of the sections, pass the token masking
        $payload['errors'] = PayloadBuilder::limitErrors(array_map(
            fn (string $error): string => $this->clean($error, $config),
            $payload['errors'],
        ));
        $composerFailed = !$outdated->isAvailable() || !$audit->isAvailable();

        // Self-check with the same rules as the monitor
        $envelopeErrors = $this->validator->validateEnvelope($payload);

        if ($envelopeErrors !== []) {
            $out->error($this->clean(
                'Internal error, the payload is invalid and was not sent: ' . implode('; ', $envelopeErrors),
                $config,
            ));

            return ExitCode::COMPOSER_ERROR;
        }

        $sections = $this->validator->validateSections($payload);

        if (!$sections->isValid()) {
            $payload = $sections->apply($payload);
            $composerFailed = true;
        }

        foreach ($payload['errors'] as $error) {
            $out->error($this->clean($error, $config));
        }

        try {
            $json = $this->builder->encode($payload, $dryRun);
        } catch (\JsonException $exception) {
            $out->error('Internal error, the payload could not be encoded: ' . $exception->getMessage());

            return ExitCode::COMPOSER_ERROR;
        }

        if ($dryRun) {
            $out->payload($json);

            return $composerFailed ? ExitCode::COMPOSER_ERROR : ExitCode::SUCCESS;
        }

        $result = $this->sender->send(
            (string) $config->endpoint,
            (string) $config->project,
            $config->token ?? throw new \LogicException('validate() guarantees a token'),
            $json,
            $config->httpTimeout,
        );

        if (!$result->delivered) {
            $out->error($this->clean($result->message, $config));

            return ExitCode::HTTP_ERROR;
        }

        $out->info($this->clean($result->message, $config));

        return $composerFailed ? ExitCode::COMPOSER_ERROR : ExitCode::SUCCESS;
    }

    /**
     * @return array{ParsedSection, ParsedSection, ?string, list<string>}
     */
    private function runComposer(ClientConfig $config, OutputSink $out): array
    {
        $unavailable = $this->runner->unavailableReason();

        if ($unavailable !== null) {
            // The builder drops the duplicate, so the reason appears once
            return [ParsedSection::failed($unavailable), ParsedSection::failed($unavailable), null, []];
        }

        $version = $this->runner->version();
        $this->logCommand($version, $out, $config);

        $outdatedResult = $this->runner->outdated($config->directOnly);
        $this->logCommand($outdatedResult, $out, $config);
        $outdated = $this->parser->parseOutdated($outdatedResult);

        $auditResult = $this->runner->audit();
        $this->logCommand($auditResult, $out, $config);
        // A bare [] from outdated means the lock file has no packages
        $audit = $this->parser->parseAudit($auditResult, $outdated->data === []);

        return [$outdated, $audit, $this->parser->parseVersion($version), $this->runner->environmentWarnings()];
    }

    private function logCommand(CommandResult $result, OutputSink $out, ClientConfig $config): void
    {
        if (!$out->isVerbose()) {
            return;
        }

        $status = $result->failure ?? 'exit ' . $result->exitCode;

        $line = sprintf('$ %s (%s, %.1f s)', $result->commandLine(), $status, $result->duration);
        $out->verbose($this->clean($line, $config));

        if (trim($result->stderr) !== '') {
            $out->verbose($this->clean('stderr: ' . trim($result->stderr), $config));
        }

        if (trim($result->stdout) !== '') {
            $out->verbose($this->clean('stdout: ' . trim($result->stdout), $config));
        }
    }

    /**
     * Sanitized, and the configured token is masked even when it lacks the usual prefix.
     */
    private function clean(string $text, ClientConfig $config): string
    {
        $token = $config->token?->reveal();

        if ($token !== null && $token !== '') {
            $text = str_replace($token, '***', $text);
        }

        return $this->sanitizer->redact($text);
    }
}
