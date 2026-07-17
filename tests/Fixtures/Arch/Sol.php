<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch;

/**
 * Exists to shadow `Solo` and `Solo\Client` from *outside* their namespace: `...\Arch\Solo`
 * starts with the string `...\Arch\Sol`.
 *
 * Mid-segment, and deliberately so — Pest's exclusion is a bare `str_starts_with` with no
 * notion of namespace boundaries, so `Sol` really does silence `Solo`. That is the defect at
 * its least intuitive, and the detector has to model Pest rather than model what a sensible
 * matcher would do.
 */
class Sol
{
    public function shine(): string
    {
        return 'sol';
    }
}
