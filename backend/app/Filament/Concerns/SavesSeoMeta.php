<?php

namespace App\Filament\Concerns;

use App\Seo\SeoMetaSaver;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/** For a create or edit page whose form has a SeoSection: fills it from the record and saves it after the record. */
trait SavesSeoMeta
{
    /** @var array<string, mixed> */
    private array $seoData = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['seo'] = app(SeoMetaSaver::class)->load($this->getRecord());

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->takeSeo($data);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->takeSeo($data);
    }

    /** Sets the SEO part aside, checked now so a mistake in it stops the save before anything is written. */
    protected function takeSeo(array $data): array
    {
        $this->seoData = Arr::pull($data, 'seo') ?? [];

        try {
            app(SeoMetaSaver::class)->check($this->seoData);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["data.seo.{$k}" => $m])->all());
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->syncSeo();
    }

    protected function afterCreate(): void
    {
        $this->syncSeo();
    }

    /** @return bool whether the SEO values changed */
    protected function syncSeo(): bool
    {
        return app(SeoMetaSaver::class)->sync($this->getRecord(), $this->seoData);
    }
}
