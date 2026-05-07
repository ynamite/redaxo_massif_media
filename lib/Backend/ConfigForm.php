<?php

declare(strict_types=1);

namespace Ynamite\Media\Backend;

use rex_config;
use rex_config_form;
use rex_dir;
use rex_path;
use Ynamite\Media\Config;

/**
 * Drop-in replacement for `rex_config_form::factory()` on this addon's
 * settings pages. Auto-clears the addon's variant cache and bumps the
 * cache-generation token whenever a save changes any of the
 * content-affecting settings (`{@see \Ynamite\Media\Config::CACHE_INVALIDATING_KEYS}`).
 *
 * Why a subclass and not a `REX_FORM_SAVED` extension: REDAXO core's
 * `rex_form` fires that extension point in its DB-backed `save()`, but
 * `rex_config_form::save()` writes through `rex_config::set` directly and
 * does not fire any extension point — see
 * `redaxo/src/core/lib/form/config_form.php::save()`. The cleanest way to
 * hook into the save flow is to subclass and intercept; there's no useful
 * runtime detection path otherwise.
 *
 * The HMAC sign key (`KEY_SIGN_KEY`) is never auto-regenerated — it stays
 * user-triggered via its dedicated button on the security tab. A regen
 * invalidates every browser-cached URL and any third-party hotlink for
 * content that's still semantically valid; that's heavier than a
 * content-only cache wipe and shouldn't fire as a side effect.
 */
final class ConfigForm extends rex_config_form
{
    protected function save()
    {
        $before = self::cacheRelevantSnapshot();
        $result = parent::save();
        if ($result === true) {
            $after = self::cacheRelevantSnapshot();
            if ($before !== $after) {
                self::onContentAffectingChange();
            }
        }
        return $result;
    }

    /**
     * Snapshot the current values of every cache-invalidating key. Compared
     * before- and after-`parent::save()` to detect whether the user actually
     * touched a content-affecting setting; settings that don't appear in
     * `CACHE_INVALIDATING_KEYS` (HTML markup, runtime TTLs, CDN routing,
     * external-fetch behaviour) leave existing cached variants valid and
     * therefore don't trigger a clear.
     *
     * @return array<string, mixed>
     */
    private static function cacheRelevantSnapshot(): array
    {
        $snapshot = [];
        foreach (Config::CACHE_INVALIDATING_KEYS as $key) {
            $snapshot[$key] = rex_config::get(Config::ADDON, $key);
        }
        return $snapshot;
    }

    private static function onContentAffectingChange(): void
    {
        $cacheDir = rex_path::addonAssets(Config::ADDON, 'cache/');
        if (is_dir($cacheDir)) {
            rex_dir::delete($cacheDir, false);
        }
        Config::bumpCacheGeneration();
    }
}
