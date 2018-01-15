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
$prefix = basename(dirname(__DIR__));
$containers = shell_exec('docker ps --format="{{.Names}}" | grep "'.$prefix.'\_"');
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

// Append the SQL commands to create databases and users in a single connection
$mysqlCmd = '';
$mysqlCmd .= "CREATE DATABASE IF NOT EXISTS {$project->database_name}; " . "\n";
$mysqlCmd .= "CREATE USER IF NOT EXISTS {$project->database_user} " .
            "IDENTIFIED BY '{$project->database_password}'; " . "\n";
$mysqlCmd .= "GRANT ALL ON {$project->database_name}.* TO '{$project->database_user}'@'%' " .
            "IDENTIFIED BY '{$project->database_password}'; " . "\n" . "\n";

// Get the PHP containers
$phpcontainers = array_filter($containers, function ($item) {
    return (strpos($item, 'php') !== false);
});

// Foreach the PHP and restart it
foreach ($phpcontainers as $php) {
    // Execute the command
    exec("docker restart {$php}", $output, $result);

    // If not success result message log
    if (0 !== $result) {
        formatLog([
            "Container \"{$php}\" return status \"{$result}\""
        ]);
        exit(6);
    }
    formatLog([
        "Container \"{$php}\" configuration successful"
    ], 'info');
}

// Get the MySQL containers
$mysqlcontainers = array_filter($containers, function ($item) {
    return (strpos($item, 'mysql') !== false);
});

// Foreach the MySQL containers to create the databases
foreach ($mysqlcontainers as $mysql) {
    // If mysql query is empty continue
    if (empty($mysqlCmd)) {
        continue;
    }

    // Get the mapped port from this container
    $port = shell_exec("docker port {$mysql}");
    $port = trim(preg_replace('/(.*)\:(\d+)$/ui', '$2', $port));

    // Instance the pdo conn
    $conn = new PDO(
        "mysql:host=localhost:{$port}",
        'root',
        $projects->rootpassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Execute the create statement
    $result = $conn->exec($mysqlCmd);

    // If not success result message log
    if (empty($result)) {
        formatLog([
            "MySQL container \"{$mysql}\" return status \"{$result}\""
        ]);
        exit(7);
    }
    shell_exec("docker restart {$mysql}");
    formatLog([
        "MySQL container \"{$mysql}\" configuration successful"
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
        'default' => $nocolor,
    ];
    $color = $logColors[$logType] ?: $cyan;
    $count = array_reduce($messages, function ($a, $b) {
        return strlen($a) > strlen($b) ? $a : $b;
    });
    $count = strlen($count) + 1;
    foreach ($messages as &$message) {
        $message = "$color " . str_pad($message, $count, ' ', STR_PAD_RIGHT) . $nocolor;
    }
    $separator = "$color " . str_pad("", $count, ' ', STR_PAD_RIGHT) . $nocolor;
    echo "\n" . $separator . "\n" . implode("\n", $messages) . "\n" . $separator . "\n\n";

    return;
}
