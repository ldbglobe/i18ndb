<?php
require "../vendor/autoload.php";

use ldbglobe\i18ndb\i18ndb;

$pdo = $dbh = new \PDO('mysql:host=localhost;dbname=test;charset=UTF8','root','');
$i18n0 = new i18ndb($pdo,'i18n0');
$i18n1 = new i18ndb($pdo,'i18n1');

$i18n0->RegisterLanguageFallback(array('en','fr')); // default language fallback chain

$i18n0->set('test',1,'title','fr', 'Titre de test');
$i18n0->set('test',1,'description','fr', 'Description de test');
$i18n0->set('test',1,'title','en', 'Test title');
$i18n0->set('test',1,'description','en', 'Test description');
$i18n0->set('test',1,'title','es', 'Título del ensayo');
$i18n0->set('test',1,'description','es', 'Descripción del ensayo');

// no English version for this one
$i18n0->set('test',2,'title','fr', 'FR(2)');
$i18n0->set('test',2,'title','es', 'ES(2)');

// no French version for this one
$i18n0->set('test',3,'title','en', 'EN(3)');
$i18n0->set('test',3,'title','es', 'ES(3)');

$i18n1->registerLanguageFallback(array('en','fr','es')); // default language fallback chain

$i18n1->set('test',1,'title','fr', 'Titre de test');
$i18n1->set('test',1,'description','en', 'Test description');
$i18n1->set('test',1,'title','es', 'Título del ensayo');
$i18n1->set('test',1,'description','es', 'Descripción del ensayo');

// no English version for this one
$i18n1->set('test',2,'title','es', 'ES(2)');

// no French version for this one
$i18n1->set('test',3,'title','en', 'EN(3)');
$i18n1->set('test',3,'title','es', 'ES(3)');

$i18n1->set('test',3,'indexed','fr', array('test 0', 'test 1', 'test 2'));
$i18n1->set('test',3,'indexed','fr', 'test 0 Edited',0);

$i18n1->set('test',3,'removed','fr', array('test 0', 'test 1', 'test 2'));
$i18n1->set('test',3,'removed','en', array('test 0', 'test 1', 'test 2'));
$i18n1->clear('test',3,'removed');



// registering a static instance globaly accessible through ldbglobe\i18ndb\i18ndb class
i18ndb::RegisterInstance($i18n0,'instance0');
i18ndb::RegisterInstance($i18n1,'instance1');

function unit($v,$instanceName,$expectedIdx)
{
	$expected_results = array(
		'instance0' => array(
			'Titre de test',
			'Test title',
			array(
				"en"=>"Test title",
				"es"=>"Título del ensayo",
				"fr"=>"Titre de test",
			),
			'Título del ensayo',
			'FR(2)',
			'FR(2)',
			'EN(3)',
			'EN(3)',
		),
		'instance1' => array(
			'Titre de test',
			false,
			array(
				"es"=>"Título del ensayo",
				"fr"=>"Titre de test",
			),
			'Título del ensayo',
			'ES(2)',
			'ES(2)',
			'EN(3)',
			'EN(3)',
		)
	);
	$expected = isset($expected_results[$instanceName][$expectedIdx]) ? $expected_results[$instanceName][$expectedIdx]:null;

	ob_start(); var_dump($v); $dump = ob_get_clean();
	ob_start(); var_dump($expected); $dump_expected = ob_get_clean();

	if($v===$expected)
		echo '<pre class="success">'.$dump.'</pre>';
	else
	{
		echo '<pre class="fail">'.$dump.'</pre>';
		echo '<pre class="expected">Expected value was<br>'.$dump_expected.'</pre>';
	}
}

