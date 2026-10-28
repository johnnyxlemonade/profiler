<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Capture;

/**
 * Lexes a conservative SQL subset; ambiguous syntax never returns raw text.
 */
final readonly class SqlSanitizer
{
    /**
     * Removes literal values, quoted tokens, parameter names and comments before retention.
     */
    public function sanitize(string $sql, int $maxLength): ?string
    {
        if (strlen($sql) > 65536 || preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $sql) !== 0) {
            return null;
        }

        $tokens = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $char = $sql[$i];
            if (ctype_space($char)) {
                $i++;
                continue;
            }
            if (substr($sql, $i, 2) === '--' || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end + 1;
                continue;
            }
            if (substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false || str_contains(substr($sql, $i + 2, $end - $i - 2), '/*')) {
                    return null;
                }
                $i = $end + 2;
                continue;
            }
            if (str_contains("'\"`[", $char)) {
                $quote = $char === '[' ? ']' : $char;
                $i++;
                $closed = false;
                while ($i < $length) {
                    if ($sql[$i] === '\\') {
                        return null; // Escaping depends on dialect and session SQL modes.
                    }
                    if ($sql[$i++] !== $quote) {
                        continue;
                    }
                    if ($i < $length && $sql[$i] === $quote) {
                        $i++;
                        continue;
                    }
                    $closed = true;
                    break;
                }
                if (!$closed) {
                    return null;
                }
                $tokens[] = '?';
                continue;
            }
            if ($char === '$' || $char === '\\' || $char === '@') {
                return null;
            }
            if ($char === ':' && ($sql[$i + 1] ?? '') !== ':') {
                if (preg_match('/\G:[A-Za-z_][A-Za-z0-9_]*/', $sql, $match, 0, $i) !== 1) {
                    return null;
                }
                $tokens[] = '?';
                $i += strlen($match[0]);
                continue;
            }
            if (ctype_digit($char) || ($char === '.' && ctype_digit($sql[$i + 1] ?? ''))) {
                if (preg_match('/\G(?:0[xX][0-9a-fA-F]+|0[bB][01]+|(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)/', $sql, $match, 0, $i) !== 1) {
                    return null;
                }
                $i += strlen($match[0]);
                if (ctype_alnum($sql[$i] ?? '') || ($sql[$i] ?? '') === '_') {
                    return null;
                }
                $tokens[] = '?';
                continue;
            }
            if (ctype_alpha($char) || $char === '_') {
                if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $sql, $match, 0, $i) !== 1) {
                    return null;
                }
                $word = $match[0];
                $i += strlen($word);
                // Dialect-specific prefixed literals must not expose their payload.
                if (in_array(strtoupper($word), ['E', 'Q', 'NQ', 'U'], true) && in_array($sql[$i] ?? '', ["'", '&'], true)) {
                    return null;
                }
                $tokens[] = in_array(strtoupper($word), ['TRUE', 'FALSE', 'NULL'], true) ? '?' : $word;
                continue;
            }
            if (substr($sql, $i, 2) === '::') {
                $tokens[] = '::';
                $i += 2;
                continue;
            }
            if (!str_contains('(),.;?=<>!+-*/%|&^~', $char)) {
                return null;
            }
            $tokens[] = $char;
            $i++;
        }

        $result = implode(' ', $tokens);
        return $result !== '' && strlen($result) <= $maxLength ? $result : null;
    }
}
