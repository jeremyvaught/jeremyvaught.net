<?php

test('site pages render', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<title>Jeremy Vaught</title>', false)
        ->assertSee('GTM-TLZCGZHV', false)
        ->assertSee('47F82BCE3975A4C75D7D4B0D8267D603', false);
})->with(['/', '/index.htm']);

test('site page is byte-identical to the original', function () {
    $html = file_get_contents(resource_path('views/site/index.blade.php'));
    $html = substr($html, strlen('@verbatim'), -strlen('@endverbatim'));

    expect($this->get('/')->getContent())->toBe($html);
});
