<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Facades;

use RuntimeException;

/**
 * Thrown by {@see RedactionProbe} when a facade call reaches it; its trace is what
 * {@see FacadeRedaction} reads.
 *
 * @internal
 */
final class RedactionProbeReached extends RuntimeException {}
