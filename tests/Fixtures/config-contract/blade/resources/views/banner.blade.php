{{-- config('shop.not_a_read') is a Blade comment, never a read --}}
<p>Don't miss it: {{ config('shop.banner') }}</p>
@if (config('shop.flag'))
    <x-shop::cta :label="config('shop.cta')" class="it's" />
@endif
{!! config('shop.footer.text') !!}
@{{ config('shop.escaped') }}
<script>const data = @json(config('shop.json'));</script>
<?php echo config('shop.raw'); ?>
@php $x = config('shop.php_block'); @endphp
@verbatim
    {{ config('shop.verbatim') }}
@endverbatim
<a href="mailto:sales@shop.example">sales@shop.example</a>
