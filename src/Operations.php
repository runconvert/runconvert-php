<?php

declare(strict_types=1);

namespace RunConvert;

/** Formats, targets, engines and options. Public: no key needed. */
final class Operations
{
    public function __construct(private RunConvert $c)
    {
    }

    /** Every input format with its targets: ['categories' => [...], 'formats' => [...]]. */
    public function list(?string $category = null): array
    {
        return $this->c->request('GET', '/operations', null, ['category' => $category]);
    }

    /** What one format converts to, with credit costs. */
    public function forInput(string $input): array
    {
        return $this->c->request('GET', '/operations', null, ['input' => strtolower($input)]);
    }

    /** Engines for one conversion and the options a convert task accepts. */
    public function forPair(string $input, string $output): array
    {
        return $this->c->request('GET', '/operations', null, ['input' => strtolower($input), 'output' => strtolower($output)]);
    }
}
