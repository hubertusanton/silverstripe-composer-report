<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Payload;

use Composer\InstalledVersions;

final class EnvironmentInfo
{
    public const MAX_LENGTH = 255;

    public function __construct(
        public readonly ?string $phpVersion,
        public readonly ?string $composerVersion,
        public readonly ?string $silverstripeFramework,
        public readonly ?string $hostname,
    ) {
    }

    public static function detect(?string $composerVersion): self
    {
        $framework = InstalledVersions::isInstalled('silverstripe/framework')
            ? InstalledVersions::getPrettyVersion('silverstripe/framework')
            : null;
        $hostname = gethostname();

        return new self(
            PHP_VERSION,
            $composerVersion,
            $framework,
            $hostname === false || $hostname === '' ? null : $hostname,
        );
    }

    /**
     * @return array<string, ?string> php_version, composer_version, silverstripe_framework, hostname
     */
    public function toArray(): array
    {
        return [
            'php_version'            => self::limit($this->phpVersion),
            'composer_version'       => self::limit($this->composerVersion),
            'silverstripe_framework' => self::limit($this->silverstripeFramework),
            'hostname'               => self::limit($this->hostname),
        ];
    }

    private static function limit(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, self::MAX_LENGTH);
    }
}
