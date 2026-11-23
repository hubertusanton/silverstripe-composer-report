<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Payload;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Payload\PayloadValidator;
use Webium\ComposerReport\Tests\Core\Fixture;

class PayloadValidatorTest extends SapphireTest
{
    protected $usesDatabase = false;

    /**
     * @return array<string, array{string}>
     */
    public static function provideValidPayloads(): array
    {
        return [
            'advisories'             => ['valid-advisories.json'],
            'clean'                  => ['valid-clean.json'],
            'audit failed'           => ['valid-partial-audit-failed.json'],
            'advisory without a CVE' => ['valid-advisory-without-cve.json'],
        ];
    }

    /**
     * @dataProvider provideValidPayloads
     */
    #[DataProvider('provideValidPayloads')]
    public function testValidPayloads(string $file): void
    {
        $payload = Fixture::json('payloads/' . $file);
        $validator = new PayloadValidator();

        $this->assertSame([], $validator->validateEnvelope($payload));
        $this->assertTrue($validator->validateSections($payload)->isValid());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideInvalidEnvelopes(): array
    {
        return [
            'not an object'             => ['invalid-not-object.json', 'payload must be a JSON object'],
            'schema version too new'    => ['invalid-schema-version.json', 'schema_version must be an integer'],
            'project not a slug'        => ['invalid-project.json', 'project must be a slug'],
            'generated_at not ATOM'     => ['invalid-generated-at.json', 'generated_at must be a date in ATOM'],
            'environment key missing'   => ['invalid-environment.json', 'environment.hostname is missing'],
            'errors not a list'         => ['invalid-errors.json', 'errors must be a list'],
            'section is a string'       => ['invalid-section-type.json', 'outdated must be null or a JSON object'],
            'section missing'           => ['invalid-missing-section.json', 'audit is missing'],
            'no sections and no errors' => ['invalid-no-sections-no-errors.json', 'errors must explain why both'],
        ];
    }

    /**
     * @dataProvider provideInvalidEnvelopes
     */
    #[DataProvider('provideInvalidEnvelopes')]
    public function testInvalidEnvelopes(string $file, string $expected): void
    {
        $errors = (new PayloadValidator())->validateEnvelope(Fixture::json('payloads/' . $file));

        $this->assertSame([$expected], array_map(
            static fn (string $error): string => substr($error, 0, strlen($expected)),
            $errors,
        ));
    }

    public function testEveryInvalidFixtureIsCovered(): void
    {
        $files = array_map('basename', glob(Fixture::path('payloads/invalid-*.json')) ?: []);
        $covered = array_column(self::provideInvalidEnvelopes(), 0);

        sort($files);
        sort($covered);

        $this->assertSame($files, $covered);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideInvalidProjects(): array
    {
        return [
            'too long'         => [str_repeat('a', 65)],
            'leading hyphen'   => ['-project'],
            'double hyphen'    => ['my--project'],
            'trailing hyphen'  => ['project-'],
            'uppercase'        => ['Project'],
            'underscore'       => ['my_project'],
            'trailing newline' => ["project\n"],
            'integer'          => [123],
            'empty'            => [''],
        ];
    }

    /**
     * @dataProvider provideInvalidProjects
     */
    #[DataProvider('provideInvalidProjects')]
    public function testInvalidProjects(mixed $project): void
    {
        $this->assertFalse(PayloadValidator::isValidSlug($project));
    }

    public function testValidProjects(): void
    {
        $this->assertTrue(PayloadValidator::isValidSlug('klant-x'));
        $this->assertTrue(PayloadValidator::isValidSlug('a'));
        $this->assertTrue(PayloadValidator::isValidSlug(str_repeat('a', 64)));
    }

    public function testEnvelopeDetails(): void
    {
        $validator = new PayloadValidator();
        $base = Fixture::json('payloads/valid-clean.json');
        $environment = $base['environment'];

        $invalid = [
            ['schema_version' => '1'],
            ['schema_version' => 0],
            ['generated_at' => '2026-02-31T03:00:00+01:00'],
            ['environment' => ['php_version' => 8] + $environment],
            ['environment' => ['hostname' => str_repeat('h', 256)] + $environment],
            ['errors' => array_fill(0, 51, 'x')],
            ['errors' => [str_repeat('x', 2001)]],
            ['errors' => [1]],
        ];

        foreach ($invalid as $change) {
            $this->assertNotSame([], $validator->validateEnvelope($change + $base), json_encode($change) ?: '');
        }

        $this->assertNotSame([], $validator->validateEnvelope([]));

        $valid = [
            ['environment' => ['hostname' => null] + $environment],
            ['unknown_key' => ['anything']],
            ['outdated' => null, 'audit' => null, 'errors' => ['both failed']],
        ];

        foreach ($valid as $change) {
            $this->assertSame([], $validator->validateEnvelope($change + $base), json_encode($change) ?: '');
        }
    }

    public function testInvalidSectionIsSeparateFromTheEnvelope(): void
    {
        $payload = Fixture::json('payloads/valid-invalid-section.json');
        $validator = new PayloadValidator();

        $this->assertSame([], $validator->validateEnvelope($payload));

        $sections = $validator->validateSections($payload);

        $this->assertNull($sections->outdatedError);
        $this->assertSame(
            'audit section rejected: advisory example/package[0] needs a string affectedVersions',
            $sections->auditError,
        );

        $applied = $sections->apply($payload);

        $this->assertNull($applied['audit']);
        $this->assertSame($payload['outdated'], $applied['outdated']);
        $this->assertSame([$sections->auditError], $applied['errors']);
        $this->assertSame([], $validator->validateEnvelope($applied));
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function provideOutdatedSections(): array
    {
        return [
            'empty lock'           => [[], true],
            'empty object'         => [json_decode('{}', true), true],
            'nothing outdated'     => [['locked' => []], true],
            'installed'            => [['installed' => [['name' => 'a/b', 'version' => '1.0']]], true],
            'list without key'     => [[['name' => 'a/b', 'version' => '1.0']], false],
            'locked not a list'    => [['locked' => ['a' => 'b']], false],
            'item without version' => [['locked' => [['name' => 'a/b']]], false],
            'neither key'          => [['packages' => []], false],
        ];
    }

    /**
     * @dataProvider provideOutdatedSections
     */
    #[DataProvider('provideOutdatedSections')]
    public function testOutdatedSection(mixed $outdated, bool $valid): void
    {
        $sections = (new PayloadValidator())->validateSections(['outdated' => $outdated, 'audit' => null]);

        $this->assertSame($valid, $sections->outdatedError === null, (string) $sections->outdatedError);
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function provideAuditSections(): array
    {
        $advisory = ['title' => 't', 'packageName' => 'a/b', 'affectedVersions' => '<1'];
        $withNulls = $advisory + ['cve' => null, 'severity' => null];
        $withoutTitle = array_diff_key($advisory, ['title' => 1]);
        $composer211 = ['advisories' => [], 'abandoned' => [], 'filter' => [], 'cooldown' => []];

        return [
            'no advisories'         => [['advisories' => []], true],
            'empty object'          => [['advisories' => json_decode('{}', true)], true],
            'minimal advisory'      => [['advisories' => ['a/b' => [$advisory]]], true],
            'nulls allowed'         => [['advisories' => ['a/b' => [$withNulls]]], true],
            'unknown keys allowed'  => [$composer211, true],
            'no advisories key'     => [['abandoned' => []], false],
            'advisories is a list'  => [['advisories' => [[$advisory]]], false],
            'package is not a list' => [['advisories' => ['a/b' => $advisory]], false],
            'missing title'         => [['advisories' => ['a/b' => [$withoutTitle]]], false],
            'non-string cve'        => [['advisories' => ['a/b' => [$advisory + ['cve' => 123]]]], false],
            'not an object'         => ['audit', false],
        ];
    }

    /**
     * @dataProvider provideAuditSections
     */
    #[DataProvider('provideAuditSections')]
    public function testAuditSection(mixed $audit, bool $valid): void
    {
        $sections = (new PayloadValidator())->validateSections(['outdated' => null, 'audit' => $audit]);

        $this->assertSame($valid, $sections->auditError === null, (string) $sections->auditError);
    }

    public function testGeneratedFixturesAndLegacyShapesAreValidSections(): void
    {
        $validator = new PayloadValidator();
        $pairs = [
            ['composer/vulnerable.outdated.json', 'composer/vulnerable.audit.json'],
            ['composer/abandoned-only.outdated.json', 'composer/abandoned-only.audit.json'],
            ['composer/clean.outdated-direct.json', 'composer/clean.audit.json'],
            ['composer/empty.outdated.json', null],
            ['composer-legacy/outdated-2.5.json', 'composer-legacy/audit-2.5.json'],
        ];

        foreach ($pairs as [$outdated, $audit]) {
            $sections = $validator->validateSections([
                'outdated' => Fixture::json($outdated),
                'audit'    => $audit === null ? null : Fixture::json($audit),
            ]);

            $this->assertTrue($sections->isValid(), $outdated . ': ' . implode(', ', $sections->errors()));
        }
    }
}
