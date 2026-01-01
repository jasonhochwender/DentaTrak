# DentaTrak

A dental lab case tracking and workflow management application.

## Features

- **Case Management**: Track dental cases through workflow stages (Received → In Progress → Quality Check → Ready → Delivered)
- **Multi-Practice Support**: Users can belong to multiple practices with role-based permissions
- **Google Drive Integration**: Automatic file storage and organization
- **Analytics Dashboard**: Track performance metrics and get AI-powered recommendations
- **Team Management**: Assign cases to team members with labels and filtering
- **Billing Integration**: Stripe-powered subscription management

## Requirements

- PHP 8.0+
- MySQL 5.7+ or MariaDB 10.3+
- Composer
- MAMP (for local development) or Cloud Run (for production)

## Installation

### 1. Clone the Repository

```bash
git clone https://github.com/your-org/dentatrak.git
cd dentatrak
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Configure Environment

Copy the example environment file and configure your settings:

```bash
cp .env.example .env
```

Edit `.env` with your actual credentials:

- **Database**: Set your MySQL credentials
- **Google API**: Get credentials from [Google Cloud Console](https://console.cloud.google.com/)
- **Stripe**: Get API keys from [Stripe Dashboard](https://dashboard.stripe.com/apikeys)
- **SendGrid**: Get API key from [SendGrid](https://app.sendgrid.com/settings/api_keys)
- **AI Provider**: Configure OpenAI or Gemini API key

### 4. Set Up Database

Run the database setup script to create required tables:

```bash
php setup-db.php
```

### 5. Configure Web Server

For MAMP:
1. Point the document root to the project directory
2. Ensure MySQL is running on the configured port (default: 3308)

### 6. Access the Application

Open your browser and navigate to:
- Local: `http://localhost/`

## Environment Modes

The application supports multiple environments:

- **Development**: Local MAMP with local database (port 3308)
- **UAT**: Local machine with bridge to production database (port 3307)
- **Production**: Cloud Run with Cloud SQL

To explicitly set the environment, create a `.env_mode` file with one of:
- `development` or `local`
- `uat`

## Project Structure

```
├── api/                 # Backend API endpoints
│   ├── appConfig.php    # Application configuration
│   ├── bootstrap.php    # Environment setup
│   └── ...
├── css/                 # Stylesheets
├── js/                  # JavaScript files
├── images/              # Static images
├── uploads/             # User uploads (logos, etc.)
├── vendor/              # Composer dependencies (gitignored)
├── logs/                # Application logs (gitignored)
├── index.php            # Login page
├── main.php             # Main application
└── ...
```

## Security Notes

- **Never commit `.env`** - Contains sensitive credentials
- **API keys** are loaded from environment variables
- **Session security** includes httponly cookies and CSRF protection
- **Practice isolation** ensures users only see data from their practices

## Development

### Running Locally

1. Start MAMP (Apache + MySQL)
2. Ensure `.env` is configured
3. Access `http://localhost/`

### Code Style

- PHP follows PSR-12 standards
- JavaScript uses ES6+ features
- CSS organized by component

## License

Proprietary - All rights reserved.
