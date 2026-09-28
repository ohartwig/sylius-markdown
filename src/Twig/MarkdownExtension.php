<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Twig;

use League\HTMLToMarkdown\HtmlConverter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Converts the rich-text fields — and only those.
 *
 * Product descriptions are authored as HTML in the admin, so this one hop is
 * unavoidable. It is confined to individual fields rather than applied to a
 * rendered page, which is the difference between converting content and
 * converting a layout.
 */
final class MarkdownExtension extends AbstractExtension
{
    private ?HtmlConverter $converter = null;

    /** @return list<TwigFilter> */
    public function getFilters(): array
    {
        return [
            new TwigFilter('markdown_from_html', $this->fromHtml(...)),
        ];
    }

    public function fromHtml(?string $html): string
    {
        if (null === $html || '' === trim($html)) {
            return '';
        }

        // Plain text authored without markup: pass through, but do not let a
        // stray < be swallowed by the converter.
        if (!str_contains($html, '<')) {
            return trim($html);
        }

        return trim($this->converter()->convert($html));
    }

    private function converter(): HtmlConverter
    {
        return $this->converter ??= new HtmlConverter([
            // Keep author intent: a manual line break in a description is
            // usually meaningful.
            'hard_break' => true,
            // Anything without a Markdown equivalent is dropped rather than
            // passed through as raw HTML — an agent should not have to parse
            // two languages.
            'strip_tags' => true,
            'remove_nodes' => 'script style iframe',
            'header_style' => 'atx',
        ]);
    }
}
