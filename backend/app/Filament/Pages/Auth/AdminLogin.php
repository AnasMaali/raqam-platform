<?php

namespace App\Filament\Pages\Auth;

use App\Auth\AdminSignIn;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Step 1 of the admin sign-in: e-mail and password. Whatever the answer, the visitor moves on to the
 * code page with the same message (see AdminSignIn).
 */
class AdminLogin extends Login
{
    public const PASSWORD_MAX_LENGTH = 128;

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        app(AdminSignIn::class)->begin($data['email'], $data['password']);

        $this->redirect(route(Filament::getCurrentOrDefaultPanel()->generateRouteName('auth.login.code')), navigate: false);

        return null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
        ]);
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->maxLength(self::PASSWORD_MAX_LENGTH)
            ->validationMessages(['max' => 'كلمة المرور أطول من المسموح.']);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('البريد الإلكتروني')
            ->email()
            ->required()
            ->maxLength(254)
            ->autocomplete('username')
            ->autofocus();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }
}
