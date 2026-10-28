<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests\Support;

use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\View\ViewRendererInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final readonly class TestController
{
    public function __construct(private ConnectionInterface $database, private ViewRendererInterface $views) {}

    public function page(): ResponseInterface
    {
        $this->database->select('SELECT :value AS value', ['value' => 'secret-binding']);
        $this->database->select("SELECT 'secret-literal' AS value");
        return $this->views->render('page', ['password' => 'secret-template']);
    }

    public function error(): ResponseInterface
    {
        $this->database->select('SELECT 1');
        throw new RuntimeException('secret-exception');
    }
}
