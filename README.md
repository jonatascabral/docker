Docker Simple Configuration for PHP development
---
Docker compose file to configure a simple php development server with nginx or apache, using the mysql database;

## Requirements
- Docker >= 1.13;
- Docker compose >= 1.18;
- PHP >= 5.6 (For command line project binary);

## Includes
- PHP 7.1 FPM with extensions:

    |  |  |  |  |  |
    | --- | --- | --- | --- | --- |
    | bcmath | calendar | Core | ctype | curl |
    | date | dom | fileinfo | filter | ftp |
    | gd | gettext | hash | iconv | intl |
    | json | libxml | mbstring | mcrypt | mysqlnd |
    | openssl | pcre | PDO | pdo_mysql | pdo_sqlite |
    | Phar | posix | readline | Reflection | session |
    | SimpleXML | soap | SPL | sqlite3 | standard |
    | tokenizer | xml | xmlreader | xmlwriter | zlib |
    - Check the php docker file on _configs/docker/images/php/Dockerfile_ to install another extensions
    - See [php-docker](https://hub.docker.com/_/php/) for more information about installing extensions on a php docker container;
- MySQL 5.7;
- Nginx 1.13;
- Apache 2.24;

## Usage
```sh
# Duplicate the file __virtualhosts.yml.example__ to __virtualhosts.yml__
$ cp virtuahosts.yml.example virtualhosts.yml
```

```ruby
...
# Select your prefered webserver on on __docker-compose.yml__ service __webserver__
  webserver:
    extends:
      file: webserver.yml
      # service: nginx
      # service: apache
...
```

```sh
# Start the containers
$ docker-compose up -d
```

- Enjoy the docker magic :laughing:

## Adding projects
The project comes with a php file to include projects (_bin/addproject.php_), the file reads a json and create the virtualhost, database and user for the project;
```sh
# Duplicate the file __configs/projects.json.default__ to __configs/projects.json__
$ cp configs/projects.json.default configs/projects.json
```

```json
// Configure the project
...
  "server_id": {
    "webserver": "apache|nginx",
    "server_name": "",
    "document_root": "",
    "upload_root": "",
    "database_name": "",
    "database_user": "",
    "database_password": ""
  },
...
```
- Check the comments on file for more information

```sh
# Run the php file
$ php bin/addproject.php <project name>
```

### Example
```json
...
  "foo-project": {
    "webserver": "nginx",
    "server_name": "foo.bar.com",
    "document_root": "/var/www/foobar",
    "upload_root": "/var/www/uploads/foobar",
    "database_name": "foo_bar",
    "database_user": "foo_bar_user",
    "database_password": "foo_bar_secret"
  },
...
```
```sh
$ php bin/addproject.php foo-project
```
- Don't forget to append the __server_name__ to your machine hosts file;

### Important
If the __MYSQL_ROOT_PASSWORD__ is changed on _docker-compose.yml_ you must change the value of __rootpassword__ on _projects.json_
