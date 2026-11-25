<?php

declare(strict_types=1);

namespace Imgul\AgentGuard\Support;

/**
 * Loop fingerprinting.
 *
 * Two fingerprints are derived from a tool call:
 *
 *  - exact:        sha256(tool . ':' . json_encode(sorted args))
 *  - normalized:   the same, after cosmetic variation (whitespace, numeric
 *                  and pagination params) is stripped, so argument-perturbation
 *                  evasion cannot bypass detection.
 */
final class Fingerprint
{
    /**
     * Argument keys that carry pagination semantics and no loop signal.
     */
    private const PAGINATION_KEYS = [
        'page',
        'offset',
        'limit',
        'cursor',
        'per_page',
        'perpage',
        'skip',
        'take',
        'start',
        'end',
        'timestamp',
        'timestamps',
        'created_at',
        'updated_at',
        'ts',
    ];

    /**
     * Exact fingerprint: sha256 of the tool name and canonically-encoded args.
     *
     * @param  array<string, mixed>  $args
     */
    public static function exact(string $tool, array $args): string
    {
        return hash('sha256', $tool.':'.self::canonical($args));
    }

    /**
     * Normalized fingerprint: cosmetic variation stripped before hashing.
     *
     * @param  array<string, mixed>  $args
     */
    public static function normalized(string $tool, array $args): string
    {
        return hash('sha256', $tool.':'.self::canonical(self::normalize($args)));
    }

    /**
     * Normalize cosmetic variation from arguments (whitespace collapsed,
     * numeric/pagination params bucketed to a wildcard).
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function normalizedArgs(array $args): array
    {
        return self::normalize($args);
    }

    /**
     * Canonical JSON encoding with stable key ordering.
     *
     * @param  array<string, mixed>  $args
     */
    private static function canonical(array $args): string
    {
        ksort($args);

        return (string) json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Strip cosmetic variation from arguments: whitespace is collapsed,
     * numeric values and pagination params are bucketed to a wildcard.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function normalize(array $args): array
    {
        $normalized = [];

        foreach ($args as $key => $value) {
            if (is_string($value)) {
                $value = self::collapseWhitespace($value);
            }

            if (self::isPaginationKey($key) || is_int($value) || is_float($value)) {
                $normalized[$key] = '*';

                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $nested */
                $nested = $value;
                $normalized[$key] = self::normalize($nested);
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private static function isPaginationKey(string|int $key): bool
    {
        return in_array(strtolower((string) $key), self::PAGINATION_KEYS, true);
    }

    private static function collapseWhitespace(string $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($value));
    }
}
