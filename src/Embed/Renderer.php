<?php

/**
 * @package     Webmarka Clean
 * @copyright   (C) 2026 Beibit Sakhanov <https://webmarka.kz>
 * @license     GNU General Public License version 2 or later
 */

namespace Webmarka\Plugin\System\Clean\Embed;

\defined('_JEXEC') or die;

/**
 * Turns the safe embed markers the cleaner leaves in article text
 * (<div class="tm-embed" data-embed-src="…" data-embed-w="…" data-embed-h="…"><a href="…">…</a></div>)
 * into real embeds on the site: responsive, lazy-loaded iframes of allowed hosts. Joomla's text filters forbid iframes,
 * so the article keeps the marker and the embed is built only when the page is shown — no template support needed.
 * A marker of a host that is no longer allowed stays as it is: its link still leads to the material.
 */
final class Renderer
{
    /** Quotes of social networks kept by the cleaner and the script of the platform that draws them. */
    public const SCRIPTS = [
        'twitter-tweet'   => 'https://platform.twitter.com/widgets.js',
        'instagram-media' => 'https://www.instagram.com/embed.js',
        'tiktok-embed'    => 'https://www.tiktok.com/embed.js',
        'text-post-media' => 'https://www.threads.net/embed.js',
        'bluesky-embed'   => 'https://embed.bsky.app/static/embed.js',
    ];

    /** @return array{0: string, 1: string[]} the text with markers turned into iframes, and the platform scripts it needs */
    public static function render(string $html, string $hosts): array
    {
        $allowed = array_filter(preg_split('~[\s,]+~', strtolower(trim($hosts))) ?: []);

        if (stripos($html, 'tm-embed') !== false) {
            $html = preg_replace_callback(
                '~<div\b[^>]*\bclass\s*=\s*"[^"]*\btm-embed\b[^"]*"[^>]*>.*?</div>~is',
                static function (array $m) use ($allowed): string {
                    $src = self::attr($m[0], 'data-embed-src');

                    if (!self::allowed($src, $allowed)) {
                        return $m[0];
                    }

                    $w     = (int) self::attr($m[0], 'data-embed-w');
                    $h     = (int) self::attr($m[0], 'data-embed-h');
                    $url   = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
                    $title = htmlspecialchars((string) parse_url($src, PHP_URL_HOST), ENT_QUOTES, 'UTF-8');

                    if (preg_match('~^https://t\.me/~i', $src)) {
                        return '<div class="wmarka-embed wmarka-embed--telegram"><iframe src="' . $url . '" title="' . $title . '" width="100%" height="' . ($h ?: 480)
                            . '" style="width:100%;max-width:560px;border:0" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
                    }

                    $ratio = $w > 0 && $h > 0 ? $w . ' / ' . $h : '16 / 9';

                    return '<div class="wmarka-embed" style="width:100%;max-width:' . ($w > 0 ? $w . 'px' : '100%') . ';aspect-ratio:' . $ratio . '">'
                        . '<iframe src="' . $url . '" title="' . $title . '" style="width:100%;height:100%;border:0" loading="lazy"'
                        . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen'
                        . ' referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
                },
                $html
            ) ?? $html;
        }

        $scripts = [];

        foreach (self::SCRIPTS as $class => $url) {
            if (preg_match('~class\s*=\s*"[^"]*\b' . preg_quote($class, '~') . '\b~', $html)) {
                $scripts[] = $url;
            }
        }

        return [$html, $scripts];
    }

    public static function allowed(string $src, array $allowed): bool
    {
        if (!preg_match('~^https://~i', $src)) {
            return false;
        }

        $host = strtolower((string) parse_url($src, PHP_URL_HOST));

        foreach ($allowed as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function attr(string $tag, string $name): string
    {
        return preg_match('~\b' . preg_quote($name, '~') . '\s*=\s*"([^"]*)"~i', $tag, $m)
            ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }
}
