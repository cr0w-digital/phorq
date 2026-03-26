<?php

declare(strict_types=1);

session_start();
$_SESSION['authed'] = false;

return redirect('/');