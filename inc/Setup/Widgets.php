<?php

declare(strict_types=1);

namespace KoperasiKita\Setup;

final class Widgets
{
    public static function register(): void
    {
        // Widget classes land in Phase 3. Nothing is registered here yet on purpose:
        // registering a half-built widget would surface it in the admin before it works.
    }
}
