<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}
//TODO Review manifest
$manifest = array();

$manifest['name']         = __( 'Learning Grading', 'fw' );
$manifest['description']  = __( 'Another awesome framework extension', 'fw' );
$manifest['version']      = '1.0';
$manifest['author']       = 'ThemeFuse';
$manifest['author_uri']   = 'http://themefuse.com/';
$manifest['requirements'] = array(
	'wordpress'  => array(
		'min_version' => '4.0',
	),
	'framework'  => array(),
	'extensions' => array(
		'learning-student' => array(),
		'learning-quiz'    => array(),
	)
);

$manifest['display'] = true;