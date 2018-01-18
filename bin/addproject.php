<?php

// Get the project
$args = $argv;
array_shift($args); // Remove the filename

// If the project are empty
if (empty($args)) {
    formatLog([
        'Usage:',
        'php bin/addproject.php <projectname>'
    ], 'default');
    exit(1);
}

// Get the containers of this docker-compose
$prefix = preg_replace('/\s|\t|-/ui', '', basename(dirname(__DIR__)));
$containers = shell_exec('docker ps --format="{{.Names}}"');
$containers = trim($containers);
if (empty($containers)) {
    formatLog([
        'No running containers found on this directory',
        'Run "docker-compose up -d" to start/create the containers before include a new project',
    ]);
    exit(2);
}
$containers = explode("\n", $containers);

// Load the project
$jsonFile = __DIR__ . '/../configs/projects.json';
if (!file_exists($jsonFile)) {
    formatLog([
        '"configs/projects.json" not found'
    ]);
    exit(3);
}

// Clear the comments and parse the json
$json = preg_replace('#\/\*([^*]|[\r\n]|(\*+([^*\/]|[\r\n])))*\*+\/#uim', '', file_get_contents($jsonFile));
$projects = json_decode($json);
if (is_null($projects)) {
    formatLog([
        'Invalid json found at "configs/projects.json"',
        'Check the file before continue'
    ]);
    exit(3);
}

// Check if project exists in json
$projectName = $args[0];
if (!property_exists($projects, $projectName)) {
    formatLog([
        "Project \"{$projectName}\" not found in \"projects.json\"",
    ]);
    exit(4);
}
$project = $projects->{$projectName};

// Check if webserver exists on project JSON
if (empty($project->webserver)) {
    formatLog([
        'Webserver not found at project configuration',
        'Check the file before continue'
    ]);
    exit(3);
}

// Check if database exists on project JSON
if (empty($project->database)) {
    formatLog([
        'Database not found at project configuration',
        'Check the file before continue'
    ]);
    exit(3);
}

// Check if database_rootpassword exists on project JSON
if (empty($project->database_rootpassword)) {
    formatLog([
        'Database root password not found at project configuration',
        'Check the file before continue'
    ]);
    exit(3);
}

// Webserver template variables
$vhostsdir = __DIR__ . "/../configs/{$project->webserver}/virtualhosts";
$webserverbase = "{$vhostsdir}/virtualhost.template";

// Check if the webserver directory exists
if (!is_file($webserverbase)) {
    formatLog([
        "The webserver virtualhost directory: '{$vhostsdir}' is not found",
        'Valid values are "nginx" or "apache"'
    ]);
    exit(3);
}

// Set the path from the config file
$webserverconf = "{$vhostsdir}/{$projectName}.conf";

// Copy the Webserver conf
$webserver = copy($webserverbase, $webserverconf);
if ($webserver) {
    $config = file_get_contents($webserverconf);
    $config = strtr($config, [
        '##DOCUMENT_ROOT##' => $project->document_root,
        '##UPLOAD_ROOT##' => $project->upload_root,
        '##SERVER_NAME##' => $project->server_name,
        '##SERVER_ID##' => $projectName,
    ]);
    $webserver = file_put_contents($webserverconf, $config);
}
if (false === $webserver) {
    formatLog([
        "Something wrong occurred while coping \"{$webserverbase}\" to \"{$webserverconf}\"",
    ]);
    exit(5);
}

// Get the Webserver containers
$webservercontainers = array_filter($containers, function ($item) {
    return (strpos($item, 'webserver') !== false);
});

// Foreach the Webserver and restart it
foreach ($webservercontainers as $webserver) {
    // Execute the command
    exec("docker restart {$webserver}", $output, $result);

    // If not success result message log
    if (0 !== $result) {
        formatLog([
            "Container \"{$webserver}\" return status \"{$result}\""
        ]);
        exit(6);
    }
    formatLog([
        "Container \"{$webserver}\" configuration successful"
    ], 'info');
}

