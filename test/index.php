<?php
require "../vendor/autoload.php";

use ldbglobe\i18ndb\i18ndb;

$pdo = $dbh = new PDO('mysql:host=localhost;dbname=test;charset=UTF8','root','');
$i18n = new i18ndb($pdo,'i18n');

$i18n->set(0,'test','test','fr', 'Ceci est un test');
$i18n->set(0,'test','test','en', 'This is a test');

echo '<pre>'.print_r($i18n->get(0,'test','test','fr'),1).'</pre>';
echo '<pre>'.print_r($i18n->get(0,'test','test','en'),1).'</pre>';
echo '<pre>'.print_r($i18n->get(0,'test','test'),1).'</pre>';