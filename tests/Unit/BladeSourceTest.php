<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Assertions\ConfigContract\BladeSource;

it('extracts echoes, raw echoes, directive arguments and component bindings', function (): void {
    $php = BladeSource::toPhp(<<<'BLADE'
        <h1>{{ $title }}</h1>
        {!! $html !!}
        @foreach ($items as $item) @endforeach
        <x-alert :type="$level" :data-id="$id" class="x" />
        <div :class="notABinding"></div>
        BLADE);

    expect($php)->toStartWith('<?php')
        ->toContain('$title;')
        ->toContain('$html;')
        ->toContain('($items as $item);')
        ->toContain('$level;')
        ->toContain('$id;')
        ->not->toContain('notABinding');
});

it('keeps nested parentheses in a directive argument together', function (): void {
    expect(BladeSource::toPhp("@if (in_array(config('a.b'), [1, 2]))"))->toContain("(in_array(config('a.b'), [1, 2]));");
});

it('drops what Blade never runs: comments, escapes and verbatim blocks', function (): void {
    $php = BladeSource::toPhp(<<<'BLADE'
        {{-- {{ $commented }} --}}
        @{{ $escaped }}
        @@if($escapedDirective)
        @verbatim {{ $verbatim }} @endverbatim
        mail me at someone@example.com(maybe)
        BLADE);

    expect($php)->not->toContain('$commented')
        ->not->toContain('$escaped')
        ->not->toContain('$escapedDirective')
        ->not->toContain('$verbatim')
        ->not->toContain('maybe');
});

it('takes @php blocks and <?php blocks as code, including an unterminated trailing block', function (): void {
    $php = BladeSource::toPhp("@php \$a = 1; @endphp\n<?php \$b = 2; ?>\n<?php \$c = 3;");

    expect($php)->toContain('$a = 1;')
        ->toContain('$b = 2;')
        ->toContain('$c = 3;');
});

it('never tokenizes markup, so an apostrophe cannot swallow a read', function (): void {
    $tokens = token_get_all(BladeSource::toPhp("<p>Don't</p> {{ config('shop.banner') }} <p>it's</p>"));
    $strings = array_values(array_filter($tokens, fn ($t): bool => is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING));

    expect(array_column($strings, 1))->toBe(["'shop.banner'"]);
});
