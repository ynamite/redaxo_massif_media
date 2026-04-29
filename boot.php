<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// Extension-point registrations are wired here as features are built:
// - OUTPUT_FILTER  → REX_PIC[...] placeholder substitution
// - OUTPUT_FILTER  → <link rel="preload"> injection into <head>
// - CACHE_DELETED  → purge addon cache on REDAXO cache clear
