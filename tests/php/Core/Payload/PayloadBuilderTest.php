<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Payload;

use DateTimeImmutable;
use DateTimeZone;
use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Composer\ParsedSection;
use Webium\ComposerReport\Core\Payload\EnvironmentInfo;
use Webium\ComposerReport\Core\Payload\PayloadBuilder;
use Webium\ComposerReport\Core\Payload\PayloadValidator;
use Webium\ComposerReport\Tests\Core\Fixture;

class PayloadBuilderTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testShapeOfVersion1(): void
    {
        $outdated = Fixture::json('composer/vulnerable.outdated-direct.json');
        $audit = Fixture::json('composer/vulnerable.audit.json');

        $payload = $this->build(ParsedSection::ok($outdated), ParsedSection::ok($audit));

        $this->assertSame(
            ['schema_version', 'project', 'generated_at', 'environment', 'outdated', 'audit', 'errors'],
            array_keys($payload),
        );
        $this->assertSame(1, $payload['schema_version']);
        $this->assertSame('example-project', $payload['project']);
        $this->assertSame('2026-11-21T03:00:00+01:00', $payload['generated_at']);
        $this->assertSame(
            [
                'php_version'            => '8.3.36',
                'composer_version'       => '2.11.2',
                'silverstripe_framework' => '6.3.0',
                'hostname'               => 'web01',
            ],
            $payload['environment'],
        );
        $this->assertSame($outdated, $payload['outdated']);
        $this->assertSame($audit, $payload['audit']);
        $this->assertSame([], $payload['errors']);
        $this->assertSame([], (new PayloadValidator())->validateEnvelope($payload));
        $this->assertTrue((new PayloadValidator())->validateSections($payload)->isValid());
    }

    public function testErrorsFromSections(): void
    {
        $payload = $this->build(
            ParsedSection::failed('outdated failed'),
            ParsedSection::failed('audit failed', ['cache warning']),
            ['proc_open disabled', 'audit failed'],
        );

        $this->assertNull($payload['outdated']);
        $this->assertNull($payload['audit']);
        $this->assertSame(
            ['proc_open disabled', 'audit failed', 'outdated failed', 'cache warning'],
            $payload['errors'],
        );
        $this->assertSame([], (new PayloadValidator())->validateEnvelope($payload));
    }

    public function testErrorsAreCapped(): void
    {
        $errors = [];
        for ($i = 0; $i < 60; $i++) {
            $errors[] = 'error ' . $i . ' ' . str_repeat('é', 2500);
        }

        $payload = $this->build(ParsedSection::ok([]), ParsedSection::ok(['advisories' => []]), $errors);

        $this->assertCount(PayloadBuilder::MAX_ERRORS, $payload['errors']);
        $this->assertSame(PayloadBuilder::MAX_ERROR_LENGTH, mb_strlen($payload['errors'][0]));
        $this->assertSame([], (new PayloadValidator())->validateEnvelope($payload));
    }

    public function testEmptyStructuresStayEmptyArraysInJson(): void
    {
        $builder = new PayloadBuilder();
        $payload = $this->build(ParsedSection::ok([]), ParsedSection::ok(Fixture::json('composer/clean.audit.json')));

        $decoded = json_decode($builder->encode($payload));

        $this->assertSame([], $decoded->outdated);
        $this->assertSame([], $decoded->audit->advisories);
        $this->assertSame([], $decoded->errors);
    }

    public function testOffsetOfTheGenerationTime(): void
    {
        $payload = (new PayloadBuilder())->build(
            'example-project',
            $this->environment(),
            ParsedSection::ok([]),
            ParsedSection::ok(['advisories' => []]),
            [],
            new DateTimeImmutable('2026-06-01 03:00:00', new DateTimeZone('Europe/Amsterdam')),
        );

        $this->assertSame('2026-06-01T03:00:00+02:00', $payload['generated_at']);
    }

    public function testInvalidUtf8DoesNotCrash(): void
    {
        $builder = new PayloadBuilder();
        $payload = $this->build(
            ParsedSection::failed("stderr with \xC3\x28 invalid bytes"),
            ParsedSection::ok(['advisories' => []]),
        );

        $json = $builder->encode($payload);

        $this->assertJson($json);
        $this->assertStringContainsString('stderr with', $json);
    }

    public function testEncodeIsCompactUnlessPretty(): void
    {
        $builder = new PayloadBuilder();
        $payload = $this->build(
            ParsedSection::ok([]),
            ParsedSection::ok(['advisories' => []]),
            ['see https://example.com/a/b'],
        );

        $this->assertStringNotContainsString("\n", $builder->encode($payload));
        $this->assertStringContainsString("\n", $builder->encode($payload, true));
        $this->assertStringContainsString('https://example.com/a/b', $builder->encode($payload));
    }

    public function testEnvironmentInfo(): void
    {
        $detected = EnvironmentInfo::detect('2.11.2')->toArray();

        $this->assertSame(PHP_VERSION, $detected['php_version']);
        $this->assertSame('2.11.2', $detected['composer_version']);
        $this->assertNotNull($detected['silverstripe_framework']);

        $long = new EnvironmentInfo(str_repeat('x', 300), null, null, null);
        $this->assertSame(EnvironmentInfo::MAX_LENGTH, mb_strlen((string) $long->toArray()['php_version']));
        $this->assertNull($long->toArray()['hostname']);
    }

    /**
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    private function build(ParsedSection $outdated, ParsedSection $audit, array $errors = []): array
    {
        return (new PayloadBuilder())->build(
            'example-project',
            $this->environment(),
            $outdated,
            $audit,
            $errors,
            new DateTimeImmutable('2026-11-21T03:00:00+01:00'),
        );
    }

    private function environment(): EnvironmentInfo
    {
        return new EnvironmentInfo('8.3.36', '2.11.2', '6.3.0', 'web01');
    }
}
