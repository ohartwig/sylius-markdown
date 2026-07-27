<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Renderer;

/**
 * Tidies template output into well-formed Markdown.
 *
 * Twig control structures leave blank lines wherever a block was skipped, so a
 * product without a description renders with a four-line gap. Harmless to a
 * parser, but the documents are read by people too, and the whitespace inflates
 * the token count advertised in the response header.
 */
final class MarkdownNormalizer
{
    public function normalize(string $markdown): string
    {
        // Windows line endings from admin-authored rich text.
        $markdown = str_replace("\r\n", "\n", $markdown);

        // A lone trailing space is noise; two or more are a hard line break and
        // stay. The obvious `/ +$/m` eats the break along with the noise.
        $markdown = (string) preg_replace('/(?<! ) $/m', '', $markdown);

        // Any run of blank lines collapses to one — the paragraph separator.
        $markdown = (string) preg_replace('/\n{3,}/', "\n\n", $markdown);

        return trim($markdown)."\n";
    }
}
