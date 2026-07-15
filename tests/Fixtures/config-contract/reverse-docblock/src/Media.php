<?php

declare(strict_types=1);

/**
 * The size cap is read from config('shop.documented') before every upload.
 *
 * (It is not — this mention lives only in the docblock. A regex over raw text is
 * satisfied by this comment; the tokenizer is not, so the reverse contract still bites.)
 */
return function (): void {
    config('shop.used');
};
