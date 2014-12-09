<?php
	defined('SYSPATH') or die('No direct script access.');

	return array(
		/**
		 * - group_name = nazov konfiguracnej skupiny
		 *		- service = URL adresa pre servis alebo jej cast
		 *			- authorization
		 *				- method = typ autorizacnej metody
		 *				- username = prihlasovacie meno
		 *				- password = prihlasovacie heslo
		 */
		'group_name' => array(
			'service' => '',
			'authorization' => array(
				'method' => Rest_Authorization::BASIC,
				'username' => '',
				'password' => ''
			)
		)
	);