<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Storage;

use Lemonade\Framework\Filesystem\Contract\DirectoryManagerInterface;
use Lemonade\Framework\Filesystem\Contract\FileManagerInterface;

use Lemonade\Framework\Filesystem\Contract\LockManagerInterface;
use Lemonade\Profiler\Profile;
use Lemonade\Profiler\ProfilerConfig;
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
        $json = json_encode($profile->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > $this->config->maxProfileBytes()) {
            throw new RuntimeException('Profile exceeds storage limits.');
        }
        $this->prepare();
        $this->locks->lock($this->directory . '/.lock', function () use ($profile, $json): void {
            $this->directories->write($this->filename($profile->id()), $json, 0600);
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
            if ($profile->id() === $id) {
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
     * Deletes invalid, expired, and excess snapshots while returning retained profiles newest first.
     *
     * @return list<Profile>
     */
    private function prune(): array
    {
        $profiles = [];
        $threshold = (int) ((microtime(true) - $this->config->retentionSeconds()) * 1000000);
        foreach ($this->directories->find('*.json', $this->directory) as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            if (!self::validId($id) || is_link($file) || !is_file($file)) {
                continue;
            }
            $profile = $this->files->size($file) <= $this->config->maxProfileBytes()
                ? Profile::decode($this->files->read($file))
                : null;
            if ($profile === null || $profile->id() !== $id || $profile->createdAt() < $threshold) {
                $this->directories->delete($file);
                continue;
            }
            $profiles[] = $profile;
        }
        usort($profiles, static fn(Profile $left, Profile $right): int => $right->createdAt() <=> $left->createdAt());
        foreach (array_slice($profiles, $this->config->maxProfiles()) as $profile) {
            $this->directories->delete($this->filename($profile->id()));
        }
        return array_slice($profiles, 0, $this->config->maxProfiles());
    }

    /**
     * Creates private storage and rejects symlinks or directories inside the public root.
     */
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

    /**
     * Maps a generated profile ID to its JSON file within private storage.
     */
    private function filename(string $id): string
    {
        return $this->directory . '/' . $id . '.json';
    }

    /**
     * Accepts only a generated 32-character hexadecimal profile ID.
     */
    private static function validId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{32}$/D', $id) === 1;
    }
}
