<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Capture\Metadata;

use Lemonade\Framework\Session\Config\SessionConfig;
use Lemonade\Framework\Session\Contract\SessionInterface;
use Lemonade\Profiler\Profile;

/**
 * Observes session configuration and state without starting or changing the session.
 * @phpstan-import-type Section from Profile
 */
final class SessionMetadata
{
    use SanitizesMetadata;

    /**
     * Observes the application session, or PHP's native session when no facade was resolved.
     * @return Section
     */
    public static function capture(?SessionInterface $session = null, ?SessionConfig $config = null): array
    {
        $native = function_exists('session_status') ? session_status() : PHP_SESSION_DISABLED;
        if ($session === null && $native === PHP_SESSION_ACTIVE) {
            $config = null;
        }
        $custom = $config !== null && $config->driver() !== 'native';
        $active = $session !== null ? $session->started() : $native === PHP_SESSION_ACTIVE;
        $status = $custom && $session === null ? 'unavailable' : ($active ? 'active' : ($native === PHP_SESSION_DISABLED && !$custom ? 'disabled' : 'inactive'));
        $cookie = function_exists('session_get_cookie_params') ? session_get_cookie_params() : [];
        return ['values' => [
            'status' => $status,
            'handler' => self::safe($config !== null && $config->driver() !== 'native' ? $config->driver() : ini_get('session.save_handler')),
            'items' => $active && $native === PHP_SESSION_ACTIVE && !$custom && isset($_SESSION) ? (string) count($_SESSION) : null,
            'lifetime' => $config !== null ? (string) $config->lifetime() : self::safe(ini_get('session.gc_maxlifetime')),
            'cookie_lifetime' => $config !== null ? (string) $config->lifetime() : (isset($cookie['lifetime']) ? (string) $cookie['lifetime'] : null),
            'secure' => $config !== null ? $config->cookie()->secure()->value : (isset($cookie['secure']) ? ($cookie['secure'] ? 'yes' : 'no') : null),
            'httponly' => $config !== null ? 'yes' : (isset($cookie['httponly']) ? ($cookie['httponly'] ? 'yes' : 'no') : null),
            'samesite' => self::safe($config?->cookie()->sameSite()->value ?? ($cookie['samesite'] ?? null)),
        ], 'entries' => []];
    }
}
