<?php

require_once __DIR__ . '/../vendor/autoload.php';
$app = new Silex\Application();

require_once __DIR__ . '/../db/index.php';

use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;

use Silex\Provider\FormServiceProvider;

$app['debug'] = true;

$app->register(new Silex\Provider\TwigServiceProvider(), array(
	'twig.path' => __DIR__.'/views',
));

$app->register(new Silex\Provider\TranslationServiceProvider(), array(
    'translator.messages' => array(),
));

$app['twig'] = $app->share($app->extend('twig', function($twig, $app) {
    $twig->addFunction(new \Twig_SimpleFunction('asset', function ($asset) use ($app) {
        return sprintf('%s/%s', trim($app['request']->getBasePath()), ltrim($asset, '/'));
    }));
    return $twig;
}));


$app->match('/kineo/vote', function(Request $request) use ($app) {

	$posted = false;

	$app->register(new FormServiceProvider());
	$conn = $app['db'];
	$queryBuilder = $conn->createQueryBuilder();

	$default = [
		'vote' => '',
		'email' => '',
		'message' => '',
	];

	$queryBuilder->select('*')
	  ->from('parties', 'p');

	$result = $conn->fetchAll($queryBuilder);

	$parties = [];

	foreach ($result as $party) {
		$parties[$party['id']] = $party['name'];
	}

	$queryBuilder->select('*')
	  ->from('constituencies', 'p');

	$result = $conn->fetchAll($queryBuilder);

	$constituencies = [];

	foreach ($result as $constituency) {
		$constituencies[$constituency['id']] = $constituency['name'];
	}

	$form = $app['form.factory']->createBuilder('form', $default)
		->add('vote', 'choice', [
			'label' => 'Are you going to vote?',
			'choices' => [
					1 => 'Yes',
					0 => 'No'
				]
		])
		->add('party', 'choice', [
			'label' => 'Who are you going to vote for?',
			'choices' => $parties
		])
		->add('constituency', 'choice', [
			'label' => 'Which constituency are you in?',
			'choices' => $constituencies
		])
		->add('send', 'submit', array(
			'attr' => array('class' => 'btn btn-default')
		))
		->getForm();

	$form->handleRequest($request);

	if($form->isValid()) {
		$data = $form->getData();

		$sql = 'INSERT INTO votes
			(`vote`, `constituency`, `party`)
			VALUES
			("' . (int)$data['vote'] . '", "' . (int)$data['constituency'] . '", "' . (int)$data['party'] . '")';

		$res = $app['db']->executeQuery($sql);

		$posted = true;
	}


	return $app['twig']->render('/form.twig', array('form' => $form->createView(), 'posted' => $posted));

})->bind('vote');


$app->match('/kineo/results', function(Request $request) use ($app) {

	$conn = $app['db'];
	$queryBuilderParties = $conn->createQueryBuilder();

	$queryBuilderParties
		->select('p.name, COUNT(v.party) as total')
		->from('parties', 'p')
		->leftJoin('p', 'votes', 'v', 'p.id = v.party')
		->where('v.vote = 1')
		->groupBy('v.party');

	$party_list = $conn->fetchAll($queryBuilderParties);

	$totals = ['name' => 'Total', 'data' => []];
	foreach ( $party_list as $party ) $totals['data'][] = (int)$party['total'];

	$queryBuilder = $conn->createQueryBuilder();

	$queryBuilder
		->select('p.name as party, c.name as constituency, count(p.id) as votes, p.colour as colour')
		->from('votes', 'v')
		->leftJoin('v', 'constituencies', 'c', 'c.id = v.constituency')
		->leftJoin('v', 'parties', 'p', 'p.id = v.party')
		->where('v.vote = 1')
		->groupBy('v.constituency, v.party')
		->orderBy('v.party')
		;

	$result = $conn->fetchAll($queryBuilder);
	$votes = [];

	foreach( $result as $key => $value ) {
		if ( !isset( $votes[$value['constituency']] ) ) $votes[$value['constituency']] = [];

		foreach( $party_list as $id => $party ) {
			$parties[$value['party']] = $value['party'];
			if( $value['party'] == $party['name'] ) {
				$votes[$value['constituency']][$party['name']] = (int)$value['votes'];
			} else {
				if ( !isset($votes[$value['constituency']][$party['name']]) ) $votes[$value['constituency']][$party['name']] = 0;
			}
		}
	}

	$series = [];

	foreach ( $votes as $name => $data ) {
		$d = []; $total = 0;
		foreach ( $data as $party => $vote ) $d[] = $vote;
		$series[] = ['name' => $name, 'data' => $d];
	}
	array_push($series, $totals);
	// Must json_encode series so keys are not strings
	$results = ['parties' => $parties, 'votes' => json_encode($series)];

  return $app->json($results, 201);

})->bind('results');

$app->run();
