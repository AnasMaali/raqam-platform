<?php

namespace App\Awareness;

use App\Models\Character;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;

/**
 * The poses of a character (decisions 7.9, 7.10): up to one for each of the five kinds, each with a still picture
 * (always shown, and the only thing shown on weak devices or with "reduce motion") and, optionally, the name of
 * the state to play in the character's Rive file.
 */
class CharacterLibrary
{
    /** @return array<string, array{static_media_id: ?int, rive_state: ?string}> pose code => values, for the five kinds in order */
    public function poses(Character $character): array
    {
        $rows = DB::table('character_poses')->where('character_id', $character->id)->get()->keyBy('pose_type_code');
        $poses = [];
        foreach (DB::table('pose_types')->orderBy('sort_order')->pluck('code') as $code) {
            $row = $rows->get($code);
            $poses[$code] = ['static_media_id' => $row?->static_media_id, 'rive_state' => $row?->rive_state];
        }

        return $poses;
    }

    /** @return list<string> Arabic names of the pose kinds this character still has no picture for */
    public function missing(Character $character): array
    {
        $have = DB::table('character_poses')->where('character_id', $character->id)->pluck('pose_type_code')->all();

        return DB::table('pose_types')->whereNotIn('code', $have)->orderBy('sort_order')->pluck('name_ar')->all();
    }

    /**
     * Sets the poses to exactly what is given. A kind with no picture has no pose; a pose that cards already
     * use cannot be removed, but its picture can be replaced.
     *
     * @param  array<string, array{static_media_id?: mixed, rive_state?: mixed}>  $poses
     *
     * @throws CharacterRejected
     */
    public function savePoses(Character $character, array $poses): void
    {
        $kinds = DB::table('pose_types')->pluck('name_ar', 'code')->all();
        $clean = $this->clean($character->rive_media_id, $poses);

        DB::transaction(function () use ($character, $clean, $kinds) {
            foreach ($clean as $code => $values) {
                $existing = DB::table('character_poses')->where('character_id', $character->id)->where('pose_type_code', $code)->first();

                if ($values === null) {
                    if ($existing !== null) {
                        $used = DB::table('awareness_cards')->where('character_pose_id', $existing->id)->count();
                        if ($used > 0) {
                            throw new CharacterRejected("وضعية «{$kinds[$code]}» مستعملة في {$used} بطاقة توعية، لا يمكن حذفها (بدّل صورتها بدلاً من ذلك).", "poses.{$code}.static_media_id");
                        }
                        DB::table('character_poses')->where('id', $existing->id)->delete();
                    }

                    continue;
                }

                // Explicit update or insert, as everywhere else in this code base: an upsert would hide a clash.
                if ($existing !== null) {
                    DB::table('character_poses')->where('id', $existing->id)->update($values);
                } else {
                    DB::table('character_poses')->insert(['character_id' => $character->id, 'pose_type_code' => $code] + $values);
                }
            }
        });
    }

    /**
     * Checks the poses without writing anything, so a form can refuse before it saves the character itself.
     *
     * @param  array<string, array{static_media_id?: mixed, rive_state?: mixed}>  $poses
     *
     * @throws CharacterRejected
     */
    public function check(?int $riveMediaId, array $poses): void
    {
        $this->clean($riveMediaId, $poses);
    }

    /**
     * @return array<string, array{static_media_id: int, rive_state: ?string}|null> pose code => values to write, null = no pose
     *
     * @throws CharacterRejected
     */
    private function clean(?int $riveMediaId, array $poses): array
    {
        $kinds = DB::table('pose_types')->pluck('name_ar', 'code')->all();
        $clean = [];

        foreach ($poses as $code => $values) {
            if (! is_string($code) || ! isset($kinds[$code])) {
                continue;   // not one of the five kinds
            }
            $name = $kinds[$code];
            $media = $this->mediaId($values['static_media_id'] ?? null);
            $state = trim((string) ($values['rive_state'] ?? ''));

            if ($media === null) {
                if ($state !== '') {
                    throw new CharacterRejected("وضعية «{$name}»: اختر الصورة الثابتة أولاً.", "poses.{$code}.static_media_id");
                }
                $clean[$code] = null;

                continue;
            }
            if (! MediaAsset::query()->publicImages()->whereKey($media)->exists()) {
                throw new CharacterRejected("وضعية «{$name}»: الصورة يجب أن تكون صورة عامة من مكتبة الوسائط.", "poses.{$code}.static_media_id");
            }
            if ($state !== '' && preg_match('/^[A-Za-z0-9 _.\-]{1,60}$/', $state) !== 1) {
                throw new CharacterRejected("وضعية «{$name}»: اسم حالة Rive حروف إنجليزية وأرقام فقط (حتى 60).", "poses.{$code}.rive_state");
            }
            if ($state !== '' && $riveMediaId === null) {
                throw new CharacterRejected("وضعية «{$name}»: أضف ملف Rive للشخصية قبل تحديد حالة الحركة.", "poses.{$code}.rive_state");
            }

            $clean[$code] = ['static_media_id' => $media, 'rive_state' => $state === '' ? null : $state];
        }

        return $clean;
    }

    private function mediaId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 && (int) $value < PHP_INT_MAX / 2 ? (int) $value : -1;
    }
}
