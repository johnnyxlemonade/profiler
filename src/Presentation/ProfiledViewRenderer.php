<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Presentation;

use Lemonade\Framework\Http\HttpStatus;
use Lemonade\Framework\View\ViewRendererInterface;

use Lemonade\Profiler\Capture\ProfileContext;
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
     * Records an inclusive rendering interval while preserving the inner response and exceptions.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], int $status = HttpStatus::OK->value): ResponseInterface
    {
        $index = $this->context->beginRendering($template);
        $completed = false;
        try {
            $response = $this->inner->render($template, $data, $status);
            $completed = true;
            return $response;
        } finally {
            $this->context->endRendering(index: $index, completed: $completed);
        }
    }

    /**
     * Records an inclusive rendering interval without retaining template data or output.
     *
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
            $this->context->endRendering(index: $index, completed: $completed);
        }
    }
}