// Create the SQL commands array
$databaseCmd = [];
switch ($project->database) {
    case 'mysql':
        $databaseCmd[] = "CREATE DATABASE IF NOT EXISTS {$project->database_name}; ";
        $databaseCmd[] = "CREATE USER IF NOT EXISTS {$project->database_user} " .
                    "IDENTIFIED BY '{$project->database_password}'; ";
        $databaseCmd[] = "GRANT ALL ON {$project->database_name}.* TO '{$project->database_user}'@'%' " .
                    "IDENTIFIED BY '{$project->database_password}'; ";
        break;
    case 'postgres':
        $databaseCmd[] = "CREATE USER {$project->database_user} " .
                         "PASSWORD '{$project->database_password}'; ";
        if (strpos($project->database_name, '.') !== false) {
            list($schema, $database) = explode('.', $project->database_name);
            $databaseCmd[] = "CREATE SCHEMA {$schema}; ";
            $databaseCmd[] = "CREATE DATABASE {$database}; ";

            $databaseCmd[] = "GRANT ALL ON SCHEMA {$schema} TO" .
                             " \"{$project->database_user}\"; ";
        } else {
            $databaseCmd[] = "CREATE DATABASE {$project->database_name}; ";
            $databaseCmd[] = "GRANT ALL ON DATABASE {$project->database_name} TO" .
                             " \"{$project->database_user}\"; ";
        }
        break;
}

// Get the Database containers
$databasecontainers = array_filter($containers, function ($item) {
    return (strpos($item, 'database') !== false);
});

// Foreach the Database containers to create the databases
foreach ($databasecontainers as $database) {
    // If database query is empty continue
    if (empty($databaseCmd)) {
        break;
    }

    // Get the mapped port from this container
    $port = shell_exec("docker port {$database}");
    $port = trim(preg_replace('/(.*)\:(\d+)$/ui', '$2', $port));

    // Instance the pdo conn
    $driver = getDbDriver($project->database);
    $user = getDbRootUser($project->database);
    $conn = new PDO(
        "{$driver}:host=127.0.0.1;port={$port}",
        $user,
        $project->database_rootpassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Execute the create statement
    foreach ($databaseCmd as $i => $cmd) {
        try {
            $result = $conn->exec($cmd);
            formatLog([
                "Executed \"{$cmd}\""
            ], 'debug');
        } catch (PDOException $e) {
            formatLog([
                "Database container \"{$database}\" return status \"{$e->getMessage()}\""
            ]);
            exit(7);
        }
    }

    shell_exec("docker restart {$database}");
    formatLog([
        "Database container \"{$database}\" configuration successful"
    ], 'info');
}

/**
 * Helper functions
 */
function formatLog($messages, $logType = 'error')
{
    // Color helpers
    $red = "\033[0;41m";
    $cyan = "\033[0;46m";
    $blue = "\033[0;44m";
    $yellow = "\033[30;43;5m";
    $green = "\033[0;42m";
    $purple = "\033[0;45m";
    $nocolor = "\033[0m";
    $logColors = [
        'error' => $red,
        'warn' => $yellow,
        'info' => $blue,
        'debug' => $green,
        'default' => $nocolor,
    ];
    $color = $logColors[$logType] ?: $cyan;
    $count = array_reduce($messages, function ($a, $b) {
        return strlen($a) > strlen($b) ? $a : $b;
    });
    $count = strlen($count) + 2;
    $start = "{$color}  ";
    foreach ($messages as &$message) {
        $message = $start . str_pad($message, $count, ' ', STR_PAD_RIGHT) . $nocolor;
    }
    $separator = $start . str_pad("", $count, ' ', STR_PAD_RIGHT) . $nocolor;
    echo "\n" . $separator . "\n" . implode("\n", $messages) . "\n" . $separator . "\n\n";

    return;
}

function getDbData($database, $dataType)
{
    switch ($database) {
        case 'mysql':
            $driver = 'mysql';
            $user = 'root';
            break;

        case 'postgres':
            $driver = 'pgsql';
            $user = 'postgres';
            break;

        default:
            throw new Exception("Invalid database", 1);
            break;
    }
    if ($dataType === 'driver') {
        return $driver;
    }
    return $user;
}

function getDbDriver($database)
{
    return getDbData($database, 'driver');
}

function getDbRootUser($database)
{
    return getDbData($database, 'user');
}
