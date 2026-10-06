<?php

namespace App\Filament\Resources\EmailTemplateResource\Pages;

use App\Filament\Resources\EmailTemplateResource;
use App\Mail\TransactionalMail;
use App\Models\SiteSetting;
use App\Support\Email\SampleData;
use App\Support\Email\TemplateDefinitions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class EditEmailTemplate extends EditRecord
{
    protected static string $resource = EmailTemplateResource::class;

    public function getTitle(): string
    {
        return $this->record->label();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTest')
                ->label('Send test email to me')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Send a test email?')
                ->modalDescription(fn (): string => 'Sends this email, as it is in the form now and filled in with a made-up order, to '.(Auth::user()?->email ?? 'you').' using the mail settings from the Integrations page.')
                ->modalSubmitActionLabel('Send')
                ->action(fn () => $this->sendTest()),
        ];
    }

    /**
     * Unchanged wording is stored as blank, so it keeps following the built-in text (and any later improvement to it).
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return array_merge($data, $this->record->formState());
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $defaults = TemplateDefinitions::defaults($this->record->key);

        foreach (['subject', 'intro_html', 'closing_html', 'footer_note'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === trim($defaults[$field])) {
                $data[$field] = null;
            }
        }

        return $data;
    }

    private function sendTest(): void
    {
        $to = Auth::user()?->email;

        if (blank($to)) {
            Notification::make()->danger()->title('Your account has no email address')->send();

            return;
        }

        $wording = EmailTemplateResource::wordingFrom($this->form->getRawState());

        try {
            Mail::to($to)->send(new TransactionalMail(
                $this->record->key,
                SampleData::order(),
                SampleData::customer(),
                SiteSetting::query()->first() ?? new SiteSetting,
                $wording,
            ));
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('The test email could not be sent')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title("Test email sent to {$to}")
            ->body('Sent with the "'.config('mail.default').'" mailer.')
            ->send();
    }
}
