# SummitCess - Trip Service Documentation

This documentation covers the authentication system, guide management, and email notification features implemented in the SummitCess application.

## Table of Contents
1. [Authentication System](#authentication-system)
2. [Guide Management](#guide-management)
3. [Email Notifications](#email-notifications)
4. [Frontend Integration](#frontend-integration)

## Authentication System

The authentication system uses Laravel Sanctum for API token authentication with the following features:

- User registration with role-based access (admin, guide, user)
- Login with token generation
- Protected routes with middleware
- Password hashing and validation

Key files:
- `app/Http/Controllers/Api/AuthController.php` - Handles login/register
- `app/Models/User.php` - User model with role attribute
- `routes/api.php` - API routes with auth middleware

## Guide Management

The guide management system includes:

- Guide registration with KTP verification
- Admin approval workflow
- Guide profile management
- Trip creation and management by guides

Key components:
- `app/Models/Guide.php` - Guide model linked to User
- `app/Http/Controllers/Api/GuideController.php` - Guide CRUD operations
- `app/Http/Controllers/Api/AdminController.php` - Admin approval functions

## Email Notifications

Email notifications are implemented for important events:

### Guide Approval Email
Sent when an admin approves a guide application:

```php
// app/Mail/GuideApprovalMail.php
class GuideApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public $guideName;
    public $loginUrl;

    public function __construct($guideName)
    {
        $this->guideName = $guideName;
        $this->loginUrl = config('app.frontend_url', 'http://localhost:3000') . '/login';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Selamat! Anda Telah Menjadi Guide SummitCess',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.guide-approval',
        );
    }
}
```

### Guide Ban Email
Sent when an admin bans/deletes a guide:

```php
// app/Mail/GuideBanMail.php
class GuideBanMail extends Mailable
{
    use Queueable, SerializesModels;

    public $guideName;

    public function __construct($guideName)
    {
        $this->guideName = $guideName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Pemberitahuan Penonaktifan Akun Guide',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.guide-ban',
        );
    }
}
```

Email templates are stored in:
- `resources/views/emails/guide-approval.blade.php`
- `resources/views/emails/guide-ban.blade.php`

## Frontend Integration

The frontend uses Next.js with the following features:

### Admin Dashboard
- Guide approval interface
- Guide management with ban functionality
- Custom toast notifications for actions

Example of guide ban implementation:
```tsx
// Custom confirmation toast for guide ban
const handleBanGuide = async () => {
  toast((t) => (
    <div className="flex flex-col gap-4">
      <div className="flex items-center gap-2">
        <Trash2 className="h-5 w-5 text-red-500" />
        <p className="font-medium">Hapus Penyedia Jasa?</p>
      </div>
      <p className="text-sm text-gray-600">
        Tindakan ini akan menghapus akun penyedia jasa secara permanen dan tidak dapat dibatalkan.
      </p>
      <div className="flex justify-end gap-2">
        <button
          onClick={() => toast.dismiss(t.id)}
          className="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-800 transition-colors"
        >
          Batal
        </button>
        <button
          onClick={async () => {
            // Ban implementation
          }}
          className="px-3 py-1.5 text-sm bg-red-50 text-red-600 hover:bg-red-100 rounded-md transition-colors"
        >
          Hapus
        </button>
      </div>
    </div>
  ), {
    duration: Infinity,
    style: {
      background: 'white',
      padding: '16px',
      borderRadius: '8px',
      boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)',
    }
  });
};
```

### Guide Dashboard
- Trip creation and management
- Profile management
- Booking management

## Configuration

### Email Configuration
Email is configured in `.env`:

```
MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@summitcess.com"
MAIL_FROM_NAME="${APP_NAME}"
```

For production, consider using:
- Gmail SMTP
- Amazon SES
- SendGrid
- Mailgun

### Frontend URL Configuration
Frontend URL is configured in `.env`:

```
FRONTEND_URL=http://localhost:3000
```

This is used for generating links in emails and redirects.

## Development Notes

- For local email testing, consider using Mailpit or Mailtrap
- Clear cache after configuration changes: `php artisan config:clear`
- Use queue workers in production for better email performance: `QUEUE_CONNECTION=database`