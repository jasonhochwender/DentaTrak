@echo off
set BUCKET=gs://dtk-app-source

echo === Uploading root PHP files ===
call gsutil cp baa-acceptance.php billing.php clear-google-tokens.php clear-session.php forgot-password.php index.php login.php main.php practice-setup.php privacy.php reset-password.php set-password.php setup-db.php terms.php verify-email.php %BUCKET%
echo --- Root PHP files uploaded ---

echo === Uploading config / infra files ===
call gsutil cp .htaccess app.yaml composer.json composer.lock Dockerfile php.ini %BUCKET%
echo --- Config files uploaded ---

REM echo === Uploading SendGrid vendor files ===
REM call gsutil cp -r vendor\sendgrid %BUCKET%\vendor\
REM call gsutil cp -r vendor\starkbank %BUCKET%\vendor\
REM call gsutil cp vendor\autoload.php %BUCKET%\vendor\
REM echo --- SendGrid vendor files uploaded ---

echo === Uploading directories ===

echo Uploading api/
call gsutil cp -r api %BUCKET%
echo --- api uploaded ---

echo Uploading css/
call gsutil cp -r css %BUCKET%
echo --- css uploaded ---

echo Uploading images/
call gsutil cp -r images %BUCKET%
echo --- images uploaded ---

echo Uploading js/
call gsutil cp -r js %BUCKET%
echo --- js uploaded ---

echo Uploading uploads/
call gsutil cp -r uploads %BUCKET%
echo --- uploads uploaded ---

echo === Deploy complete ===
pause
