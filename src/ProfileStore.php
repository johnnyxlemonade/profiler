<?php

declare(strict_types=1);

namespace Lemonade\Profiler;

use Lemonade\Framework\Filesystem\Contract\DirectoryManagerInterface;
use Lemonade\Framework\Filesystem\Contract\FileManagerInterface;
use Lemonade\Framework\Filesystem\Contract\LockManagerInterface;
use RuntimeException;

/**
 * Publishes bounded JSON snapshots atomically under one local directory lock.
 */
final readonly class ProfileStore
{
    /**
     * Uses framework filesystem capabilities and a private application writable directory.
     */
    public function __construct(
        private string $directory,
        private string $publicRoot,
        private ProfilerConfig $config,
        private DirectoryManagerInterface $directories,
        private FileManagerInterface $files,
        private LockManagerInterface $locks,
    ) {}

    /**
     * Saves one complete snapshot; oversized profiles fail before any file is written.
     */
    public function save(Profile $profile): void
    {
        $json = json_encode($profile->data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > $this->config->maxProfileBytes || !self::validId($profile->data['id'])) {
            throw new RuntimeException('Profile exceeds storage limits.');
        }
        $this->prepare();
        $this->locks->lock($this->directory . '/.lock', function () use ($profile, $json): void {
            $this->directories->write($this->filename($profile->data['id']), $json, 0600);
            $this->prune();
        });
    }

    /**
     * Returns a retained snapshot only for a generated identifier.
     */
    public function find(string $id): ?Profile
    {
        if (!self::validId($id)) {
            return null;
        }
        foreach ($this->history() as $profile) {
            if ($profile->data['id'] === $id) {
                return $profile;
            }
        }
        return null;
    }

    /**
     * Lists retained snapshots newest first, pruning expired records on access as well as writes.
     * @return list<Profile>
     */
    public function history(): array
    {
        $this->prepare();
        return $this->locks->lock($this->directory . '/.lock', fn(): array => $this->prune());
    }

    /**
     * @return list<Profile>
     */
    private function prune(): array
    {
        $profiles = [];
        $threshold = (int) ((microtime(true) - $this->config->retentionSeconds) * 1000000);
        foreach ($this->directories->find('*.json', $this->directory) as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            if (!self::validId($id) || is_link($file) || !is_file($file)) {
                continue;
            }
            $profile = $this->files->size($file) <= $this->config->maxProfileBytes
                ? Profile::decode($this->files->read($file))
                : null;
            if ($profile === null || $profile->data['id'] !== $id || $profile->data['created_at'] < $threshold) {
                $this->directories->delete($file);
                continue;
            }
            $profiles[] = $profile;
        }
        usort($profiles, static fn(Profile $left, Profile $right): int => $right->data['created_at'] <=> $left->data['created_at']);
        foreach (array_slice($profiles, $this->config->maxProfiles) as $profile) {
            $this->directories->delete($this->filename($profile->data['id']));
        }
        return array_slice($profiles, 0, $this->config->maxProfiles);
    }

    private function prepare(): void
    {
        if (is_link($this->directory) || is_link($this->directory . '/.lock')) {
            throw new RuntimeException('Profiler storage must be a private local directory.');
        }
        $this->directories->create($this->directory, 0700);
        $resolved = realpath($this->directory);
        $public = realpath($this->publicRoot);
        if ($resolved === false || $public === false || $resolved === $public || str_starts_with($resolved, $public . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Profiler storage must be outside the public root.');
        }
        if (!chmod($this->directory, 0700)) {
            throw new RuntimeException('Profiler storage permissions could not be restricted.');
        }
    }

    private function filename(string $id): string
    {
        return $this->directory . '/' . $id . '.json';
    }

    private static function validId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{32}$/D', $id) === 1;
    }
}
