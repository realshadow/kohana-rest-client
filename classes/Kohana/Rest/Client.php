<?php
	defined('SYSPATH') or die('No direct script access.');

	/**
	 * Trieda vykonavanie requestov na RESTfull servisy. Podporovane su momentalne GET, POST, PUT a DELETE requesty
	 *
	 * Autorizacia do servisov prebieha v ramci Rest_Authorization triedy. Nastavit autorizaciu pre servis
	 * mozete v configu pre danu konfiguracnu skupinu. Nastavit je mozne username a password pre HTTP BASIC
	 * autorizaciu, viac v dokumentacii pre konfig a Rest_Authorization triedu
	 *
	 * Pri volani metod, ktore si vyzaduju body odpovede je nutne posielat uz upravene data vo formate content type-u,
	 * ktory pouzivate data. Rovnako funguje aj spracovanie odpovede. Trieda nezistuje ake data sa vratili ale pokusi
	 * sa ich vyparsovat podla accept header-u, ktory ste vy poslali do servisu a ktory mal servis vratit lebo tak
	 * funguje content negotiation
	 *
	 * Priklad vytvorenia GET requestu:
	 *		$response = Rest::instance($config_group)->get($url);
	 *
	 * Priklad vytvorenia POST requestu s content negotiation kde chcem naspat dostat XML
	 *		$response = Rest::instance($config_group)->negotiate(Rest::AS_XML)->post($url, json_encode($data));
	 *
	 * Priklad poslania dat suborov do Storage servisu a pouzitie override headeru:
	 *		$response = Rest::instance($config_group)->headers(Rest::HTTP_HEADER_OVERRIDE, Rest::HTTP_PUT)->files($uri, $data, 'files');
	 *
	 * Priklad nastavenia viacerych headerov naraz:
	 *		$headers = array(
	 *			'header' => $header
	 *			'header2' => $header2
	 *		);
	 *		$response = Rest::instance($config_group)->headers($headers)->delete($uri, $data);
	 *
	 * Vdaka tomu, ze trieda vrati Rest_Response object, ktory je kompatibilny s Response objektom od Kohany
	 * je mozne vyparsovat odpoved zo servisu dvoma sposobmi a to:
	 *  - priamy pristup k datam a vyparsovanie odpovede do pola, objektu alebo raw odpoved
	 *  - spracovanie HTTP status kodu odpovede pri void metodach (201 pre created a 202 pre accepted)
	 *
	 * Priklad pristupu na odpoved priamo
	 *		$data = $response-body();
	 *
	 * Priklad ako dostat vyparsovane data do pola alebo objektu
	 *		$data = $response->as_object();
	 *    // alebo
	 *		$data = $response->as_array();
	 *
	 * Priklad ako spracovat kod odpovede void metody
	 *		$response = Rest::instance($config_group)->put($url, json_encode($data));
	 *		if($response->status() === HTTP_CREATED) {
	 *			// resource bol uspesne vytvoreny
	 *    }
	 *
	 * @package Utils\Rest
	 * @author Lukas Homza <lhomza@cfh.sk>
	 * @version 1.0
	 */
	class Kohana_Rest_Client {
		/** @var string - HTTP GET method */
		const HTTP_GET = 'GET';
		/** @var string - HTTP PUT method */
		const HTTP_PUT = 'PUT';
		/** @var string - HTTP POST method */
		const HTTP_POST = 'POST';
		/** @var string - HTTP DELETE method */
		const HTTP_DELETE = 'DELETE';

		/** @var int - HTTP status po uspesnom vykonani requestu */
		const HTTP_OK = 200;
		/** @var int - HTTP status po uspesnom vytvoreni resource */
		const HTTP_CREATED = 201;
		/** @var int - HTTP status po akceptovani resource-u, ktorý bude asynchrónne spracovaný */
		const HTTP_ACCEPTED = 202;
		/** @var int - HTTP status po uspesnom zmeneni resource-u (v skutocnosti je to NO CONTENT) */
		const HTTP_UPDATED = 204;
		/** @var int - HTTP status pre zly request */
		const HTTP_BAD_REQUEST= 400;
		/** @var int - HTTP status pre neuspesnu autorizaciu */
		const HTTP_UNAUTHORIZED = 401;
		/** @var int - HTTP status pre nepovoleny vstup */
		const HTTP_FORBIDDEN = 403;
		/** @var int - HTTP status pre neexistujuci resource */
		const HTTP_NOT_FOUND = 404;
		/** @var int - HTTP status pre chybu na strane servera pri spracovani requestu */
		const HTTP_INTERNAL_SERVER_ERROR = 500;

		/** @var string - shortcut pre X-HTTP-Method-Override header */
		const HTTP_HEADER_OVERRIDE = 'X-HTTP-Method-Override';

		/** @var int - shortcut pre debugger, @deprecated */
		const AS_ARRAY = 1;

		/** @var string - JSON header */
		const AS_JSON = 'application/json';
		/** @var string - XML header */
		const AS_XML = 'application/xml';
		/** @var string - PLAIN/HTML header */
		const AS_PLAIN = 'text/html';

		/** @var array - zoznam aktivnych instancii Rest triedy */
		protected static $instances = array();

		/** @var array - config */
		protected $config = array();
		/** @var array - zoznam headerov */
		protected $headers = array();
		/** @var array - drzi accept a content-type header */
		protected $content = array();
		/** @var array - interna cache, napr. pre GET requesty */
		protected $cache = array();

		/**
		 * Staticky constructor pre triedu, ako vstup zoberie nazov skupiny z configu pre Rest. V pripade, ze
		 * nie je zadefinovana skupina bude hladat default skupinu. Nasledne vytvori novu instanciu
		 * triedy pre danu konfiguracnu skupinu a zresetuje headers a content-type headers pre pripad
		 * rozlicnych volani v ramci jednej instancie konfiguracnej skupiny
		 *
		 * Defaultny accept a content-type header su nastavene na JSON
		 *
		 * @param string $group - nazov skupiny z configu
		 *
		 * @return \Rest_Client
		 *
		 * @since 1.0
		 */
		public static function instance($group = 'default') {
			if(!isset(self::$instances[$group])) {
				$config = Kohana::$config->load('rest.'.$group);

				self::$instances[$group] = new self($config);
			}

			# -- safety reset
			self::$instances[$group]->headers = array();
			self::$instances[$group]->content = array(
				'Accept' => self::AS_JSON,
				'Content-Type' => self::AS_JSON
			);

			return self::$instances[$group];
		}

		/**
		 * Realny constructor. Nastavi config pre dalsie pouzitie
		 *
		 * @param array $config - config pre danu konfiguracnu skupinu
		 *
		 * @return void
		 */
		protected function __construct($config) {
			if(empty($config)) {
				$config = array();
			}

			$this->config = $config;
		}

		/**
		 * Metoda na vykonanie HTTP request pre danu URL adresu
		 *
		 * Vykladat URL adresu mozete dvoma sposobmi:
		 *  - v kombinacii s configom a vstupom do danej metody
		 *  - komplet cela URL adresa vstupi do metody
		 *
		 * Autorizacia prebieha v Rest_Authorization triede
		 *
		 * @param string $method - typ HTTP metody, ktora sa ma vykonat
		 * @param string $uri - URL adresa, pre ktoru sa ma request vykonat
		 * @param string $data - body requestu
		 *
		 * @return \Rest_Response
		 *
		 * @throws Rest_Exception
		 *
		 * @since 1.0
		 */
		protected function execute($method, $uri, $data = '') {
			$base = Arr::get($this->config, 'service', '');

			# -- vyskladaj URL adresu
			$uri = (!empty($base) ? rtrim($base, '/').'/' : '').$uri;

			# -- pozri ci uz nahodou nemam nacacheovany nejaky HTTP GET request
			$cachedResponse = Arr::get($this->cache, $uri);
			if($method === self::HTTP_GET && !empty($cachedResponse)) {
				return $cachedResponse;
			}

			# -- autorizacia
			$authConfig = Arr::get($this->config, 'authorization', null);
			if(!is_null($authConfig)) {
				$auth = new Rest_Authorization($authConfig);

				$this->headers($auth->header());
			}

			# -- pripava request body ak nejde o HTTP GET
			if($method !== self::HTTP_GET) {
				if(is_null($data)) {
					$data = '';
				}

				# -- data musia prist ako string, inak sa neda spocitat Content-Length
				if(!is_string($data)) {
					throw new Rest_Exception('Data must be passed as string');
				}

				$this->headers('Content-Length', strlen($data));
			}

			# -- nastav headers
			$this->headers($this->content);

			if(Kohana::$profiling === true) {
				$calledOn = microtime();

				$benchmark = Profiler::start(__CLASS__, $method.'|'.$calledOn);
			}

			$request = Request::factory($uri)->method($method)->headers($this->headers);
			if($method !== self::HTTP_GET) {
				$request->body($data);
			}

			$out = $request->execute();

			# -- vyskladaj data pre Rest_Response
			$response = new Rest_Response(
				$out->status(),
				$out->headers(),
				$out->body(),
				Arr::get($this->headers, 'Accept')
			);

			if(isset($benchmark)) {
				if(Kohana::$config->load('debugger.enabled')) {
					list(, $wrapper, $calledBy) = debug_backtrace(false);

					$calledBy['file'] = Arr::get($wrapper, 'file');

					$temp = array(
						$method.'|'.$calledOn => array(
							'service' => $uri,
							'request' => $request,
							'response' => $response,
							'display_as' => 1, # -- as_array
							'called_by' => $calledBy
						)
					);

					Debugger::append(Debugger::PANEL_REST, $temp);
				}

				Profiler::stop($benchmark);
			}

			$this->cache[$uri] = $response;

			return $response;
		}

		/**
		 * Metoda prida do zoznamu headerov novy header. V pripade, ze poslem ako prvy paramameter
		 * pole s headermi v tvare array('header' => 'value') tak sa zmerguju s uz existujucimi
		 * headermi
		 *
		 * @param mixed $header - nazov headeru alebo pole s headermi
		 * @param mixed $value - hodnata, ktora sa ma nastavit pre dany header
		 *
		 * @return \Rest_Client
		 *
		 * @since 1.0
		 */
		public function headers($header, $value = null) {
			if(is_array($header)) {
				$this->headers = array_merge($this->headers, $header);
			} else {
				$this->headers[$header] = $value;
			}

			return $this;
		}

		/**
		 * Metoda sluzi na content negotiation, t.j. upravu Accept a Content-Type headeru v pripade,
		 * ze sa nebavime iba cisto s JSON datami. Druhy parameter nie je povinny, defaultne sa
		 * vzdy nastavi ako JSON
		 *
		 * @param string $acceptType - aky content-type ocakavam naspat
		 * @param string $contentType - aky content-type posielam
		 *
		 * @return \Rest_Client
		 *
		 * @throws Rest_Exception
		 *
		 * @since 1.0
		 */
		public function negotiate($acceptType, $contentType = self::AS_JSON) {
			if(empty($acceptType)) {
				throw new Rest_Exception('Accept type header can not be empty.');
			}

			$this->content['Accept'] = $acceptType;
			$this->content['Content-Type'] = $contentType;

			return $this;
		}

		/**
		 * Wrapper metoda na vykonanie HTTP GET requestu
		 *
		 * @param string $uri - URL adresa, pre ktoru sa ma vykonat request
		 *
		 * @return \Rest_Response
		 *
		 * @since 1.0
		 */
		public function get($uri) {
			return $this->execute(self::HTTP_GET, $uri);
		}

		/**
		 * Wrapper metoda na vykonanie HTTP POST requestu
		 *
		 * @param string $uri - URL adresa, pre ktoru sa ma vykonat request
		 * @param string $data - body requestu
		 *
		 * @return \Rest_Response
		 *
		 * @since 1.0
		 */
		public function post($uri, $data = null) {
			return $this->execute(self::HTTP_POST, $uri, $data);
		}

		/**
		 * Wrapper metoda na vykonanie HTTP PUT requestu
		 *
		 * @param string $uri - URL adresa, pre ktoru sa ma vykonat request
		 * @param string $data - body requestu
		 *
		 * @return \Rest_Response
		 *
		 * @since 1.0
		 */
		public function put($uri, $data = null) {
			return $this->execute(self::HTTP_PUT, $uri, $data);
		}

		/**
		 * Wrapper metoda na vykonanie HTTP DELETE requestu
		 *
		 * @param string $uri - URL adresa, pre ktoru sa ma vykonat request
		 * @param string $data - body requestu
		 *
		 * @return \Rest_Response
		 *
		 * @since 1.0
		 */
		public function delete($uri, $data = null) {
			return $this->execute(self::HTTP_DELETE, $uri, $data);
		}

		/**
		 * Metoda sluzi na posielanie suborov do servisu. Kohana posielanie suborov nepodporuje
		 * preto je napisany cisty CURL request (v konecnom dosledku by Kohana urobila to iste).
		 *
		 * Na zaciatku sa vzdy vykonava validacia vstupnych dat, ktore pridu z $_FILES. Upload
		 * musi byt validny, ak nie je tato podmienka splnena metoda vrati Validation_Exception.
		 * Je mozne specifikovat aj $lookup ak by som chcel bavit s konkretnou castou dat z $_FILES.
		 * Referencie na subory sa posielaju vdaka magickemu "@". Viac info najdete v dokumentacii
		 * ku Storage servisu.
		 *
		 * Autorizacia prebieha v Rest_Authorization triede
		 *
		 * Metoda vrati vyparsovanu CURL odpoved ako Rest_Response object
		 *
		 * @param string $uri - URL adresa, pre ktoru sa ma vykonat request
		 * @param string $data - body requestu
		 * @param string $lookup - lookup na konkretnu cast vo $_FILES
		 *
		 * @return \Rest_Response
		 *
		 * @throws Rest_Exception
		 * @throws Validation_Exception
		 *
		 * @since 1.0
		 */
		public function files($uri, $data, $lookup) {
			if(empty($_FILES) || (!empty($lookup) && empty($_FILES[$lookup]))) {
				throw new Rest_Exception('No files specified.');
			}

			if(empty($lookup)) {
				$files =& $_FILES;
			} else {
				$files =& $_FILES[$lookup];
			}

			$validation = Validation::factory($files);
			$validation->rule('file', 'Upload::valid');

			# -- validacia
			if($validation->check() === false) {
				throw new Validation_Exception('Upload did not pass validation.');
			}

			# -- referencie na subory
			$message = null;
			for($i = 0; $i < count($files['name']); $i++) {
				$data['files['.$i.']'] = '@'.$files['tmp_name'][$i].';filename='.$files['name'][$i].';type='.$files['type'][$i];
			}

			# -- autorizacia
			$authConfig = Arr::get($this->config, 'authorization', null);
			if(!is_null($authConfig)) {
				$auth = new Rest_Authorization($authConfig);

				$this->headers($auth->header());
			}

			$base = Arr::get($this->config, 'service', '');

			# -- vyskladaj URL adresu
			$uri = (!empty($base) ? rtrim($base, '/').'/' : '').$uri;

			# -- priprav response object kvoli parsovaniu response headerov
			$responseHeaders = Response::factory()->headers();

			# -- prisposob headers pre CURL
			$requestHeaders = array();
			foreach($this->headers as $header => $value) {
				$requestHeaders[] = $header.': '.$value;
			}

			$curl = curl_init();
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_VERBOSE, false);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_URL, $uri);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $requestHeaders);
			curl_setopt($curl, CURLOPT_HEADERFUNCTION, array($responseHeaders, 'parse_header_string'));

			if(Kohana::$profiling === true) {
				$calledOn = microtime();

				$benchmark = Profiler::start(__CLASS__, __FUNCTION__.'|'.$calledOn);
			}

			$out = curl_exec($curl);

			# -- vyskladaj data, ktore vstupuju do Rest_Response
			$response = new Rest_Response(
				curl_getinfo($curl, CURLINFO_HTTP_CODE),
				$responseHeaders,
				$out,
				Arr::get($this->headers, 'Accept')
			);

			if(isset($benchmark)) {
				if(Kohana::$config->load('debugger.enabled')) {
					list(, $calledBy) = debug_backtrace(false);

					$request = Request::factory()->headers($this->headers)->body($data);

					$temp = array(
						__FUNCTION__.'|'.$calledOn => array(
							'service' => $uri,
							'request' => $request,
							'response' => $response,
							'display_as' => 1, # -- as_array
							'called_by' => $calledBy
						)
					);

					Debugger::append(Debugger::PANEL_REST, $temp);
				}

				Profiler::stop($benchmark);
			}

			curl_close($curl);

			return $response;
		}
	}