<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

final class StreamAccumulator
{
    private string $text = '';
    private ?Usage $usage = null;

    public function consume(StreamEvent $event): void
    {
        if ($event->type === StreamEventType::TEXT_DELTA) {
            $this->text .= (string) ($event->data['text'] ?? '');
        }

        if ($event->type === StreamEventType::FINISH && isset($event->data['usage']) && $event->data['usage'] instanceof Usage) {
            $this->usage = $event->data['usage'];
        }
    }

    public function finalize(): StreamResult
    {
        return new StreamResult(text: $this->text, usage: $this->usage);
    }
}
