<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

/**
 * A stand-in host-configured model class, used only to prove {@see FakePackageTestCase}
 * swaps `config('fake.widget_model')` before the providers boot.
 */
final class FakeWidget
{
    //
}
