<?php

namespace Modules\GiftWrap;

/** Example module: optional gift wrapping at checkout. */
class GiftWrap
{
    public const VERSION = '1.0.0';

    public function price(): int
    {
        return 1500;
    }
}
