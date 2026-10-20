<?php

namespace App\Content\Subtypes;

use App\Content\PostRuleViolation;
use App\Support\UniqueSlug;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;

/** A podcast episode (5.1): its season and number. Guests and chapters come with the post and the video phase. */
final class EpisodeSubtype extends Subtype
{
    public function kind(): string
    {
        return 'episode';
    }

    public function table(): string
    {
        return 'podcast_episodes';
    }

    public function rules(): array
    {
        return [
            'season_id' => ['required', 'integer', 'min:1', 'max:32767', 'exists:podcast_seasons,id'],
            'episode_number' => ['required', 'integer', 'min:1', 'max:32767'],
        ];
    }

    public function required(): array
    {
        return ['season_id', 'episode_number'];
    }

    public function messages(): array
    {
        return ['season_id.exists' => 'الموسم غير موجود.', 'episode_number.min' => 'رقم الحلقة يبدأ من 1.', 'episode_number.max' => 'رقم الحلقة كبير جداً.', 'episode_number.integer' => 'رقم الحلقة يجب أن يكون رقماً صحيحاً.'];
    }

    public function fields(): array
    {
        return [
            Select::make('season_id')->label('الموسم')->native(false)
                ->options(fn () => DB::table('podcast_seasons')->orderBy('season_number')->get()->mapWithKeys(fn ($s) => [$s->id => 'الموسم '.$s->season_number.($s->title_ar ? ' — '.$s->title_ar : '')])->all())
                ->createOptionForm([
                    TextInput::make('season_number')->label('رقم الموسم')->numeric()->integer()->minValue(1)->maxValue(32767)->required(),
                    TextInput::make('title_ar')->label('اسم الموسم (اختياري)')->maxLength(120),
                ])
                // A season is a playlist (5.1, 8.9): making one makes the other, so its episodes' videos gather there.
                ->createOptionUsing(fn (array $data) => DB::transaction(function () use ($data) {
                    $name = 'الموسم '.$data['season_number'].($data['title_ar'] ? ' — '.$data['title_ar'] : '');
                    $playlist = DB::table('playlists')->insertGetId(['name_ar' => mb_substr($name, 0, 120), 'slug' => UniqueSlug::for('playlists', 'podcast-season-'.$data['season_number'], null, 'season'), 'section_id' => DB::table('sections')->where('key', 'podcast')->value('id')]);

                    return DB::table('podcast_seasons')->insertGetId(['season_number' => $data['season_number'], 'title_ar' => $data['title_ar'] ?: null, 'playlist_id' => $playlist]);
                })),
            TextInput::make('episode_number')->label('رقم الحلقة')->numeric()->integer()->minValue(1)->maxValue(32767),
        ];
    }

    protected function validate(array $values, int $postId): void
    {
        parent::validate($values, $postId);

        $clash = DB::table($this->table())->where('season_id', $values['season_id'])->where('episode_number', $values['episode_number'])
            ->where('post_id', '<>', $postId)->exists();
        if ($clash) {
            throw new PostRuleViolation('رقم الحلقة مستخدم في هذا الموسم.', 'episode.episode_number');
        }
    }
}
