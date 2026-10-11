<?php

use Symfony\Component\Finder\Finder;

function modInternalClasses(): array
{
    $classes = [];

    foreach (Finder::create()->files()->name('*.php')->in(dirname(__DIR__, 2).'/vendor/tey/mod/src') as $file) {
        $source = $file->getContents();

        // A class-level @internal: the docblock right before the class, interface, trait or enum declaration.
        if (! preg_match('/\/\*\*(?:(?!\*\/).)*@internal(?:(?!\*\/).)*\*\/\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/s', $source, $match)) {
            continue;
        }

        preg_match('/^namespace\s+([^;]+);/m', $source, $namespace);
        $classes[] = $namespace[1].'\\'.$match[1];
    }

    sort($classes);

    return $classes;
}

it('finds the classes mod marks internal', function () {
    expect(modInternalClasses())
        ->toContain('Tey\Mod\Layout\LayoutRegistry')
        ->toContain('Tey\Mod\Reverse\ReverseMapper')
        ->not->toContain('Tey\Mod\Layout\CompiledLayout');
});

it('uses no internal Mod classes in production', function () {
    $offenders = [];

    foreach (Finder::create()->files()->name('*.php')->in(dirname(__DIR__, 2).'/src') as $file) {
        foreach (modInternalClasses() as $class) {
            if (preg_match('/^use '.preg_quote($class, '/').';$/m', $file->getContents())) {
                $offenders[] = $file->getRelativePathname().': '.$class;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps retired internal Mod members out of the host adapter', function () {
    $offenders = [];
    $methods = ['kind', 'hasKind', 'kinds', 'rule', 'rules', 'compile', 'class', 'cache',
        'writeCache', 'presetFingerprint', 'definitionsFingerprint', 'existingArtifacts',
        'inOption', 'relationsTo', 'placeSibling', 'plannedRelationsTo', 'layoutName'];
    $properties = ['kind', 'shape', 'namePolicy', 'fromKind', 'toKind'];

    foreach (Finder::create()->files()->name('*.php')->in(dirname(__DIR__, 2).'/src') as $file) {
        // Tokenise so comments and emitted PHP strings do not count as host calls.
        $tokens = token_get_all($file->getContents());
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
            $member = $tokens[$index + 1];
            if (! is_array($member) || $member[0] !== T_STRING) {
                continue;
            }
            $next = $index + 2;
            while (is_array($tokens[$next] ?? null) && $tokens[$next][0] === T_WHITESPACE) {
                $next++;
            }
            $call = ($tokens[$next] ?? null) === '(';
            if (in_array($member[1], $call ? $methods : $properties, true)) {
                $offenders[] = $file->getRelativePathname().':'.$member[2].' '.$member[1];
            }
        }
    }

    expect($offenders)->toBe([]);
});
