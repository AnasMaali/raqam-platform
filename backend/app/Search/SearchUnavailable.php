<?php

namespace App\Search;

use RuntimeException;

/** Meilisearch did not answer, or refused. The message never carries the key or the engine's raw answer. */
final class SearchUnavailable extends RuntimeException {}
