<?php

namespace App\Search;

use RuntimeException;

/** A group of synonyms that cannot be saved, with the reason in Arabic. */
final class SynonymRejected extends RuntimeException {}