function test($instanceName)
{
	// In this scope previously declared $i18n is not set
	// So we can retrieve the instance by the i18ndb class
	$i18n = i18ndb::LoadInstance($instanceName);

	//print_r($i18n->get('test',3,'indexed'));

	echo "<h2>Direct read of specific text</h2>";
	echo "<h3>test,1,title,fr = </h2>"; unit($i18n->get('test',1,'title','fr'),$instanceName,0);
	echo "<h3>test,1,title,en = </h2>"; unit($i18n->get('test',1,'title','en'),$instanceName,1);

	echo "<h2>Direct read of specific text cluster (any language)</h2>";
	echo "<h3>test,1,title = </h2>"; unit($i18n->get('test',1,'title'),$instanceName,2);

	echo "<h2>Read with language fallback</h2>";
	echo "<h3>test,1,title,es = </h2>"; unit($i18n->getWithFallback('test',1,'title','es'),$instanceName,3);
	echo "<h3>test,2,title,en = </h2>"; unit($i18n->getWithFallback('test',2,'title','en'),$instanceName,4);
	echo "<h3>test,2,title,en = </h2>"; unit($i18n->getWithFallback('test',2,'title','en'),$instanceName,5);
	echo "<h3>test,3,title,fr = </h2>"; unit($i18n->getWithFallback('test',3,'title','fr'),$instanceName,6);
	echo "<h3>test,3,title,fr = </h2>"; unit($i18n->getWithFallback('test',3,'title','fr'),$instanceName,7);

	echo "<h2>Search</h2>";
	echo "<h3>\"titre\" = </h2>";
	echo '<pre>'.print_r($i18n->search('titre'),1).'</pre>';
	echo "<h3>\"titre\" = </h2>";
	echo '<pre>'.print_r($i18n->search('title'),1).'</pre>';
	echo "<h3>\"test\" = </h2>";
	echo '<pre>'.print_r($i18n->search('test'),1).'</pre>';
}

?>
<table border="0" cellpadding="10" cellspacing="0">
	<tr>
		<td valign="top">
			<h2>i18n0 DB content</h2>
			<table class="resume" border="0" cellpadding="10" cellspacing="0">
				<thead>
					<tr>
						<td>Type</td>
						<td>Id</td>
						<td>Key</td>
						<td>Language</td>
						<td>Index</td>
						<td>Value</td>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach($i18n0->search('') as $v)
					{
						?>
						<tr>
							<td><?=$v->type;?></td>
							<td><?=$v->id;?></td>
							<td><?=$v->key;?></td>
							<td><?=$v->language;?></td>
							<td><?=$v->index;?></td>
							<td><?=$v->value;?></td>
						</tr>
						<?php
					}
					?>
				</tbody>
			</table>
			<?php
			test('instance0');
			?>
		</td>
		<td valign="top">
			<h2>i18n1 DB content</h2>
			<table class="resume" border="0" cellpadding="10" cellspacing="0">
				<thead>
					<tr>
						<td>Type</td>
						<td>Id</td>
						<td>Key</td>
						<td>Language</td>
						<td>Index</td>
						<td>Value</td>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach($i18n1->search('') as $v)
					{
						?>
						<tr>
							<td><?=$v->type;?></td>
							<td><?=$v->id;?></td>
							<td><?=$v->key;?></td>
							<td><?=$v->language;?></td>
							<td><?=$v->index;?></td>
							<td><?=$v->value;?></td>
						</tr>
						<?php
					}
					?>
				</tbody>
			</table>
			<?php
			test('instance1');
			?>
		</td>
	</tr>
</table>
<style>
body {
	font-family: sans-serif;
	padding: 2em;
	background: #222;
	color: #ddd;
}
table.resume {
	background: #fece43;
	color: #333;
	font-family: sans-serif;
}
table.resume thead tr {
	background: #4a4a4a;
}
table.resume thead td {
	color: #fff;
	font-weight: bold;
}
table.resume tfoot tr {
	background: #4a4a4a;
}
table.resume tfoot td {
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
pre.success { background: #16A05C; }
pre.fail { background: #dc4f43; }
pre.expected { background: #fcc83e; }
</style>