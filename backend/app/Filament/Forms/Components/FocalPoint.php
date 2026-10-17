<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;

/**
 * Click on the picture to say which part matters most (decision 9.5). The state is ['x' => 0..1, 'y' => 0..1]
 * or null, relative to the image itself, so it means the same on every screen and in either text direction.
 */
class FocalPoint extends Field
{
    protected string $view = 'filament.forms.focal-point';

    protected string|Closure|null $imageUrl = null;

    public function imageUrl(string|Closure|null $url): static
    {
        $this->imageUrl = $url;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->evaluate($this->imageUrl);
    }
}
