<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Tests\Negotiation;

use Koh\SyliusMarkdown\Negotiation\AcceptParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AcceptParserTest extends TestCase
{
    #[DataProvider('headers')]
    public function testNegotiation(string $accept, bool $expected, string $why): void
    {
        self::assertSame($expected, (new AcceptParser())->wantsMarkdown($accept), $why);
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function headers(): iterable
    {
        yield 'explicit' => ['text/markdown', true, 'the plain case'];

        yield 'with charset' => ['text/markdown; charset=utf-8', true, 'parameters do not change the type'];

        yield 'case-insensitive' => ['TEXT/MARKDOWN', true, 'media types are case-insensitive'];

        yield 'preferred over html' => [
            'text/markdown;q=1.0, text/html;q=0.9',
            true,
            'markdown outranks html',
        ];

        yield 'html preferred' => [
            'text/html;q=1.0, text/markdown;q=0.5',
            false,
            'a client that merely tolerates markdown must still get html',
        ];

        yield 'equal quality' => [
            'text/html;q=0.9, text/markdown;q=0.9',
            true,
            'a tie goes to markdown — the client accepts either',
        ];

        yield 'explicitly rejected' => [
            'text/markdown;q=0, text/html',
            false,
            'q=0 is a rejection, not a request — the naive str_contains check gets this wrong',
        ];

        // Every browser sends */* in the tail. Counting it as competition would
        // mean no browser could ever request markdown.
        yield 'browser header' => [
            'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            false,
            'an ordinary browser must get html',
        ];

        yield 'browser header asking for markdown' => [
            'text/markdown,*/*;q=0.8',
            true,
            'the wildcard tail must not veto an explicit request',
        ];

        yield 'wildcard only' => [
            '*/*',
            false,
            'a wildcard is not a preference for markdown',
        ];

        yield 'whitespace' => ['  text/markdown ;  q=0.8  ', true, 'surrounding whitespace is legal'];

        yield 'unrelated' => ['application/json', false, 'no markdown mentioned'];

        yield 'empty' => ['', false, 'no header at all'];

        yield 'out-of-range q' => [
            'text/markdown;q=5',
            true,
            'a malformed q is clamped, not honoured as a super-preference',
        ];
    }

    public function testMissingHeaderIsNotARequest(): void
    {
        self::assertFalse((new AcceptParser())->wantsMarkdown(null));
    }
}
