<?php

declare(strict_types=1);

namespace Ynamite\Media\Var;

use rex_var;

/**
 * Native REDAXO REX_VAR for `<video>` markup in slice content.
 *
 * Syntax (named attributes; supports embedded REX_VARs):
 *
 *   REX_VIDEO[src="hero.mp4" poster="hero.jpg" autoplay="true" muted="true" loop="true"]
 *
 * Recognized attributes: src (required), poster, width, height, alt, class,
 * preload, loading, autoplay, muted, loop, controls, playsinline.
 *
 * Substitution happens during article cache generation (REDAXO core's
 * `replaceObjectVars` calls `rex_var::parse` per slice). The PHP expression
 * returned from `getOutput()` is baked into the cached article and evaluated
 * fresh on every render — Video::render()'s defaults take effect for any
 * attribute the editor omits.
 */
final class RexVideo extends rex_var
{
    protected function getOutput(): string|false
    {
        $src = $this->getParsedArg('src');
        if ($src === null) {
            return false;
        }

        $args = ['src: ' . $src];

        // String / int passthroughs. getParsedArg returns either an already-
        // quoted PHP string literal, a bare numeric, or null. Missing args
        // fall back to Video::render()'s own defaults.
        foreach (['poster', 'width', 'height', 'alt', 'class', 'preload', 'loading'] as $key) {
            $val = $this->getParsedArg($key);
            if ($val !== null) {
                $args[] = $key . ': ' . $val;
            }
        }

        // Bool attrs: emit only when present, so Video::render()'s asymmetric
        // defaults (autoplay/muted/loop default false; controls/playsinline
        // default true) survive when the editor omits the attribute.
        foreach (['autoplay', 'muted', 'loop', 'controls', 'playsinline'] as $key) {
            $raw = $this->getArg($key);
            if ($raw !== null) {
                $args[] = $key . ': ' . (filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false');
            }
        }

        return '\\Ynamite\\Media\\Video::render(' . implode(', ', $args) . ')';
    }
}
