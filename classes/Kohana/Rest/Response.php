<?php
	defined('SYSPATH') or die('No direct script access.');

	/**
	 * Trieda reprezentujuca odpoved z volaneho servisu. V ramci triedy mate pristup k rovnakym
	 * datam ako pri Response objekte od Kohany. A to:
	 *  - HTTP status kod
	 *  - headers
	 *  - body
	 *
	 * Trieda nikdy nezistuje aky content-type header sa vratil ale povazuje accept header, ktory
	 * bol poslany do servisu ako prioritny v ramci content negotiation. Jedine parsovanie, ktore
	 * v ramci triedy prebieha je uprava odpovede do pola alebo objektu
	 *
	 * @package Utils\Rest
	 * @author Lukas Homza <lhomza@cfh.sk>
	 * @version 1.0
	 */
	class Kohana_Rest_Response {
		/** @var int - HTTP status kod */
		protected $status = null;
		/** @var string - response body zo servisu */
		protected $body = null;
		/** @var array - zoznam response headerov */
		protected $headers = null;
		/** @var string - ocakavany typ dat, uklada sa kvoli parsovaniu odpovede */
		protected $acceptType = null;

		/**
		 * Metoda na vyparsovanie tela odpovede do pola, objektu. V pripade, ze bol
		 * pouzity na navrat TEXT/PLAIN header, vrati sa raw odpoved
		 *
		 * @param bool $array - ma byt vystup pole alebo nie
		 *
		 * @return mixed
		 *
		 * @since 1.0
		 */
		protected function parse($array = false) {
			$body = $this->body();

			switch($this->acceptType) {
				case Rest::AS_XML:
					$body = json_encode((array) simplexml_load_string($body));
				break;
			}

			if($this->acceptType !== Rest::AS_PLAIN) {
				$body = json_decode($body, $array);
			}

			return $body;
		}

		/**
		 * Konstruktor metody, nastavi vsetky premenne pre dalsie pouzitie. Posledna
		 * hodnota sa pouziva na zistenie typu ocakavanych dat, podla ktorych sa vyberie
		 * metoda na parsovanie tela odpovede ci uz do pola alebo objektu
		 *
		 * @param int $status - HTTP status kod odpovede
		 * @param array $headers - zoznam headerov v odpovedi
		 * @param string $body - telo odpovede
		 * @param string $acceptType - accept header
		 *
		 * @return void
		 *
		 * @since 1.0
		 */
		public function __construct($status, $headers, $body, $acceptType = Rest::AS_JSON) {
			$this->status = $status;
			$this->headers = $headers;
			$this->body = $body;
			$this->acceptType = $acceptType;
		}

		/**
		 * Metoda vrati HTTP status kod z odpovede servisu
		 *
		 * @return int
		 *
		 * @since 1.0
		 */
		public function status() {
			return (int) $this->status;
		}

		/**
		 * Ak je vstup do metody null tak vrati zoznam vsetkych headerov z response-u zo
		 * servisu alebo ak konkretnu hodnotu headeru ak je definovany pri volani metody
		 *
		 * @param type $header
		 *
		 * @return mixed
		 *
		 * @since 1.0
		 */
		public function headers($header = null) {
			if(!is_null($header)) {
				return Arr::get($this->headers, strtolower($header));
			}

			return $this->headers;
		}

		/**
		 * Metoda na pristup k raw odpovedi zo servisu
		 *
		 * @return string
		 *
		 * @since 1.0
		 */
		public function body() {
			return $this->body;
		}

		/**
		 * Wrapper metoda na vyparsovanie odpovede zo servisu do pola
		 *
		 * @return array
		 *
		 * @since 1.0
		 */
		public function as_array() {
			return $this->parse(true);
		}

		/**
		 * Wrapper metoda na vyparsovanie odpovede zo servisu do objektu
		 *
		 * @return array
		 *
		 * @since 1.0
		 */
		public function as_object() {
			return $this->parse();
		}
	}