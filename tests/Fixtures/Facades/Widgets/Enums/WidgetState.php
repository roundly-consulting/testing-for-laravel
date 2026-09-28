<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Enums;

enum WidgetState: string
{
    case Draft = 'draft';
    case Published = 'published';
}
