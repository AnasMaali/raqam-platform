<?php

namespace App\Filament\Concerns;

use App\Awareness\CharacterLibrary;
use App\Awareness\CharacterRejected;
use Illuminate\Validation\ValidationException;

/** For the character pages: the five poses are not columns, so they are set aside and saved after the character. */
trait SavesCharacterPoses
{
    /** @var array<string, mixed> */
    private array $poseData = [];

    protected function takePoses(array $data): array
    {
        $this->poseData = (array) ($data['poses'] ?? []);
        unset($data['poses']);

        // Refuse before the character itself is written; only "a card uses this pose" has to wait for the saved record.
        try {
            $rive = $data['rive_media_id'] ?? null;
            app(CharacterLibrary::class)->check(is_scalar($rive) && ctype_digit((string) $rive) ? (int) $rive : null, $this->poseData);
        } catch (CharacterRejected $e) {
            throw ValidationException::withMessages(["data.{$e->field}" => $e->getMessage()]);
        }

        return $data;
    }

    /** @return bool whether a pose changed */
    protected function savePoses(): bool
    {
        try {
            return app(CharacterLibrary::class)->savePoses($this->getRecord(), $this->poseData);
        } catch (CharacterRejected $e) {
            throw ValidationException::withMessages(["data.{$e->field}" => $e->getMessage()]);
        }
    }
}
