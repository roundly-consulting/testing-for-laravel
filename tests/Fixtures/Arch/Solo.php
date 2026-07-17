<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch;

/**
 * A class whose name is **also** a namespace: `Solo.php` sits beside a `Solo/` directory.
 * This is a common package shape (a manager class plus its own sub-namespace), and Pest
 * resolves it through a dedicated branch — a namespace may be satisfied by a same-named
 * *file* as well as a directory.
 *
 * It matters here because that branch decides the population. Miss it and
 * `expect('...\Solo')` would resolve to the directory alone: the class doing the shadowing
 * would drop out of the set, every shadow it casts would vanish, and the check would report
 * a clean bill of health over the exact shape most likely to shadow anything.
 */
class Solo
{
    public function run(): string
    {
        return 'solo';
    }
}
