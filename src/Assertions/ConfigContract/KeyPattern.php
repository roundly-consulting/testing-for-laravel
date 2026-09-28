<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\ConfigContract;

/**
 * Segment-wise matching for a scraped config read, which may carry `*` holes.
 *
 * A `*` stands for **exactly one** dotted segment, and only ever appears in a *read* —
 * never in a shipped leaf, which is always a literal path built from the config array. So
 * matching is deliberately asymmetric: the read is the pattern, the leaf is the subject.
 *
 * ## Why a hole is not a licence
 *
 * The holes come from driver-keyed sections — `config("git.providers.{$key}.url")`, the
 * shape of Laravel's own `database.connections.<name>`. The driver segment is a runtime
 * value that no literal read can name, but the *leaf* under it (`url`) is right there in
 * the source, as a literal.
 *
 * That split is the whole design. A pattern proves the **relative leaf**, not the driver:
 * `git.providers.*.url` proves that whatever driver is in play, `url` is read — so every
 * shipped `providers.<driver>.url` is genuinely read. It proves nothing about
 * `providers.<driver>.timeout`, which stays unread and red until some read names it.
 * That is why this is not `allowUnread` wearing a hat: a dead *leaf* still bites.
 *
 * What a pattern deliberately does **not** prove is that any particular driver is ever
 * instantiated — `providers.bitbucket.url` counts as read once any driver's `url` is read.
 * That axis is unprovable from config reads alone (the host picks the driver at runtime),
 * and shipping config for a driver the host never selects is correct, not dead. A driver
 * that no factory can build is a real defect, but it is a *registry* defect, not a config-key
 * one, and this contract does not claim to catch it. See the README's "Known gap".
 *
 * By construction a pattern never *ends* in `*` — {@see TokenScraper} strips a trailing hole
 * down to the literal parent, because a read that stops at the hole is a wholesale section
 * read and proves no leaf at all.
 */
final class KeyPattern
{
    /**
     * True when $read is at or **below** $leaf — the per-leaf proof the reverse direction
     * demands. A read of an *ancestor* never qualifies: reading `git.providers` wholesale
     * says nothing about `git.providers.github.url`.
     */
    public static function readsLeaf(string $read, string $leaf): bool
    {
        $readSegments = explode('.', $read);
        $leafSegments = explode('.', $leaf);

        if (count($readSegments) < count($leafSegments)) {
            return false;
        }

        return self::segmentsMatch($readSegments, $leafSegments, count($leafSegments));
    }

    /**
     * True when $read and $leaf lie on the same path — either may be the deeper one. This is
     * the forward direction's tolerance: naming a parent (`config('pkg.rp')`) or reaching
     * into a leaf (`config('pkg.guards.web')` under a shipped `'guards' => []`) both lie on
     * a shipped path. {@see ConfigContract} narrows the second case: below a *scalar* leaf
     * nothing can live, so it does not count as shipped.
     */
    public static function sharesPath(string $read, string $leaf): bool
    {
        $readSegments = explode('.', $read);
        $leafSegments = explode('.', $leaf);

        return self::segmentsMatch($readSegments, $leafSegments, min(count($readSegments), count($leafSegments)));
    }

    /**
     * Compare the first $length segments, treating a `*` on the **read** side as a match for
     * exactly one segment. A `*` on the leaf side is a literal — a config key really named
     * `*` is matched only by a `*` or by itself, never by every read.
     *
     * @param  list<string>  $readSegments
     * @param  list<string>  $leafSegments
     */
    private static function segmentsMatch(array $readSegments, array $leafSegments, int $length): bool
    {
        for ($i = 0; $i < $length; $i++) {
            if ($readSegments[$i] !== '*' && $readSegments[$i] !== $leafSegments[$i]) {
                return false;
            }
        }

        return true;
    }
}
