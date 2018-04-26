#!/usr/bin/php5.6
<?php
require_once dirname(__DIR__) . "/vendor/autoload.php";

use \Cvsgit\UpdateExecute;

$curr_file = array_shift($argv);
$path = array_shift($argv);
$config = array_shift($argv);
$commit = array_shift($argv) == 'true';
$files = $argv ?: array();

try {


  if (empty($path)) {
    throw new Exception("Invalid param path");
  }

  if (empty($config)) {
    throw new Exception("Invalid param config path");
  }

  ini_set('display_errors', true);

  $parser = new UpdateExecute($path, $config, $commit, $files);
  $result = $parser->execute();

  echo json_encode($result);

} catch (\Exception $err) {

  fwrite(STDERR, "Error: " . $err->getMessage(). "\n");

  if (strpos($err->getMessage(), '[update aborted]') !== false) {
    exit(2);
  }

  exit(1);
}
