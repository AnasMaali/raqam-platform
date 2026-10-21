<?php

namespace App\Markets;

use RuntimeException;

/** A currency change the admin asked for cannot be made; the message says why, in Arabic. */
class RateRejected extends RuntimeException {}
