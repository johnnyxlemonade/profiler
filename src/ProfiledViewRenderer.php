<?php

declare(strict_types=1);

namespace Lemonade\Profiler;

use Lemonade\Framework\View\ViewRendererInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Measures public renderer calls while preserving the inner renderer and its layout behavior.
 */
final readonly class ProfiledViewRenderer implements ViewRendererInterface
{
    /**
     * Wraps the already-resolved renderer in the same request scope.
     */
    public function __construct(
        private ViewRendererInterface $inner,
        private ProfileContext $context,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], int $status = 200): ResponseInterface
    {
        $index = $this->context->beginRendering($template);
        $completed = false;
        try {
            $response = $this->inner->render($template, $data, $status);
            $completed = true;
            return $response;
        } finally {
            $this->context->endRendering($index, $completed);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function content(string $template, array $data = []): string
    {
        $index = $this->context->beginRendering($template);
        $completed = false;
        try {
            $content = $this->inner->content($template, $data);
            $completed = true;
            return $content;
        } finally {
            $this->context->endRendering($index, $completed);
        }
    }
}
