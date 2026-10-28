<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Capture\Metadata;

use Composer\InstalledVersions;
use Lemonade\Profiler\Profile;

/**
 * Captures allowlisted PHP and server metadata without retaining deployment text.
 * @phpstan-import-type Section from Profile
 */
final class ServerMetadata
{
    use SanitizesMetadata;

    /**
     * Records a bounded allowlist of server and PHP runtime values without exposing deployment text.
     * @return Section
     */
    public static function capture(string $environment, mixed $software = null): array
    {
        $values = [
            'web_server' => is_string($software) && preg_match('~^(Apache|nginx|Caddy|LiteSpeed|Microsoft-IIS)(?:/([0-9.]{1,24}))?(?: |$)~D', $software, $server) === 1
                ? $server[1] . (isset($server[2]) ? '/' . $server[2] : '') : null,
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'environment' => preg_match('/^[a-zA-Z0-9_.-]{1,160}$/D', $environment) === 1 ? $environment : null,
            'timezone' => self::safe(date_default_timezone_get()),
            'framework_version' => class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('johnnyxlemonade/framework')
                ? self::safe(InstalledVersions::getPrettyVersion('johnnyxlemonade/framework')) : null,
        ];
        foreach (['memory_limit', 'max_execution_time', 'upload_max_filesize', 'post_max_size'] as $key) {
            $value = ini_get($key);
            $values[$key] = is_string($value) && preg_match('/^-?\d+[KMG]?$/iD', $value) === 1 ? $value : null;
        }
        // Retain only recognized server product/version, never the remaining deployment text.
        $entries = [];
        $extensions = get_loaded_extensions();
        sort($extensions);
        foreach (array_slice($extensions, 0, 256) as $name) {
            $safe = self::safe($name);
            if ($safe !== null) {
                $entries[] = ['name' => $safe, 'version' => self::safe(phpversion($name)), 'dependency' => null];
            }
        }
        return ['values' => $values, 'entries' => $entries];
    }
}
