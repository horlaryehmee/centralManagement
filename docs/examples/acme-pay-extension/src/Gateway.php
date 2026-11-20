<?php

namespace Extensions\AcmePay;

/** Client-specific payment gateway for Acme only. */
class Gateway
{
    public const VERSION = '1.0.0';

    public function name(): string
    {
        return 'AcmePay';
    }
}
