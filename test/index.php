<?php
require "../vendor/autoload.php";

use ldbglobe\i18ndb\i18ndb;

$pdo = $dbh = new PDO('mysql:host=localhost;dbname=test;charset=UTF8','root','');
$i18n = new i18ndb($pdo,'i18n');

$i18n->RegisterLanguageFallback(array('en','fr')); // default language fallback chain

$i18n->set(0,'test','test','fr', 'Ceci est un test');
$i18n->set(0,'test','test','en', 'This is a test');
$i18n->set(0,'test','test','es', 'Esta es una prueba');
$i18n->set(1,'test','test','fr', 'Ceci est un test pour un autre groupe');
$i18n->set(1,'test','test','en', 'This is a test for another group');
$i18n->set(2,'test','test','fr', 'Ce groupe ne contient que du Français');
$i18n->set(3,'test','test','en', 'This group contains only English');
$i18n->set(4,'test','test','es', 'Este grupo contiene solamente español');
?>
<h2>i18n DB content</h2>
<table border="0" cellpadding="10" cellspacing="0">
	<thead>
		<tr>
			<td>Group</td>
			<td>Type</td>
			<td>Key</td>
			<td>Language</td>
			<td>Value</td>
		</tr>
	</thead>
	<tbody>
		<?php
		foreach($i18n->search('') as $v)
		{
			?>
			<tr>
				<td><?=$v->group_id;?></td>
				<td><?=$v->type;?></td>
				<td><?=$v->key;?></td>
				<td><?=$v->language;?></td>
				<td><?=$v->value;?></td>
			</tr>
			<?php
		}
		?>
	</tbody>
</table>
<?php

// registering a static instance globaly accessible through ldbglobe\i18ndb\i18ndb class
i18ndb::RegisterInstance($i18n,'my-super-duper-instance');

function test()
{
	// In this scope previously declared $i18n is not set
	// So we can retrieve the instance by the i18ndb class
	$i18n = i18ndb::LoadInstance('my-super-duper-instance');

	echo "<h2>Direct read of specific text</h2>";
	echo "<h3>0,test,test,fr = </h2>";
	echo '<pre>'.print_r($i18n->get(0,'test','test','fr'),1).'</pre>';
	echo "<h3>0,test,test,en = </h2>";
	echo '<pre>'.print_r($i18n->get(0,'test','test','en'),1).'</pre>';

	echo "<h2>Direct read of specific text cluster (any language)</h2>";
	echo "<h3>0,test,test = </h2>";
	echo '<pre>'.print_r($i18n->get(0,'test','test'),1).'</pre>';

	echo "<h2>Restricted search in group \"0\"</h2>";
	echo "<h3>\"ceci\" = </h2>";
	echo '<pre>'.print_r($i18n->search('ceci',0),1).'</pre>';
	echo "<h3>\"this\" = </h2>";
	echo '<pre>'.print_r($i18n->search('this',0),1).'</pre>';
	echo "<h3>\"test\" = </h2>";
	echo '<pre>'.print_r($i18n->search('test',0),1).'</pre>';

	echo "<h2>Global search over all group</h2>";
	echo "<h3>\"test\" = </h2>";
	echo '<pre>'.print_r($i18n->search('test'),1).'</pre>';

	echo "<h2>Read with language fallback (requested language, then 'en', then 'fr')</h2>";
	echo "<h3>0,test,test,es = </h2>";
	echo '<pre>'.print_r($i18n->getWithFallback(0,'test','test','es'),1).'</pre>';
	echo "<h3>1,test,test,es = </h2>";
	echo '<pre>'.print_r($i18n->getWithFallback(1,'test','test','es'),1).'</pre>';
	echo "<h3>2,test,test,es = </h2>";
	echo '<pre>'.print_r($i18n->getWithFallback(2,'test','test','es'),1).'</pre>';
	echo "<h3>3,test,test,es = </h2>";
	echo '<pre>'.print_r($i18n->getWithFallback(3,'test','test','es'),1).'</pre>';
	echo "<h3>4,test,test,es = </h2>";
	echo '<pre>'.print_r($i18n->getWithFallback(4,'test','test','es'),1).'</pre>';
}
test();
?>
<style>
body {
	font-family: sans-serif;
	padding: 2em;
	background: #222;
	color: #ddd;
}
table {
	background: #fece43;
	color: #333;
	font-family: sans-serif;
}
table thead tr {
	background: #4a4a4a;
}
table thead td {
	color: #fff;
	font-weight: bold;
}
table tfoot tr {
	background: #4a4a4a;
}
table tfoot td {
	color: #fff;
}

h2 {
	font-size: 1.5em;
}
h3 {
	font-size: 1.2em;
}
pre {
	padding: 1em;
	background: #ff9800;
	color: #222;
}
</style>