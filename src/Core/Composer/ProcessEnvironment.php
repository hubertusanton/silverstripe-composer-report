<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Composer;

/**
 * Environment overrides for the Composer child process. Keys win over the
 * inherited environment; false removes a variable from the child.
 */
final class ProcessEnvironment
{
    /**
     * Never passed on to Composer, in case a host sets them as real OS variables.
     */
    private const SECRET_VARIABLES = [
        'COMPOSER_REPORT_TOKEN',
        'SS_DATABASE_PASSWORD',
        'SS_DEFAULT_ADMIN_PASSWORD',
    ];

    /**
     * @param string|null $composerRootVersion  set when the inherited env has none; null disables
     * @param string|null $composerHomeFallback directory for COMPOSER_HOME when HOME and COMPOSER_HOME are missing
     */
    public function __construct(
        private readonly ?string $composerRootVersion = 'dev-main',
        private readonly ?string $composerHomeFallback = null,
    ) {
    }

    /**
     * @param array<string, string> $inherited the environment the child would inherit
     * @param list<string>          $warnings  filled with problems for the payload's errors list
     * @return array<string, string|false>
     */
    public function overrides(array $inherited, array &$warnings = []): array
    {
        $overrides = [
            // Otherwise "-q" of the parent leaks in as -1 and Composer stays silent
            'SHELL_VERBOSITY'         => '0',
            'COMPOSER_NO_INTERACTION' => '1',
        ];

        foreach (self::SECRET_VARIABLES as $name) {
            $overrides[$name] = false;
        }

        if ($this->composerRootVersion !== null && !$this->isSet($inherited, 'COMPOSER_ROOT_VERSION')) {
            $overrides['COMPOSER_ROOT_VERSION'] = $this->composerRootVersion;
        }

        if (!$this->isSet($inherited, 'HOME') && !$this->isSet($inherited, 'COMPOSER_HOME')) {
            $problem = $this->composerHomeFallback === null
                ? 'HOME and COMPOSER_HOME are not set and there is no fallback directory'
                : $this->checkFallbackDirectory($this->composerHomeFallback);

            if ($problem === null) {
                $overrides['COMPOSER_HOME'] = $this->composerHomeFallback;
            } else {
                $warnings[] = 'COMPOSER_HOME not set: ' . $problem;
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, string> $env
     */
    private function isSet(array $env, string $name): bool
    {
        return isset($env[$name]) && $env[$name] !== '';
    }

    /**
     * Creates the directory with 0700 when needed. Null when it is safe to use,
     * otherwise the reason it is not.
     */
    private function checkFallbackDirectory(string $directory): ?string
    {
        if (is_link($directory)) {
            return 'the fallback directory is a symlink';
        }

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return 'the fallback directory could not be created';
        }

        clearstatcache(true, $directory);

        if (is_link($directory) || !is_dir($directory)) {
            return 'the fallback directory is not a plain directory';
        }

        $permissions = fileperms($directory);

        if ($permissions === false || ($permissions & 0077) !== 0) {
            return 'the fallback directory is accessible to other users';
        }

        if (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid()) {
            return 'the fallback directory is owned by another user';
        }

        if (!is_writable($directory)) {
            return 'the fallback directory is not writable';
        }

        return null;
    }
}
