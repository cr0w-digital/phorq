<?php

declare(strict_types=1);

return json(['count' => $req->signal('count', 0) - 1]);