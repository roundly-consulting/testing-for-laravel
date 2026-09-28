<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\ConfigContract;

/**
 * The PHP inside a Blade view, as a source the {@see TokenScraper} can tokenize.
 *
 * Tokenized as-is, a `.blade.php` file is a single `T_INLINE_HTML` token — so
 * `{{ config('pkg.banner') }}` was never scraped, and passing `resources/views` as a source
 * directory (the README's own remedy for a key read from a view) changed nothing. Compiling
 * the view with Laravel's `BladeCompiler` would need a booted view factory for every
 * `<x-component>` tag, so this extracts the PHP-bearing constructs directly instead:
 *
 *  - `{{ … }}` and `{!! … !!}` echoes;
 *  - the argument list of every `@directive( … )` (balanced parentheses);
 *  - `@php … @endphp` and `<?php … ?>` blocks;
 *  - `:attribute="…"` bindings on `<x-…>` component tags.
 *
 * What Blade itself never runs is dropped first: `{{-- comments --}}`, `@verbatim` blocks,
 * and the `@{{ … }}` / `@@directive` escapes. Markup is never tokenized, so an apostrophe in
 * the text (`Don't miss it`) cannot open a phantom string that swallows a real read.
 *
 * @internal
 */
final class BladeSource
{
    public static function toPhp(string $blade): string
    {
        $blade = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $blade);
        $blade = (string) preg_replace('/(?<!@)@verbatim\b.*?@endverbatim\b/s', '', $blade);

        $fragments = [];

        // Raw PHP first, then removed, so its content is not scanned twice.
        $blade = (string) preg_replace_callback('/(?<!@)@php\b(?!\s*\()(.*?)@endphp\b/s', static function (array $match) use (&$fragments): string {
            $fragments[] = $match[1];

            return '';
        }, $blade);

        $blade = (string) preg_replace_callback('/<\?php(.*?)(?:\?>|$)/s', static function (array $match) use (&$fragments): string {
            $fragments[] = $match[1];

            return '';
        }, $blade);

        preg_match_all('/(?<!@)\{!!(.*?)!!\}/s', $blade, $raw);
        preg_match_all('/(?<![@{])\{\{(?!--)(.*?)\}\}/s', $blade, $escaped);
        preg_match_all('/(?<![@\w])@\w+\s*(\((?:[^()]++|(?1))*\))/', $blade, $directives);
        preg_match_all('/<x-[^>]*>/s', $blade, $components);

        $bindings = [];

        foreach ($components[0] as $tag) {
            preg_match_all('/\s:[\w.:-]+="([^"]*)"/', $tag, $attributes);
            array_push($bindings, ...$attributes[1]);
        }

        array_push($fragments, ...$raw[1], ...$escaped[1], ...$directives[1], ...$bindings);

        return "<?php\n".implode(";\n", array_map(trim(...), $fragments)).";\n";
    }
}
