$env:COMPOSE_PROJECT_NAME="moodle34"
$env:MOODLE_DOCKER_WEB_PORT="0.0.0.0:80"
# $env:MOODLE_DOCKER_WEB_HOST=""
$env:MOODLE_DOCKER_WWWROOT=".\moodle"
$env:MOODLE_DOCKER_DB="pgsql"

Write-Host "Copying config.php..." -ForegroundColor Cyan
Copy-Item -Path ".\config.docker-template.php" -Destination "$env:MOODLE_DOCKER_WWWROOT\config.php" -Force

Write-Host "Restarting Docker containers..." -ForegroundColor Cyan
.\bin\moodle-docker-compose down
.\bin\moodle-docker-compose up -d
.\bin\moodle-docker-wait-for-db

Write-Host "Initializing Behat..." -ForegroundColor Cyan
.\bin\moodle-docker-compose exec webserver php admin/tool/behat/cli/init.php
.\bin\moodle-docker-compose exec -u www-data webserver php admin/tool/behat/cli/run.php --tags=@auth_manual

Write-Host "Initializing PHPUnit..." -ForegroundColor Cyan
.\bin\moodle-docker-compose exec webserver php admin/tool/phpunit/cli/init.php
.\bin\moodle-docker-compose exec webserver vendor/bin/phpunit auth/manual/tests/manual_test.php

Write-Host "Installing Moodle Database..." -ForegroundColor Cyan
.\bin\moodle-docker-compose exec webserver php admin/cli/install_database.php --agree-license --fullname="Docker moodle" --shortname="docker_moodle" --summary="Docker moodle site" --adminpass="test" --adminemail="admin@example.com"

Write-Host "Script complete!" -ForegroundColor Green