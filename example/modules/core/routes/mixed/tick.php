<?php

sse(function (): \Generator {
    $i = 0;
    while (true) {
        yield \phorq\datastar\signals(['tick' => $i, 'ts' => date('H:i:s')]);
        $i++;
        sleep(1);
    }
}, heartbeat: 15);
