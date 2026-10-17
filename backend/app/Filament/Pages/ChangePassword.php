<?php

namespace App\Filament\Pages;

use App\Auth\AdminPassword;
use App\Auth\AdminPasswordChange;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * @property-read Schema $form
 */
class ChangePassword extends Page
{
    protected static ?string $slug = 'password';

    protected static bool $shouldRegisterNavigation = false;

    /** Every submission counts, right or wrong: 5 a minute. */
    private const ATTEMPTS_PER_MINUTE = 5;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function save(): void
    {
        $user = Filament::auth()->user();
        $limiterKey = 'admin-password-change:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($limiterKey, self::ATTEMPTS_PER_MINUTE)) {
            Notification::make()->danger()->title('محاولات كثيرة، حاول بعد '.ceil(RateLimiter::availableIn($limiterKey) / 60).' دقيقة.')->send();

            return;
        }
        RateLimiter::hit($limiterKey, 60);

        $new = $this->form->getState()['password'];
        app(AdminPasswordChange::class)->change($user, $new);

        $this->form->fill();
        Notification::make()->success()->title('تم تغيير كلمة المرور. تم تسجيل الخروج من باقي الأجهزة.')->send();
    }

    public function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        return $schema->statePath('data')->components([
            TextInput::make('current_password')
                ->label('كلمة المرور الحالية')
                ->password()->revealable()->autocomplete('current-password')
                ->required()->maxLength(AdminPassword::MAX)
                ->rule(fn () => fn (string $attribute, mixed $value, \Closure $fail) => Hash::check((string) $value, $user->password_hash)
                    ? null : $fail('كلمة المرور الحالية غير صحيحة.')),
            TextInput::make('password')
                ->label('كلمة المرور الجديدة')
                ->password()->revealable()->autocomplete('new-password')
                ->rules(AdminPassword::rules())
                ->validationMessages(AdminPassword::messages('password'))
                ->rule(fn () => fn (string $attribute, mixed $value, \Closure $fail) => Hash::check((string) $value, $user->password_hash)
                    ? $fail('كلمة المرور الجديدة يجب أن تختلف عن الحالية.') : null)
                ->same('password_confirmation')
                ->validationMessages(['same' => 'تأكيد كلمة المرور غير مطابق.']),
            TextInput::make('password_confirmation')
                ->label('تأكيد كلمة المرور الجديدة')
                ->password()->revealable()->autocomplete('new-password')
                ->required()->maxLength(AdminPassword::MAX)
                ->dehydrated(false),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([Action::make('save')->label('تغيير كلمة المرور')->submit('save')]),
                ]),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'تغيير كلمة المرور';
    }
}
