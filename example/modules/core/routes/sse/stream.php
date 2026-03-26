<?php

declare(strict_types=1);

// Raw SSE stream — consumed by hx-ext="sse" on the ticker page.
// No layout, no HTML — just a stream of tick events.

sse(function (): \Generator {
    $i = 0;
    while (true) {
        yield event('tick',
            h('.ticker-value', ['id' => 'ticker-value'],
                '#' . $i . ' — ' . date('H:i:s'),
            )
        );
        $i++;
        sleep(1);
    }
}, heartbeat: 15);