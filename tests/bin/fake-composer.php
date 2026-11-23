<?php

declare(strict_types=1);

/**
 * Stand-in for Composer in ProcessComposerRunnerTest. Options before the
 * Composer arguments control its behaviour:
 *   --fake-exit=N    exit code (default 0)
 *   --fake-sleep=N   print the pid, then sleep N seconds
 * Without --fake-sleep it prints JSON with its arguments and a few environment
 * variables on stdout, and a fixed line on stderr.
 */

$exitCode = 0;
$sleep = 0;
$args = [];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--fake-exit=')) {
        $exitCode = (int) substr($arg, strlen('--fake-exit='));
    } elseif (str_starts_with($arg, '--fake-sleep=')) {
        $sleep = (int) substr($arg, strlen('--fake-sleep='));
    } else {
        $args[] = $arg;
    }
}

if ($sleep > 0) {
    fwrite(STDOUT, (string) getmypid());
    fflush(STDOUT);
    sleep($sleep);
    exit($exitCode);
}

$variables = [
    'SHELL_VERBOSITY',
    'COMPOSER_NO_INTERACTION',
    'COMPOSER_ROOT_VERSION',
    'COMPOSER_HOME',
    'COMPOSER_REPORT_TOKEN',
    'FAKE_COMPOSER_INHERITED',
];
$env = [];

foreach ($variables as $name) {
    $env[$name] = getenv($name);
}

fwrite(STDOUT, json_encode(['args' => $args, 'cwd' => getcwd(), 'env' => $env], JSON_THROW_ON_ERROR));
fwrite(STDERR, 'fake composer stderr');

exit($exitCode);
