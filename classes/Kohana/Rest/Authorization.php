<?php
	defined('SYSPATH') or die('No direct script access.');

	/**
	 * Trieda na vytvorenie autorizacneho tokenu podla HTTP specifikacie. Momentalne je
	 * podporovana iba HTTP BASIC autentifikacia. Podpora je DIGEST autentifikaciu je
	 * planovana do buducna
	 *
	 * @package Utils\Rest
	 * @author Lukas Homza <lhomza@cfh.sk>
	 * @version 1.0
	 */
	class Kohana_Rest_Authorization {
		/** @var string - typ autorizacie */
		const BASIC = 'basic';
		/** @var string - typ autorizacie */
		const DIGEST = 'digest';

		/** @var string - prihlasovacie meno */
		protected $username = null;
		/** @var string - prihlasovacie heslo */
		protected $password = null;
		/** @var string - autorizacny header */
		protected $header = null;

		/**
		 * Metoda na vytvorenie HTTP BASIC autorizacneho headeru
		 *
		 * @return void
		 *
		 * @since 1.0
		 */
		protected function basic() {
			$this->header = array(
				'Authorization' => 'Basic '.base64_encode($this->username.':'.$this->password
			));
		}

		/**
		 * Metoda na vytvorenie HTTP DIGEST autorizacneho headeru
		 *
		 * Note: momentalne nie implementovana pretoze este neexistuje servis, voci ktoremu
		 * by treba pouzit DIGEST autorizaciu
		 *
		 * @return void
		 *
		 * @since 1.0
		 */
		protected function digest() {
			throw new Rest_Exception('Digest authorization is not yet supported as it was not needed at this time.');
		}

		/**
		 * Konstruktor triedy. Nastavi potrebne premenne a urobi validaciu vstupnych dat.
		 * Nasledne zavola pozadovanu autorizacnu metodu
		 *
		 * @param array $config - config aktivnej konfiguracnej skupiny
		 *
		 * @return void
		 *
		 * @throws Rest_Exception
		 *
		 * @since 1.0
		 */
		public function __construct(array $config) {
			$this->username = Arr::get($config, 'username');
			$this->password = Arr::get($config, 'password');

			if(empty($this->username) || empty($this->password)) {
				throw new Rest_Exception('Username and/or password can not be empty.');
			}

			$method = Arr::get($config, 'method');
			if(empty($method) || !method_exists(__CLASS__, $method)) {
				throw new Rest_Exception('Authentification method ":method" does not exist.', array(
					':method' => $method
				));
			}

			$this->{$method}();
		}

		/**
		 * Metoda vrati autorizacny header
		 *
		 * @return array
		 *
		 * @since 1.0
		 */
		public function header() {
			return $this->header;
		}
	}