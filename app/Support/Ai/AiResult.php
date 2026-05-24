<?php

namespace App\Support\Ai;

final class AiResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?string $text = null,
        public readonly ?string $error = null,
        public readonly array $usage = [],
    ) {
    }

    public static function disabled(string $provider, string $model, string $reason): self
    {
        return new self(false, $provider, $model, null, $reason);
    }
}
