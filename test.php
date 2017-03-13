<?php

require 'vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

/**
 * Returns the value of the parameter in case it is a link. Like thi %database_port%
 * @param string $value
 * @param array $buzzConfig
 * @return string|int
 */
function getParameterValue($value, $buzzConfig){
    if(substr($value,0,1) == "%"){
        $paramName = str_replace("%","",$value);
        return $buzzConfig['parameters'][$paramName];
    }else{
        return $value;
    }
}

/*
 * PARAMETERS ARE COMING FROM THE BUZZ RADAR WEB PROJECT
 */
$projectConfig = Yaml::parse(file_get_contents('./config/parameters.yml'));
$buzzConfig = Yaml::parse(file_get_contents($projectConfig['parameters']['symfony_parameters_file']));

/*
 * POSTGRES CONFIGURATION
 */
$config['pgHost'] = getParameterValue($buzzConfig['parameters']['database_host'], $buzzConfig);
$config['pgPort'] = getParameterValue($buzzConfig['parameters']['connections']['default']['port'], $buzzConfig);
$config['pgUser'] = getParameterValue($buzzConfig['parameters']['database_user'], $buzzConfig);
$config['pgPassword'] = getParameterValue($buzzConfig['parameters']['database_password'], $buzzConfig);
$config['pgDatabase'] = getParameterValue($buzzConfig['parameters']['database_name'], $buzzConfig);

/*
 * REDIS CONFIGURATION
 */
$config['redisHost'] = getParameterValue($buzzConfig['parameters']['redis_cache_host'], $buzzConfig);
$config['redisPort'] = getParameterValue($buzzConfig['parameters']['redis_cache_port'], $buzzConfig);
$config['redisPassword'] = getParameterValue($buzzConfig['parameters']['redis_cache_password'], $buzzConfig);

/*
 * TESTING POSTGRES
 *  - TESTING POSTGRES MAIN DATABASE
 */
$connectionStringSkeleton = "host=%s port=%s dbname=%s user=%s password=%s";
$connectionString = sprintf($connectionStringSkeleton,
    $config['pgHost'],
    $config['pgPort'],
    $config['pgDatabase'],
    $config['pgUser'],
    $config['pgPassword']
);

$servers = [];
try {
    $conn = pg_connect($connectionString);
    if(!$conn)
    {
        header('HTTP/1.1 500 Internal Server Error');
        exit("Whoops, couldn't connect to the main postgres db!");
    }
    $result = pg_query( $conn, "SELECT * FROM server" );
    $servers = pg_fetch_all( $result );
    //var_dump($res);
    $response[] = "MAIN DATABASE OK";
    pg_close($conn);
}
catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    exit("Whoops, couldn't connect to the main postgres db!");
}

/*
 * - TESTING SLAVE DATABASES
 */

foreach ($servers as $key => $server){
    $doctrineConnectionName = $server['doctrine_connection_name'];
    $config['slaveDatabases'][$key]['doctrineConnectionName'] = $doctrineConnectionName;
    $config['slaveDatabases'][$key]['host'] = getParameterValue($buzzConfig['parameters']['connections'][$doctrineConnectionName]['host'], $buzzConfig);
    $config['slaveDatabases'][$key]['dbName'] = getParameterValue($buzzConfig['parameters']['connections'][$doctrineConnectionName]['dbname'], $buzzConfig);
    $config['slaveDatabases'][$key]['port'] = getParameterValue($buzzConfig['parameters']['connections'][$doctrineConnectionName]['port'], $buzzConfig);
    $connectionString = sprintf($connectionStringSkeleton,
        $config['slaveDatabases'][$key]['host'],
        $config['slaveDatabases'][$key]['port'],
        $config['slaveDatabases'][$key]['dbName'],
        $config['pgUser'],
        $config['pgPassword']
    );

    try {
        $conn = pg_connect($connectionString);
        if(!$conn)
        {
            header('HTTP/1.1 500 Internal Server Error');
            exit("Whoops, couldn't connect to the main postgres db!");
        }
        $result = pg_query( $conn, "SELECT count(1) FROM data_photo LIMIT 1" );
        pg_fetch_array( $result );
        //var_dump($res);
        $response[] = $doctrineConnectionName." DATABASE OK";
        pg_close($conn);
    }
    catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        exit(sprintf("Whoops, couldn't connect to the %s postgres db!",$doctrineConnectionName));
    }
}

/*
 * END TESTING POSTGRES
 */

/*
 * TESTING REDIS CONNECTION
 */
$client = new Predis\Client([
    'host' => $config['redisHost'],
    'port' => $config['redisPort'],
    'scheme' => 'tcp',
    'password' => $config['redisPassword']
]);

try{
    $client->connect();
    $response[] = "REDIS CACHE OK";
}catch (\Predis\CommunicationException $e){
    header('HTTP/1.1 500 Internal Server Error');
    exit("Whoops, couldn't connect to the remote redis instance!");
}
/*
 * END TESTING REDIS
 */

print_r($response);