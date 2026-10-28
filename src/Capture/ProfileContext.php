<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Capture;

use Lemonade\Framework\Observability\Http\HttpCheckpoint;
use Lemonade\Framework\Observability\Http\HttpMetrics;

use Lemonade\Framework\Routing\RouteMatch;
use Lemonade\Framework\Session\Config\SessionConfig;
use Lemonade\Framework\Session\Contract\SessionInterface;
use Lemonade\Profiler\Capture\Metadata\ComposerMetadata;
use Lemonade\Profiler\Capture\Metadata\ServerMetadata;
use Lemonade\Profiler\Capture\Metadata\SessionMetadata;
use Lemonade\Profiler\Profile;
use Lemonade\Profiler\ProfilerConfig;

/**
 * Holds sanitized measurements for one request scope, never template or request data.
 * @phpstan-import-type Query from Profile
 * @phpstan-import-type Rendering from Profile
 * @phpstan-import-type Section from Profile
 */
final class ProfileContext
{
    /**
     * @var array<string, Section>
     */
    private array $diagnostics = [];
    private ?SessionInterface $session = null;
    private bool $active;
    private ?HttpMetrics $metrics = null;
    private float $connectionMs = 0.0;
    /**
     * @var list<array{name:string, elapsed_ms:float}>
     */
    private array $marks = [];
    private int $renderCount = 0;
    /**
     * @var list<Query>
     */
    private array $queries = [];
    /**
     * @var list<Rendering>
     */
    private array $renderings = [];

    /**
     * Connects capture limits and the SQL lexer without resolving a request.
     */
    public function __construct(
        private readonly ProfilerConfig $config,
        private readonly SqlSanitizer $sanitizer,
        private readonly int|float|null $startedNs = null,
    ) {
        $this->active = $startedNs !== null;
    }

    /**
     * Retains only bounded, real checkpoints supplied by the framework.
     */
    public function checkpoint(HttpCheckpoint $checkpoint, float $elapsedMs): void
    {
        if ($this->active && count($this->marks) < 100) {
            $this->marks[] = ['name' => $checkpoint->value, 'elapsed_ms' => $elapsedMs];
        }
    }

    /**
     * Freezes capture with the authoritative summary; no request duration or SQL totals are recomputed.
     */
    public function completed(HttpMetrics $metrics): void
    {
        $this->metrics = $metrics;
        $this->active = false;
    }

    /**
     * Retains bounded sanitized details without maintaining another SQL count or duration aggregate.
     */
    public function queryCompleted(string $sql, float $elapsedMs, bool $successful): void
    {
        if (!$this->active || !is_finite($elapsedMs) || $elapsedMs < 0 || count($this->queries) >= $this->config->maxQueries()) {
            return;
        }
        $this->queries[] = [
            'order' => count($this->queries) + 1,
            'sql' => $this->sanitizer->sanitize(sql: $sql, maxLength: $this->config->maxSqlLength()),
            'elapsed_ms' => $elapsedMs,
            'slow' => $elapsedMs >= $this->config->slowQueryMs(),
        ];
    }

    /**
     * Retains connection setup time separately from the framework's authoritative SQL totals.
     */
    public function connectionCompleted(float $elapsedMs, bool $successful): void
    {
        if ($this->active && is_finite($elapsedMs) && $elapsedMs >= 0) {
            $this->connectionMs += $elapsedMs;
        }
    }

    /**
     * Begins one inclusive renderer interval, preserving invocation order for nesting.
     */
    public function beginRendering(string $template): ?int
    {
        if (!$this->active || count($this->renderings) >= $this->config->maxRenderings()) {
            return null;
        }
        $index = count($this->renderings);
        $this->renderings[] = [
            'order' => ++$this->renderCount,
            'template' => strlen($template) <= 256 && preg_match('/^[A-Za-z0-9_.:\/-]+$/D', $template) === 1 ? $template : '[unavailable]',
            'start_ms' => $this->elapsedMs(),
            'elapsed_ms' => 0.0,
            'completed' => false,
        ];
        return $index;
    }

    /**
     * Finishes an interval without retaining a rendered body, data or exception.
     */
    public function endRendering(?int $index, bool $completed): void
    {
        if ($index !== null && isset($this->renderings[$index])) {
            $this->renderings[$index]['elapsed_ms'] = max(0.0, $this->elapsedMs() - $this->renderings[$index]['start_ms']);
            $this->renderings[$index]['completed'] = $completed;
        }
    }

    /**
     * Creates an allowlisted snapshot; raw URI and bindings never cross this boundary.
     */
    public function snapshot(string $method, ?int $status, ?RouteMatch $match): Profile
    {
        $metrics = $this->metrics ?? throw new \LogicException('HTTP metrics have not been completed.');
        return new Profile([
            'id' => bin2hex(random_bytes(16)),
            'created_at' => (int) (microtime(true) * 1000000),
            'method' => preg_match('/^[A-Z]{1,16}$/D', $method) === 1 ? $method : 'UNKNOWN',
            'status' => $status,
            'route' => self::bounded($match?->name()),
            'path' => self::bounded($match?->path()),
            'controller' => self::bounded($match?->controller()),
            'action' => self::bounded($match?->action()),
            'duration_ms' => $metrics->durationMs(),
            'peak_bytes' => $metrics->phpPeakMemoryBytes(),
            'sql_count' => $metrics->sqlCount(),
            'sql_ms' => $metrics->sqlMs(),
            'connection_ms' => $this->connectionMs,
            'queries' => $this->queries,
            'renderings' => $this->renderings,
            'marks' => $this->marks,
            'diagnostics' => $this->diagnostics,
        ]);
    }

    /**
     * Remembers only an application-resolved session; never creates or starts it.
     */
    public function observeSession(SessionInterface $session): void
    {
        $this->session = $session;
    }

    /**
     * Captures state before request scope cleanup without reading session values.
     */
    public function captureSession(?SessionConfig $config = null): void
    {
        $this->diagnostics['session'] = SessionMetadata::capture($this->session, $config);
        $this->session = null;
    }

    /**
     * Captures bounded process and Composer metadata after the measured interval.
     */
    public function captureRuntime(string $environment, mixed $software = null, ?string $applicationRoot = null): void
    {
        $this->diagnostics['server'] = ServerMetadata::capture($environment, $software);
        $this->diagnostics['composer'] = ComposerMetadata::capture($applicationRoot);
    }

    /**
     * Measures an interval from the observer's start timestamp, or zero when none was supplied.
     */
    private function elapsedMs(): float
    {
        return $this->startedNs === null ? 0.0 : (hrtime(true) - $this->startedNs) / 1_000_000;
    }

    /**
     * Keeps only short, valid UTF-8 route metadata in a snapshot.
     */
    private static function bounded(?string $value): ?string
    {
        return $value !== null && strlen($value) <= 512 && mb_check_encoding($value, 'UTF-8') ? $value : null;
    }
}
