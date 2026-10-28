<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Presentation;

use Lemonade\Framework\Observability\Http\HttpCheckpoint;
use RuntimeException;
use stdClass;

/**
 * Reads the package dictionaries and translates presentation text without global locale state.
 */
final class ProfilerTranslator
{
    /**
     * @var array<string, array<string, string>>
     */
    private static array $dictionaries = [];

    /**
     * Selects the dictionary used by this presentation instance without changing global locale state.
     */
    public function __construct(private readonly ProfilerLocale $locale = ProfilerLocale::En) {}

    /**
     * Returns the supported language selected for this instance.
     */
    public function locale(): ProfilerLocale
    {
        return $this->locale;
    }

    /**
     * Loads the supported dictionaries for client-side presentation.
     * @return array<string, array<string, string>>
     */
    public static function dictionaries(): array
    {
        $dictionaries = [];
        foreach (ProfilerLocale::cases() as $locale) {
            $dictionaries[$locale->value] = self::load($locale);
        }
        return $dictionaries;
    }

    /**
     * Explicit supported choice wins; otherwise the highest positive header quality wins.
     * Equal qualities retain header order; no supported choice falls back to English.
     */
    public static function choose(?string $explicit, string $acceptLanguage = ''): ProfilerLocale
    {
        $locale = self::normalize($explicit);
        if ($locale !== null) {
            return $locale;
        }
        $locale = ProfilerLocale::En;
        $bestQuality = 0.0;
        foreach (explode(',', substr($acceptLanguage, 0, 512)) as $part) {
            if (preg_match('/^\s*([a-z]+(?:-[a-z0-9]+)*)(?:\s*;\s*q\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?))?\s*$/iD', $part, $matches) !== 1) {
                continue;
            }
            $candidate = self::normalize($matches[1]);
            $quality = isset($matches[2]) ? (float) $matches[2] : 1.0;
            if ($candidate !== null && $quality > $bestQuality) {
                $locale = $candidate;
                $bestQuality = $quality;
            }
        }
        return $locale;
    }

    /**
     * Reduces supported regional tags to their base language; unknown tags remain unresolved.
     */
    private static function normalize(?string $locale): ?ProfilerLocale
    {
        if ($locale === null || preg_match('/^([a-z]+)(?:-[a-z0-9]+)*$/iD', trim($locale), $matches) !== 1) {
            return null;
        }
        return ProfilerLocale::tryFrom(strtolower($matches[1]));
    }

    /**
     * Translates one key with English and then the key itself as fallbacks.
     * @param array<string, string|int|float> $parameters
     */
    public function translate(string $key, array $parameters = []): string
    {
        $message = self::load($this->locale)[$key] ?? self::load(ProfilerLocale::En)[$key] ?? $key;
        $replace = [];
        foreach ($parameters as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }
        return strtr($message, $replace);
    }

    /**
     * Formats SQL query counts using the profiler's existing Czech and English forms.
     */
    public function queryCount(int $count): string
    {
        $one = $count === 1 || ($this->locale === ProfilerLocale::Cs && $count % 10 === 1 && $count % 100 !== 11);
        $few = $this->locale === ProfilerLocale::Cs && ($count % 100 < 12 || $count % 100 > 14)
            && $count % 10 >= 2 && $count % 10 <= 4;
        $key = $one ? 'common.query_one' : ($few ? 'common.query_few' : 'common.queries');
        return $this->translate($key, ['count' => $count]);
    }

    /**
     * Applies Czech and English numeral forms to the reference sample count.
     */
    public function previousRequestCount(int $count): string
    {
        $key = match (true) {
            $count === 1 => 'comparison.previous_one',
            $this->locale === ProfilerLocale::Cs && $count >= 2 && $count <= 4 => 'comparison.previous_few',
            default => 'comparison.previous_many',
        };
        return $this->translate($key, ['count' => $count]);
    }

    /**
     * Maps technical phase IDs to shared dictionary keys without interpreting display text.
     */
    public static function phaseKey(string $phase): string
    {
        return match ($phase) {
            HttpCheckpoint::RequestStarted->value => 'timeline.phase.configuration',
            HttpCheckpoint::BootstrapStarted->value => 'timeline.phase.bootstrap',
            HttpCheckpoint::ProvidersBooted->value => 'timeline.phase.routes',
            HttpCheckpoint::RouteMatchStarted->value => 'timeline.phase.dispatch',
            HttpCheckpoint::ControllerResolutionStarted->value => 'timeline.phase.controller_resolution',
            HttpCheckpoint::ControllerActionStarted->value => 'timeline.phase.controller_action',
            HttpCheckpoint::ControllerActionFinished->value => 'timeline.phase.response',
            'render' => 'timeline.phase.render',
            default => 'timeline.measured',
        };
    }

    /**
     * Reads and caches a bundled JSON object containing only string keys and values.
     * @return array<string, string>
     */
    private static function load(ProfilerLocale $locale): array
    {
        if (!isset(self::$dictionaries[$locale->value])) {
            $json = file_get_contents(__DIR__ . '/../../resources/i18n/' . $locale->value . '.json');
            if ($json === false) {
                throw new RuntimeException('Profiler translations unavailable.');
            }
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            if (!$decoded instanceof stdClass) {
                throw new RuntimeException('Profiler translations must be a JSON object.');
            }
            $dictionary = [];
            foreach (get_object_vars($decoded) as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new RuntimeException('Profiler translations must contain string keys and values.');
                }
                $dictionary[$key] = $value;
            }
            self::$dictionaries[$locale->value] = $dictionary;
        }
        return self::$dictionaries[$locale->value];
    }
}
