<?php

namespace App\Console;

use App\Settings\FeatureFlagRefused;
use App\Settings\FeatureFlags;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FeatureSwitchCommand extends Command
{
    protected $signature = 'feature:switch {key : The feature, e.g. booking} {state : on or off}';

    protected $description = 'Switch a feature on or off from the server (the only way to turn on the ones the panel cannot)';

    public function handle(FeatureFlags $flags): int
    {
        $key = (string) $this->argument('key');
        $state = (string) $this->argument('state');

        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('The state is "on" or "off".');

            return self::INVALID;
        }

        try {
            $flags->set($key, $state === 'on', null, fromServer: true);
        } catch (ModelNotFoundException) {
            $this->error('There is no feature "'.$key.'".');

            return self::FAILURE;
        } catch (FeatureFlagRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($key.' is '.$state.'.');

        return self::SUCCESS;
    }
}
