<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

test('every Laravel validation message exists in Arabic, so no screen ever shows a raw key', function () {
    $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
    $arabic = require lang_path('ar/validation.php');

    $missing = [];
    $walk = function (array $reference, array $translated, string $path) use (&$walk, &$missing) {
        foreach ($reference as $key => $value) {
            if (in_array($key, ['custom', 'attributes'], true) && $path === '') {
                continue;
            }
            if (! array_key_exists($key, $translated)) {
                $missing[] = ltrim("$path.$key", '.');
            } elseif (is_array($value)) {
                $walk($value, (array) $translated[$key], ltrim("$path.$key", '.'));
            } elseif (! is_string($translated[$key]) || trim($translated[$key]) === '') {
                $missing[] = ltrim("$path.$key", '.');
            }
        }
    };
    $walk($english, $arabic, '');

    expect($missing)->toBe([]);
});

test('a refused file type reads as a sentence, not as a key', function () {
    $file = UploadedFile::fake()->create('notes.txt', 1, 'text/plain');
    $message = Validator::make(['file' => $file], ['file' => 'mimetypes:image/png'])->errors()->first('file');

    expect($message)->not->toStartWith('validation.')->and($message)->toMatch('/\p{Arabic}/u');
});
