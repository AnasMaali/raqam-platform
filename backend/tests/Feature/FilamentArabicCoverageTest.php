<?php

use Illuminate\Support\Facades\Lang;

/**
 * The panel is Arabic only. A string the installed Filament release has no Arabic for would show its raw key
 * (or sit in the page as hidden text for screen readers). This lists every English string of every Filament
 * package and fails when one has no Arabic, whether from the package or from our lang/vendor overrides.
 */
$namespaces = ['filament' => 'filament-panels', 'forms' => 'filament-forms', 'tables' => 'filament-tables', 'schemas' => 'filament-schemas',
    'actions' => 'filament-actions', 'notifications' => 'filament-notifications', 'infolists' => 'filament-infolists',
    'query-builder' => 'filament-query-builder', 'support' => 'filament-support'];

function flatKeys(array $items, string $prefix = ''): array
{
    $keys = [];
    foreach ($items as $key => $value) {
        is_array($value) ? $keys = [...$keys, ...flatKeys($value, "{$prefix}{$key}.")] : $keys[] = "{$prefix}{$key}";
    }

    return $keys;
}

test('every Filament string has an Arabic text', function () use ($namespaces) {
    $missing = [];
    foreach ($namespaces as $package => $namespace) {
        foreach (glob(base_path("vendor/filament/{$package}/resources/lang/en/*.php")) as $file) {
            $group = basename($file, '.php');
            foreach (flatKeys(require $file) as $key) {
                if (! Lang::hasForLocale("{$namespace}::{$group}.{$key}", 'ar')) {
                    $missing[] = "{$namespace}::{$group}.{$key}";
                }
            }
        }
    }

    expect($missing)->toBe([]);
});
