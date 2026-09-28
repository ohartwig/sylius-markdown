<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Tests\Renderer;

use Koh\SyliusMarkdown\Renderer\MarkdownNormalizer;
use Koh\SyliusMarkdown\Twig\MarkdownExtension;
use PHPUnit\Framework\TestCase;

final class MarkdownNormalizerTest extends TestCase
{
    /** Twig leaves a gap wherever a block was skipped — a product without a description. */
    public function testBlankLineRunsCollapseToOne(): void
    {
        $normalizer = new MarkdownNormalizer();

        self::assertSame("# Title\n\nBody\n", $normalizer->normalize("# Title\n\n\n\n\nBody"));
    }

    public function testWindowsLineEndingsAreNormalised(): void
    {
        self::assertSame("a\nb\n", (new MarkdownNormalizer())->normalize("a\r\nb"));
    }

    /** Two trailing spaces are a hard line break in Markdown and must survive. */
    public function testHardLineBreaksSurvive(): void
    {
        $out = (new MarkdownNormalizer())->normalize("line one  \nline two");

        self::assertSame("line one  \nline two\n", $out);
    }

    public function testStrayTrailingWhitespaceIsRemoved(): void
    {
        self::assertSame("line\n", (new MarkdownNormalizer())->normalize("line   \n"));
    }

    public function testDocumentEndsWithExactlyOneNewline(): void
    {
        $out = (new MarkdownNormalizer())->normalize("\n\n# Title\n\n\n");

        self::assertSame("# Title\n", $out);
    }

    public function testRichTextIsConvertedButPlainTextIsLeftAlone(): void
    {
        $extension = new MarkdownExtension();

        self::assertSame('**bold**', $extension->fromHtml('<p><strong>bold</strong></p>'));
        self::assertSame('just words', $extension->fromHtml('just words'));
        self::assertSame('', $extension->fromHtml(null));
        self::assertSame('', $extension->fromHtml('   '));
    }

    /** An agent should not have to parse two languages; unconvertible markup is dropped. */
    public function testScriptsAndStylesAreRemoved(): void
    {
        $out = (new MarkdownExtension())->fromHtml('<p>Text</p><script>alert(1)</script><style>p{}</style>');

        self::assertStringNotContainsString('alert', $out);
        self::assertStringNotContainsString('<style', $out);
        self::assertStringContainsString('Text', $out);
    }
}
