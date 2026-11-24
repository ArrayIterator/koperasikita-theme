<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

final class Bookmarks
{
    public static function register(): void
    {
        // Phase 3: IndexedDB-only bookmarks (no database, user meta or REST
        // sync). Entry: postId, title, url, excerpt, thumbnail, note, createdAt.
    }
}
