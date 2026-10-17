<?php

namespace App\Settings;

/** The database refused a feature switch because of the dependencies between features; the message is Arabic and ready to show. */
final class FeatureFlagRefused extends \RuntimeException {}
