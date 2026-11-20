<?php

namespace App\Support;

/**
 * Small semantic-version constraint checker, identical in the central app and
 * the update agent so both sides judge compatibility the same way.
 *
 * Supports (Composer semantics): "*", "1.2.3", "=1.2", "!=1.2",
 * ">=1.2 <2.0" (space or comma = AND), "^1.2" (>=1.2 <2.0; ^0.3 = >=0.3 <0.4),
 * "~1.2" (>=1.2 <2.0), "~1.2.3" (>=1.2.3 <1.3.0), "1.2.*", and "||" for OR.
 */
class VersionConstraint
{
    public static function normalize(?string $version): string
    {
        $v = ltrim(trim((string) $version), 'vV');
        $v = preg_replace('/[-+].*$/', '', $v); // ignore pre-release/build suffixes
        $parts = array_map('intval', explode('.', $v === '' ? '0' : $v));

        return implode('.', array_pad(array_slice($parts, 0, 3), 3, 0));
    }

    public static function compare(?string $a, ?string $b): int
    {
        return version_compare(self::normalize($a), self::normalize($b));
    }

    public static function isValid(?string $constraint): bool
    {
        try {
            self::satisfies('1.0.0', $constraint);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public static function satisfies(?string $version, ?string $constraint): bool
    {
        $constraint = trim((string) $constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        if ($version === null || trim($version) === '') {
            return false;
        }

        foreach (explode('||', $constraint) as $alternative) {
            $terms = preg_split('/[\s,]+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY);
            if (! $terms) {
                throw new \InvalidArgumentException("Invalid version constraint: {$constraint}");
            }
            $ok = true;
            foreach ($terms as $term) {
                if (! self::term($version, $term)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    protected static function term(string $version, string $term): bool
    {
        if (! preg_match('/^(\^|~|>=|<=|!=|>|<|=)?\s*v?(\d+(?:\.(?:\d+|\*))*)$/', $term, $m)) {
            throw new \InvalidArgumentException("Invalid version constraint: {$term}");
        }
        [$op, $target] = [$m[1], $m[2]];
        $v = self::normalize($version);

        if (str_contains($target, '*')) {
            $prefix = rtrim(substr($target, 0, strpos($target, '*')), '.');
            $op = $op ?: '=';
            $lower = self::normalize($prefix);
            $upper = self::bump($prefix, substr_count($prefix, '.'));

            return $op === '!='
                ? ! (version_compare($v, $lower, '>=') && version_compare($v, $upper, '<'))
                : version_compare($v, $lower, '>=') && version_compare($v, $upper, '<');
        }

        $t = self::normalize($target);

        return match ($op) {
            '^' => version_compare($v, $t, '>=') && version_compare($v, self::caretUpper($target), '<'),
            '~' => version_compare($v, $t, '>=') && version_compare($v, self::bump($target, max(0, substr_count($target, '.') - 1)), '<'),
            '>=' => version_compare($v, $t, '>='),
            '<=' => version_compare($v, $t, '<='),
            '>' => version_compare($v, $t, '>'),
            '<' => version_compare($v, $t, '<'),
            '!=' => version_compare($v, $t, '!='),
            default => version_compare($v, $t, '=='),
        };
    }

    /** Upper bound of a caret range: first non-zero part goes up by one. */
    protected static function caretUpper(string $target): string
    {
        $parts = array_map('intval', explode('.', $target));
        $parts = array_pad($parts, 3, 0);
        foreach ($parts as $i => $p) {
            if ($p !== 0 || $i === count(explode('.', $target)) - 1) {
                return self::bump(implode('.', array_slice($parts, 0, $i + 1)), $i);
            }
        }

        return self::bump($target, 0);
    }

    /** "1.2" bumped at index 1 → "1.3.0"; at index 0 → "2.0.0". */
    protected static function bump(string $version, int $index): string
    {
        $parts = array_pad(array_map('intval', explode('.', $version)), 3, 0);
        $parts[$index]++;
        for ($i = $index + 1; $i < 3; $i++) {
            $parts[$i] = 0;
        }

        return implode('.', array_slice($parts, 0, 3));
    }
}
