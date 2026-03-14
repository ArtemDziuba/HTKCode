export COMPOSE_PROJECT_NAME=moodle34
export MOODLE_DOCKER_WEB_PORT=0.0.0.0:80
#export MOODLE_DOCKER_WEB_HOST=
export MOODLE_DOCKER_WWWROOT=./moodle
export MOODLE_DOCKER_DB=pgsql

cp config.docker-template.php $MOODLE_DOCKER_WWWROOT/config.php

bin/moodle-docker-compose down
bin/moodle-docker-compose up -d
bin/moodle-docker-wait-for-db

bin/moodle-docker-compose exec webserver php admin/tool/behat/cli/init.php
bin/moodle-docker-compose exec -u www-data webserver php admin/tool/behat/cli/run.php --tags=@auth_manual

bin/moodle-docker-compose exec webserver php admin/tool/phpunit/cli/init.php
bin/moodle-docker-compose exec webserver vendor/bin/phpunit auth/manual/tests/manual_test.php

bin/moodle-docker-compose exec webserver php admin/cli/install_database.php --agree-license --fullname="Docker moodle" --shortname="docker_moodle" --summary="Docker moodle site" --adminpass="test" --adminemail="admin@example.com"
