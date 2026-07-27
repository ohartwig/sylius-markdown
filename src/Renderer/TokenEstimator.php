<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Renderer;

/**
 * Estimates the token count advertised in the x-markdown-tokens header.
 *
 * Four characters per token is the usual rule of thumb for English and is what
 * the surrounding ecosystem assumes. It is only an estimate — the header exists
 * so an agent can budget a fetch, not so it can bill against it.
 */
final class TokenEstimator
{
    private const CHARS_PER_TOKEN = 4;

    public function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }
}
