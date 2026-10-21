<?php

namespace App\Integrations;

use RuntimeException;

/** An outside service did not give a usable answer. The message is short, Arabic and free of keys and addresses. */
class ProviderUnavailable extends RuntimeException {}
