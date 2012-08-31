<?php

/**
 * Config parser
 *
 * @package Core
 * @subpackage Config
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon_ConfigParser {

	const T_ALL = 1;
	const T_COMMENT = 2;
	const T_VAR = 3;
	const T_STRING = 4;
	const T_BLOCK = 5;
	const T_CVALUE = 5;

	private $file;
	private $line = 1;
	private $col = 1;
	public $p = 0;
	public $state = array();
	private $result;
	public $errorneus = FALSE;

	/**
	 * Constructor
	 * @return void
	 */
	public function __construct($file, $config, $included = FALSE)
	{
		$cfg = $this;
		$cfg->file = $file;
		$cfg->result = $config;
		$cfg->revision = ++Daemon_Config::$lastRevision;
		$cfg->data = file_get_contents($file);
		
		if (substr($cfg->data,0,2) === '#!') 	{
			if (!is_executable($file)) {
				$this->raiseError('Shebang (#!) detected in the first line, but file hasn\'t +x mode.');
				return;
			}
			$cfg->data = shell_exec($file);
		}
		
		$cfg->data = str_replace("\r", '', $cfg->data);
		$cfg->len = strlen($cfg->data);
		$cfg->state[] = array(self::T_ALL, $cfg->result);
		$cfg->tokens = array(
			self::T_COMMENT => function($cfg, $c) {
				if ($c === "\n") {
					array_pop($cfg->state);
				}
			},
			self::T_STRING => function($cfg, $q) {
				$str = '';
				++$cfg->p;

				for (;$cfg->p < $cfg->len;++$cfg->p) {
					$c = $cfg->getCurrentChar();

					if ($c === $q) {
						++$cfg->p;
						break;
					}
					elseif ($c === '\\') {
						if ($cfg->getNextChar() === $q) {
							$str .= $q;
							++$cfg->p;
						} else {
							$str .= $c;
						}
					} else {
						$str .= $c;
					}
				}

				if ($cfg->p >= $cfg->len) {
					$cfg->raiseError('Unexpected End-Of-File.');
				}
				
				return $str;
			},
			self::T_ALL => function($cfg, $c) {
				if (ctype_space($c)) { }
				elseif ($c === '#') {
					$cfg->state[] = array(Daemon_ConfigParser::T_COMMENT);
				}
				elseif ($c === '}') {
					if (sizeof($cfg->state) > 1) {
						$cfg->purgeScope($cfg->getCurrentScope());
						array_pop($cfg->state);
					} else {
						$cfg->raiseError('Unexpected \'}\'');
					}
				}
				elseif (ctype_alnum($c)) {
					$elements = array('');
					$elTypes = array(NULL);
					$i = 0;
					$tokenType = 0;

					for (;$cfg->p < $cfg->len; ++$cfg->p) {
						$c = $cfg->getCurrentChar();

						if (ctype_space($c) || $c === '=') {
							if ($elTypes[$i] !== NULL)	{
								++$i;
								$elTypes[$i] = NULL;
							}
						}
						elseif (
							($c === '"') 
							|| ($c === '\'')
						) {
							if ($elTypes[$i] != NULL)	 {
								$cfg->raiseError('Unexpected T_STRING.');
							}

							$string = call_user_func($cfg->tokens[Daemon_ConfigParser::T_STRING], $cfg, $c);
							--$cfg->p;

							if ($elTypes[$i] === NULL)	 {
								$elements[$i] = $string;
								$elTypes[$i] = Daemon_ConfigParser::T_STRING;
							}
						}
						elseif ($c === '}') {
							$cfg->raiseError('Unexpected \'}\' instead of \';\' or \'{\'');
						}
						elseif ($c === ';') {
							$tokenType = Daemon_ConfigParser::T_VAR;
							break;
						}
						elseif ($c === '{') {
							$tokenType = Daemon_ConfigParser::T_BLOCK;
							break;
						} else {
							if ($elTypes[$i] === Daemon_ConfigParser::T_STRING)	 {
								$cfg->raiseError('Unexpected T_CVALUE.');
							} else {
								if (!isset($elements[$i])) {
									$elements[$i] = '';
								}

								$elements[$i] .= $c;
								$elTypes[$i] = Daemon_ConfigParser::T_CVALUE;
							}
						}
					}

					foreach ($elTypes as $k => $v) {
						if (Daemon_ConfigParser::T_CVALUE === $v) {
							if (ctype_digit($elements[$k])) {
								$elements[$k] = (int) $elements[$k];
							}
							elseif (is_numeric($elements[$k])) {
								$elements[$k] = (float) $elements[$k];
							} else {
								$l = strtolower($elements[$k]);

								if (($l === 'true') || ($l === 'on')) {
									$elements[$k] = true;
								}
								elseif (($l === 'false') || ($l === 'off')) {
									$elements[$k] = false;
								}
								elseif ($l === 'null') {
									$elements[$k] = null;
								}
							}
						}
					}

					if ($tokenType === 0) {
						$cfg->raiseError('Expected \';\' or \'{\''); 
					}
					elseif ($tokenType === Daemon_ConfigParser::T_VAR) {
						$name = str_replace('-', '', strtolower($elements[0]));
						$scope = $cfg->getCurrentScope();
						
						if ($name === 'include') {
							$path = $elements[1];
							if (substr($path,0,1) !== '/') {
								$path = 'conf/'.$path;
							}
							$files = glob($path);
							if ($files) {
								foreach ($files as $fn) {
									$parser = new Daemon_ConfigParser($fn, $scope, true);
								}
							}
						} elseif (substr(strtolower($elements[0]),0,4) === 'mod-') {
							$cfg->raiseError('Variable started with \'mod-\'. This style is deprecated. You should replace it with block.');
						} elseif (isset($scope->{$name})) {
							if ($scope->{$name}->source != 'cmdline')	{
								if (!isset($elements[1])) {
									$elements[1] = true;
									$elTypes[1] = Daemon_ConfigParser::T_CVALUE;
								}
								if (
									($elTypes[1] === Daemon_ConfigParser::T_CVALUE) 
									&& is_string($elements[1])
								) {
									$scope->{$name}->setHumanValue($elements[1]);
								} else {
									$scope->{$name}->setValue($elements[1]);
								}
								$scope->{$name}->source = 'config';
								$scope->{$name}->revision = $cfg->revision;
							}
						} elseif (sizeof($cfg->state) > 1) {
							$scope->{$name} = new Daemon_ConfigEntry();
							$scope->{$name}->source = 'config';
							$scope->{$name}->revision = $cfg->revision;
							if (!isset($elements[1])) {
							 $elements[1] = true;
							 $elTypes[1] = Daemon_ConfigParser::T_CVALUE;
							}
							$scope->{$name}->setValue($elements[1]);
							$scope->{$name}->setValueType($elTypes[1]);
						}
						else {$cfg->raiseError('Unrecognized parameter \''.$name.'\'');}
					}
					elseif ($tokenType === Daemon_ConfigParser::T_BLOCK) {
						$scope = $cfg->getCurrentScope();
						$sectionName = implode('-', $elements);
						$sectionName = strtr($sectionName, '-. ', ':::');
						if (!isset($scope->{$sectionName})) {
							$scope->{$sectionName} = new Daemon_ConfigSection;
						}
						$scope->{$sectionName}->source = 'config';
						$scope->{$sectionName}->revision = $cfg->revision;
						$cfg->state[] = array(
							Daemon_ConfigParser::T_ALL,
							$scope->{$sectionName},
						);
					}
				} else {
					$cfg->raiseError('Unexpected char \''.Debug::exportBytes($c).'\'');
				}
			}
		);

		for (;$cfg->p < $cfg->len; ++$cfg->p) {
			$c = $cfg->getCurrentChar();
			$e = end($this->state);
			$cfg->token($e[0], $c);
		}
		if (!$included) {$this->purgeScope($this->result);}
	}
	
	/**
	 * Removes old config parts after updating.
	 * @return void
	 */
	public function purgeScope($scope) {
		$cfg = $this;
		foreach ($scope as $name => $obj) {
			if ($obj instanceof Daemon_ConfigEntry) {
					if ($obj->source === 'config' && ($obj->revision < $cfg->revision))	{
						if (!$obj->resetToDefault()) {
							unset($scope->{$name});
						}
					}
			}
			elseif ($obj instanceof Daemon_ConfigSection) {
				
				if ($obj->source === 'config' && ($obj->revision < $cfg->revision))	{
					if (sizeof($obj) === 0) {
						unset($scope->{$name});
					}
					elseif (isset($obj->enable)) {
						$obj->enable->setValue(FALSE);
					}
				}
			}
		}			
	}
	
	/**
	 * Returns current variable scope
	 * @return object Scope.
	 */
	public function getCurrentScope() {
		$e = end($this->state);

		return $e[1];
	}

	/**
	 * Raises error message.
	 * @param string Message.
	 * @param string Level.
	 * @return void
	 */
	public function raiseError($msg, $level = 'emerg') {
		if ($level === 'emerg') {
			$this->errorneus = true;
		}

		Daemon::log('[conf#' . $level . '][' . $this->file . ' L:' . $this->line . ' C: ' . ($this->col-1) . ']   '.$msg);
	}

	/**
	 * executes token-parse callback.
	 * @return void
	 */
	public function token($token, $c) {
		call_user_func($this->tokens[$token], $this, $c);
	}
	
	/**
	 * Current character.
	 * @return string Character.
	 */
	public function getCurrentChar() {
		$c = substr($this->data, $this->p, 1);

		if ($c === "\n") {
			++$this->line;
			$this->col = 1;
		} else {
			++$this->col;
		}

		return $c;
	}

	/**
	 * Returns next character.
	 * @return string Character.
	 */
	public function getNextChar() {
		return substr($this->data, $this->p + 1, 1);
	}

	/**
	 * Rewinds the pointer back.
	 * @param integer Number of characters to rewind back.
	 * @return void
	 */
	public function rewind($n) {
		$this->p -= $n;
	}

}
