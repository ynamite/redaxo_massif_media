# Parking lot

Small, distinct bugs and cleanup items deferred from larger specs. Each is small enough to fix without a separate brainstorm + plan, but ride-along discipline keeps them out of the bigger work.

## Placeholder pipeline cleanup (logged 2026-05-02 during cropping/filter brainstorm)

1. **LQIP always generated regardless of `lqip_enabled` config.** `Pipeline/Placeholder::generate` (or its caller in `PictureRenderer`) likely doesn't check `Config::lqipEnabled()`. Files: `lib/Pipeline/Placeholder.php`, `lib/View/PictureRenderer.php`. Fix: gate inline base64 emission on `Config::lqipEnabled()`.

2. **LQIP runs even when blurhash is the user's chosen placeholder strategy.** Same root cause as #1 — the two strategies aren't gated independently. Fix: same place; honor each flag.

3. **Stale "JavaScript decode" copy on the Placeholder settings tab.** `pages/settings.placeholder.php` still describes blurhash as JS-only. Update to reflect the documented PHP-side decode option from the README.

4. **Quality / size settings on the Placeholder tab read as LQIP-only.** Same file. Either add `(LQIP only)` qualifiers to the relevant fields, or split the form into LQIP + Blurhash sections with explicit labels.

These are localized, mostly UI/text changes plus a one-line guard in `Placeholder::generate`. Pick up after the filter feature ships.
