<?php

declare(strict_types=1);

namespace AtlasCache\Support;

interface ClockInterface
{
    public function now(): int;
}
