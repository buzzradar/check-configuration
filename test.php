<?php

require 'vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

/*
 * PARAMETERS ARE COMING FROM THE BUZZ RADAR WEB PROJECT
 */
$projectConfig = Yaml::parse(file_get_contents('./config/parameters.yml'));
$buzzConfig = Yaml::parse(file_get_contents($projectConfig['parameters']['symfony_parameters_file']));

/*
 * POSTGRES CONFIGURATION
 */
$config['pgHost'] = $buzzConfig['parameters']['database_host'];
$pgPort = $buzzConfig['parameters']['connections']['default']['port'];

if(substr($pgPort,0,1) == "%"){
    $paramName = $paramName = str_replace("%","",$pgPort);
    $config['pgPort'] = $buzzConfig['parameters'][$paramName];
}else{
    $config['pgPort'] = $pgPort;
}
$config['pgUser'] = $buzzConfig['parameters']['database_user'];
$config['pgPassword'] = $buzzConfig['parameters']['database_password'];
$config['pgDatabase'] = $buzzConfig['parameters']['database_name'];

/*
 * REDIS CONFIGURATION
 */
$config['redisHost'] = $buzzConfig['parameters']['redis_cache_host'];
$config['redisPort'] = $buzzConfig['parameters']['redis_cache_port'];
$config['redisPassword'] = $buzzConfig['parameters']['redis_cache_password'];

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
    $slaveHost = $buzzConfig['parameters']['connections'][$doctrineConnectionName]['host'];
    if(substr($slaveHost,0,1) == "%"){
        $paramName = str_replace("%","",$slaveHost);
        $config['slaveDatabases'][$key]['host'] = $buzzConfig['parameters'][$paramName];
    }else{
        $config['slaveDatabases'][$key]['host'] = $slaveHost;
    }
    $connectionString = sprintf($connectionStringSkeleton,
        $config['slaveDatabases'][$key]['host'],
        $config['pgPort'],
        $config['pgDatabase'],
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