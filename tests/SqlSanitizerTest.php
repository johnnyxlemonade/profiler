<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests;

use Lemonade\Profiler\SqlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlSanitizerTest extends TestCase
{
    #[DataProvider('sqlCases')]
    public function testLiteralRemoval(string $sql, ?string $expected): void
    {
        self::assertSame($expected, (new SqlSanitizer())->sanitize($sql, 4096));
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function sqlCases(): iterable
    {
        yield 'bindings' => ['SELECT * FROM users WHERE token = :secret', 'SELECT * FROM users WHERE token = ?'];
        yield 'literal and comment' => ["SELECT 'secret', 12.5, 0xAB, 1e-5 /* secret */ FROM users -- secret", 'SELECT ? , ? , ? , ? FROM users'];
        yield 'doubled quotes' => ["SELECT 'it''s secret'", 'SELECT ?'];
        yield 'quoted tokens' => ['SELECT "secret", `private`, [private]]name]', 'SELECT ? , ? , ?'];
        yield 'booleans' => ['SELECT TRUE, FALSE, NULL', 'SELECT ? , ? , ?'];
        yield 'hash comment' => ["SELECT ? # secret\n FROM users", 'SELECT ? FROM users'];
        yield 'unclosed string' => ["SELECT 'secret", null];
        yield 'ambiguous escape' => ["SELECT 'a\\'secret'", null];
        yield 'dollar quoting' => ['SELECT $tag$secret$tag$', null];
        yield 'oracle literal' => ["SELECT q'[secret]'", null];
        yield 'oracle national literal' => ["SELECT nq'[a' private_value ']'", null];
        yield 'unclosed comment' => ['SELECT 1 /* secret', null];
        yield 'nested comment' => ['SELECT 1 /* outer /* secret */ trailing */', null];
        yield 'unicode escape' => ["SELECT U&'secret'", null];
        yield 'non-ascii' => ["SELECT 'tajné'", null];
        yield 'invalid numeric suffix' => ['SELECT 123secret', null];
        yield 'positional postgres' => ['SELECT $1', null];
    }

    public function testLimitsNeverReturnRawOrTruncatedSql(): void
    {
        $sanitizer = new SqlSanitizer();
        self::assertNull($sanitizer->sanitize('SELECT * FROM users', 4));
        self::assertNull($sanitizer->sanitize(str_repeat('a', 65537), 4096));
    }
}
