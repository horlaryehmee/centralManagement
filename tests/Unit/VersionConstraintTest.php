<?php

namespace Tests\Unit;

use App\Support\VersionConstraint as V;
use PHPUnit\Framework\TestCase;

class VersionConstraintTest extends TestCase
{
    public function test_constraints(): void
    {
        $cases = [
            ['1.4.2', '*', true], ['1.4.2', '', true], ['1.4.2', '1.4.2', true], ['1.4.2', '1.4', false],
            ['1.4.2', '>=1.2 <2.0', true], ['2.0.0', '>=1.2 <2.0', false], ['1.4.2', '>=1.2, <1.4', false],
            ['1.9.9', '^1.2', true], ['2.0.0', '^1.2', false], ['1.1.0', '^1.2', false],
            ['0.3.5', '^0.3', true], ['0.4.0', '^0.3', false],
            ['1.2.9', '~1.2', true], ['1.3.0', '~1.2', true], ['2.0.0', '~1.2', false], ['1.2.5', '~1.2.3', true], ['1.3.0', '~1.2.3', false],
            ['1.2.7', '1.2.*', true], ['1.3.0', '1.2.*', false], ['1.3.0', '!=1.2.*', true],
            ['3.0.0', '^1.0 || ^3.0', true], ['2.0.0', '^1.0 || ^3.0', false],
            ['v1.10.0', '>1.9', true], ['1.0.0-beta', '>=1.0.0', true], [null, '>=1.0', false],
        ];
        foreach ($cases as [$version, $constraint, $expected]) {
            $this->assertSame($expected, V::satisfies($version, $constraint), "{$version} vs {$constraint}");
        }
    }

    public function test_invalid_constraints_are_detected(): void
    {
        $this->assertFalse(V::isValid('>= banana'));
        $this->assertFalse(V::isValid('1.0; rm -rf'));
        $this->assertTrue(V::isValid('>=1.2 <2.0 || ^3'));
    }
}
