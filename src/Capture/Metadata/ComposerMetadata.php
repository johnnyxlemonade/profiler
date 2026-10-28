<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Capture\Metadata;

use Composer\InstalledVersions;
use JsonException;
use Lemonade\Profiler\Profile;

/**
 * Reads bounded installed-package metadata from the Composer runtime without executing commands.
 * @phpstan-import-type Section from Profile
 */
final class ComposerMetadata
{
    use SanitizesMetadata;

    /**
     * Lists installed package versions, classifying them only for a verified application root.
     * @return Section
     */
    public static function capture(?string $applicationRoot = null): array
    {
        if (!class_exists(InstalledVersions::class)) {
            return ['values' => ['status' => 'unavailable'], 'entries' => []];
        }
        $root = InstalledVersions::getRootPackage();
        $requirements = $applicationRoot === null ? null : self::requirements($applicationRoot, $root);
        $names = InstalledVersions::getInstalledPackages();
        sort($names);
        $entries = [];
        foreach (array_slice($names, 0, 500) as $name) {
            if (preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D', $name) !== 1 || strlen($name) > 160) {
                continue;
            }
            $dependency = null;
            if ($requirements !== null) {
                $dependency = array_key_exists($name, $requirements['require']) ? 'direct'
                    : (array_key_exists($name, $requirements['require-dev']) ? 'development' : 'transitive');
            }
            $entries[] = ['name' => $name, 'version' => self::safe(InstalledVersions::getPrettyVersion($name)), 'dependency' => $dependency];
        }
        return ['values' => [
            'status' => count($names) > 500 ? 'available_limited' : 'available',
            'application' => self::safe($root['name']),
            'dependency_classification' => $requirements === null ? null : 'available',
        ], 'entries' => $entries];
    }

    /**
     * Reads only requirement names from the manifest at the application root verified against Composer's root.
     * @param array<string, mixed> $installedRoot
     * @return array{require:array<string, string>, require-dev:array<string, string>}|null
     */
    private static function requirements(string $applicationRoot, array $installedRoot): ?array
    {
        $root = realpath($applicationRoot);
        $installedPath = $installedRoot['install_path'] ?? null;
        if ($root === false || !is_string($installedPath) || realpath($installedPath) !== $root) {
            return null;
        }
        $manifestPath = $root . DIRECTORY_SEPARATOR . 'composer.json';
        if (realpath($manifestPath) !== $manifestPath || !is_file($manifestPath)) {
            return null;
        }
        $contents = @file_get_contents($manifestPath, false, null, 0, 1048577);
        if ($contents === false || strlen($contents) > 1048576) {
            return null;
        }
        try {
            $manifest = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($manifest) || (isset($manifest['name']) && $manifest['name'] !== ($installedRoot['name'] ?? null))) {
            return null;
        }
        $require = array_key_exists('require', $manifest) ? $manifest['require'] : [];
        $requireDev = array_key_exists('require-dev', $manifest) ? $manifest['require-dev'] : [];
        if (!is_array($require) || !is_array($requireDev)) {
            return null;
        }
        foreach ([$require, $requireDev] as $section) {
            foreach ($section as $name => $constraint) {
                if (!is_string($name) || !is_string($constraint)) {
                    return null;
                }
            }
        }
        return ['require' => $require, 'require-dev' => $requireDev];
    }
}
