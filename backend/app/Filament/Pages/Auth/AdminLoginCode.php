<?php

namespace App\Filament\Pages\Auth;

use App\Auth\AdminSignIn;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

/**
 * Step 2 of the admin sign-in: the 6-digit code from the e-mail.
 *
 * @property-read Schema $form
 */
class AdminLoginCode extends SimplePage
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            $this->redirect(Filament::getUrl());

            return;
        }

        if (! app(AdminSignIn::class)->hasPending()) {
            $this->redirect(Filament::getLoginUrl());

            return;
        }

        $this->form->fill();
    }

    public function verify(): ?LoginResponse
    {
        $code = $this->form->getState()['code'];

        if (app(AdminSignIn::class)->verify($code)) {
            return app(LoginResponse::class);
        }

        // The sign-in expired or the attempts ran out: start over. Otherwise the code was simply wrong.
        if (! app(AdminSignIn::class)->hasPending()) {
            Notification::make()->danger()->title('انتهت محاولة الدخول، ابدأ من جديد.')->send();
            $this->redirect(Filament::getLoginUrl());

            return null;
        }

        throw ValidationException::withMessages(['data.code' => 'الرمز غير صحيح أو منتهي الصلاحية.']);
    }

    public function resend(): void
    {
        $outcome = app(AdminSignIn::class)->resend();

        $notification = match ($outcome) {
            'sent' => Notification::make()->success()->title('إذا كانت البيانات صحيحة وصلك رمز جديد.'),
            'too_soon' => Notification::make()->warning()->title('انتظر '.app(AdminSignIn::class)->secondsUntilResend().' ثانية قبل طلب رمز جديد.'),
            default => Notification::make()->danger()->title('وصلت الحد الأقصى لطلبات الرمز، حاول لاحقاً.'),
        };

        $notification->send();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('code')
                ->label('رمز التحقق')
                ->required()
                ->regex('/^\s*\d{6}\s*$/')
                ->validationMessages(['regex' => 'الرمز مكوّن من 6 أرقام.'])
                ->inputMode('numeric')
                ->autocomplete('one-time-code')
                ->autofocus()
                ->extraInputAttributes(['dir' => 'ltr', 'maxlength' => 8]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('verify')
                ->footer([
                    Actions::make([
                        Action::make('verify')->label('تأكيد')->submit('verify'),
                        Action::make('resend')->label('إعادة إرسال الرمز')->color('gray')->action('resend'),
                    ])->fullWidth(),
                ]),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'رمز التحقق';
    }

    public function getHeading(): string|Htmlable
    {
        return 'أدخل رمز التحقق';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'إذا كانت البيانات صحيحة وصلك رمز على بريدك الإلكتروني.';
    }
}
