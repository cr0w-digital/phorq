<?php

declare(strict_types=1);

namespace phorq;

/**
 * SSE event value object.
 */
final class SseEvent
{
    public function __construct(
        public readonly ?string $name,
        public readonly mixed   $data,
        public readonly ?string $id          = null,
        public readonly bool    $isComment   = false,
        public readonly ?string $commentText = null,
        public readonly bool    $raw         = false, // raw data lines — used by Datastar events
    ) {}

    public static function comment(string $text): self
    {
        return new self(null, null, null, true, $text);
    }

    public function encode(): string
    {
        if ($this->isComment) {
            return ': ' . $this->commentText . "\n\n";
        }

        $out = '';

        if ($this->id !== null) {
            $out .= 'id: ' . $this->id . "\n";
        }

        if ($this->name !== null) {
            $out .= 'event: ' . $this->name . "\n";
        }

        if ($this->raw) {
            foreach (explode("\n", (string) $this->data) as $line) {
                $out .= 'data: ' . $line . "\n";
            }
        } else {
            $data = is_array($this->data) || is_object($this->data)
                ? json_encode($this->data)
                : (string) $this->data;

            foreach (explode("\n", $data) as $line) {
                $out .= 'data: ' . $line . "\n";
            }
        }

        $out .= "\n";

        return $out;
    }
}