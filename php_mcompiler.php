<?php

class PHP_MCompiler {

	private $_tokens;
	private $_vars;
	private $_skipping = false;

	function __construct($source) {
		$this->_tokens = (array) token_get_all($source);
	}

	function set($name, $value=NULL) {
		if ($value === NULL) {
			unset($this->_vars[$name]);
		}
		else {
			$this->_vars[$name] = $value;
		}
	}

	function parse_macro($macro) {
		if (preg_match('/^#(\w+)\s*(.*)\s*$/', $macro, $matches)) {
			$command = 'command_'.$matches[1];
			$params = $matches[2];
			if (method_exists($this, $command)) {
				$this->$command($params);
				return '';
			}
		}
		return $macro;
	}

	function compile() {

		$output = '';

		foreach ($this->_tokens as $token) {

			if (is_array($token)) {
				switch ($token[0]) {
				case T_DOC_COMMENT:
				case T_COMMENT:
					$comments = trim($token[1]);
					if ($comments[0] == '#') {
						$output .= $this->parse_macro($token[1]);
					}
					else {
						$output .= $token[1];
					}
					break;
				default:
					if (!$this->_skipping) {
						$output .= $token[1];
					}
				}
			}
			elseif (!$this->_skipping) {
				$output .= $token;
			}

		}

		return $output;
	}

	private $_status = array();

	function push_status($name) {
		$this->_status[$name][] = array(
			'skipping' => $this->_skipping
		);
	}

	function pop_status($name) {
		if (count($this->_status[$name]) > 0) {
			$status = array_pop($this->_status[$name]);
			$this->_skipping = $status['skipping'];
		}
	}
	
	private function command_ifdef($params) {

		$params = preg_replace('/^\((.+)\)$/', '$1', $params);

		if (isset($this->_vars[$params])) {
			$this->push_status('if');
			$this->_skipping = true;
		}

	}

	private function command_ifndef($params) {

		$params = preg_replace('/^\((.+)\)$/', '$1', $params);

		if (!isset($this->_vars[$params])) {
			$this->push_status('if');
			$this->_skipping = true;
		}

	}

	private function command_endif($params) {
		$this->pop_status('if');
	}

}



