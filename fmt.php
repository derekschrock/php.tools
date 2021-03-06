#!/usr/bin/env php
<?php
//Copyright (c) 2014, Carlos C
//All rights reserved.
//
//Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:
//
//1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
//
//2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
//
//3. Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
//
//THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
$concurrent = function_exists('pcntl_fork');
if ($concurrent) {
	// The MIT License (MIT)
//
// Copyright (c) 2014 Carlos Cirello
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in all
// copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
// SOFTWARE.

define('PHP_INT_LENGTH', strlen(sprintf("%u", PHP_INT_MAX)));
function cofunc(callable$fn) {
	$pid = pcntl_fork();
	if (-1 == $pid) {
		trigger_error('could not fork', E_ERROR);
	} elseif ($pid) {
		// I am the parent
	} else {
		$params = [];
		if (func_num_args() > 1) {
			$params = array_slice(func_get_args(), 1);
		}
		call_user_func_array($fn, $params);
		die();
	}
}

class CSP_Channel {
	private $ipc;
	private $ipc_fn;
	private $key;
	private $closed = false;
	private $msg_count = 0;
	public function __construct() {
		$this->ipc_fn = tempnam(sys_get_temp_dir(), 'csp.' . uniqid('chn', true));
		$this->key = ftok($this->ipc_fn, 'A');
		$this->ipc = msg_get_queue($this->key, 0666);
		msg_set_queue($this->ipc, $cfg = [
			'msg_qbytes' => (1 * PHP_INT_LENGTH),
		]);

	}
	public function close() {
		$this->closed = true;
		do {
			$this->out();
			--$this->msg_count;
		} while ($this->msg_count >= 0);
		msg_remove_queue($this->ipc);
		file_exists($this->ipc_fn) && @unlink($this->ipc_fn);
	}
	public function in($msg) {
		if ($this->closed || !msg_queue_exists($this->key)) {
			return;
		}
		++$this->msg_count;
		$shm = new Message();
		$shm->store($msg);
		@msg_send($this->ipc, 1, $shm->key(), false);
	}
	public function out() {
		if ($this->closed || !msg_queue_exists($this->key)) {
			return;
		}
		$msgtype = null;
		$ipcmsg = null;
		$error = null;
		msg_receive($this->ipc, 1, $msgtype, (1 * PHP_INT_LENGTH) + 1, $ipcmsg, false, $error);
		--$this->msg_count;
		$shm = new Message($ipcmsg);
		$ret = $shm->fetch();
		return $ret;
	}
}
class Message {
	private $key;
	private $shm;
	public function __construct($key = null) {
		if (null === $key) {
			$key = ftok(tempnam(sys_get_temp_dir(), 'csp.' . uniqid('shm', true)), 'C');
		}
		$this->shm = shm_attach($key);
		if (false === $this->shm) {
			trigger_error('Unable to attach shared memory segment for channel', E_ERROR);
		}
		$this->key = $key;
	}
	public function store($msg) {
		shm_put_var($this->shm, 1, $msg);
		shm_detach($this->shm);
	}
	public function key() {
		return sprintf('%0' . PHP_INT_LENGTH . 'd', (int) $this->key);
	}
	public function fetch() {
		$ret = shm_get_var($this->shm, 1);
		$this->destroy();
		return $ret;

	}
	public function destroy() {
		if (shm_has_var($this->shm, 1)) {
			shm_remove_var($this->shm, 1);
		}
		shm_remove($this->shm);
	}
}

function make_channel() {
	return new CSP_Channel();
}
;
}
$enable_cache = false;
if (class_exists('SQLite3')) {
	$enable_cache = true;
	/**
 * @codeCoverageIgnore
 */
class Cache {
	const DEFAULT_CACHE_FILENAME = '.php.tools.cache';

	private $db;

	public function __construct($filename) {
		$start_db_creation = false;
		if (is_dir($filename)) {
			$filename = realpath($filename) . DIRECTORY_SEPARATOR . self::DEFAULT_CACHE_FILENAME;
		}
		if (!file_exists($filename)) {
			$start_db_creation = true;
		}

		$this->set_db(new SQLite3($filename));
		$this->db->busyTimeout(1000);
		if ($start_db_creation) {
			$this->create_db();
		}
	}

	public function __destruct() {
		$this->db->close();
	}

	public function create_db() {
		$this->db->exec('CREATE TABLE cache (target TEXT, filename TEXT, hash TEXT, unique(target, filename));');
	}

	public function upsert($target, $filename, $content) {
		$hash = $this->calculate_hash($content);
		$this->db->exec('REPLACE INTO cache VALUES ("' . SQLite3::escapeString($target) . '","' . SQLite3::escapeString($filename) . '", "' . SQLite3::escapeString($hash) . '")');
	}

	public function is_changed($target, $filename) {
		$row = $this->db->querySingle('SELECT hash FROM cache WHERE target = "' . SQLite3::escapeString($target) . '" AND filename = "' . SQLite3::escapeString($filename) . '"', true);
		$content = file_get_contents($filename);
		if (empty($row)) {
			return $content;
		}
		if ($this->calculate_hash($content) != $row['hash']) {
			return $content;
		}
		return false;
	}

	private function set_db($db) {
		$this->db = $db;
	}

	private function calculate_hash($content) {
		return sprintf('%u', crc32($content));
	}
}
;
}
//Copyright (c) 2014, Carlos C
//All rights reserved.
//
//Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:
//
//1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
//
//2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
//
//3. Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
//
//THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
define("ST_AT", "@");
define("ST_BRACKET_CLOSE", "]");
define("ST_BRACKET_OPEN", "[");
define("ST_COLON", ":");
define("ST_COMMA", ",");
define("ST_CONCAT", ".");
define("ST_CURLY_CLOSE", "}");
define("ST_CURLY_OPEN", "{");
define("ST_DIVIDE", "/");
define("ST_DOLLAR", "$");
define("ST_EQUAL", "=");
define("ST_EXCLAMATION", "!");
define("ST_IS_GREATER", ">");
define("ST_IS_SMALLER", "<");
define("ST_MINUS", "-");
define("ST_MODULUS", "%");
define("ST_PARENTHESES_CLOSE", ")");
define("ST_PARENTHESES_OPEN", "(");
define("ST_PLUS", "+");
define("ST_QUESTION", "?");
define("ST_QUOTE", '"');
define("ST_REFERENCE", "&");
define("ST_SEMI_COLON", ";");
define("ST_TIMES", "*");
define("ST_BITWISE_OR", "|");
define("ST_BITWISE_XOR", "^");
if (!defined("T_POW")) {
	define("T_POW", "**");
}
if (!defined("T_POW_EQUAL")) {
	define("T_POW_EQUAL", "**=");
}
if (!defined("T_YIELD")) {
	define("T_YIELD", "yield");
}
if (!defined("T_FINALLY")) {
	define("T_FINALLY", "finally");
}
;
abstract class FormatterPass {
	protected $indent_char = "\t";
	protected $new_line = "\n";
	protected $indent = 0;
	protected $code = '';
	protected $ptr = 0;
	protected $tkns = [];
	protected $use_cache = false;
	protected $cache = [];
	protected $ignore_futile_tokens = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

	protected function append_code($code = "") {
		$this->code .= $code;
	}

	private function calculate_cache_key($direction, $ignore_list, $token) {
		return $direction . "\x2" . implode('', $ignore_list) . "\x2" . (is_array($token) ? implode("\x2", $token) : $token);
	}

	abstract public function candidate($source, $found_tokens);
	abstract public function format($source);

	protected function get_token($token) {
		if (isset($token[1])) {
			return $token;
		} else {
			return [$token, $token];
		}
	}

	protected function get_crlf($true = true) {
		return $true ? $this->new_line : "";
	}

	protected function get_crlf_indent() {
		return $this->get_crlf() . $this->get_indent();
	}

	protected function get_indent($increment = 0) {
		return str_repeat($this->indent_char, $this->indent + $increment);
	}

	protected function get_space($true = true) {
		return $true ? " " : "";
	}

	protected function has_ln($text) {
		return (false !== strpos($text, $this->new_line));
	}

	protected function has_ln_after() {
		$id = null;
		$text = null;
		list($id, $text) = $this->inspect_token();
		return T_WHITESPACE === $id && $this->has_ln($text);
	}

	protected function has_ln_before() {
		$id = null;
		$text = null;
		list($id, $text) = $this->inspect_token(-1);
		return T_WHITESPACE === $id && $this->has_ln($text);
	}

	protected function has_ln_left_token() {
		list($id, $text) = $this->get_token($this->left_token());
		return $this->has_ln($text);
	}

	protected function has_ln_right_token() {
		list($id, $text) = $this->get_token($this->right_token());
		return $this->has_ln($text);
	}

	protected function inspect_token($delta = 1) {
		if (!isset($this->tkns[$this->ptr + $delta])) {
			return [null, null];
		}
		return $this->get_token($this->tkns[$this->ptr + $delta]);
	}

	protected function left_token($ignore_list = [], $idx = false) {
		$i = $this->left_token_idx($ignore_list);

		return $this->tkns[$i];
	}

	protected function left_token_idx($ignore_list = []) {
		$ignore_list = $this->resolve_ignore_list($ignore_list);

		$i = $this->walk_left($this->tkns, $this->ptr, $ignore_list);

		return $i;
	}

	protected function left_token_is($token, $ignore_list = []) {
		return $this->token_is('left', $token, $ignore_list);
	}

	protected function left_token_subset_is_at_idx($tkns, $idx, $token, $ignore_list = []) {
		$ignore_list = $this->resolve_ignore_list($ignore_list);

		$idx = $this->walk_left($tkns, $idx, $ignore_list);

		return $this->resolve_token_match($tkns, $idx, $token);
	}

	protected function left_useful_token() {
		return $this->left_token($this->ignore_futile_tokens);
	}

	protected function left_useful_token_idx() {
		return $this->left_token_idx($this->ignore_futile_tokens);
	}

	protected function left_useful_token_is($token) {
		return $this->left_token_is($token, $this->ignore_futile_tokens);
	}

	protected function print_and_stop_at($tknids) {
		if (is_scalar($tknids)) {
			$tknids = [$tknids];
		}
		$tknids = array_flip($tknids);
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$this->cache = [];
			if (isset($tknids[$id])) {
				return [$id, $text];
			}
			$this->append_code($text);
		}
	}

	protected function print_block($start, $end) {
		$count = 1;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$this->cache = [];
			$this->append_code($text);

			if ($start == $id) {
				++$count;
			}
			if ($end == $id) {
				--$count;
			}
			if (0 == $count) {
				break;
			}
		}
	}

	protected function print_curly_block() {
		$count = 1;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$this->cache = [];
			$this->append_code($text);

			if (ST_CURLY_OPEN == $id) {
				++$count;
			}
			if (T_CURLY_OPEN == $id) {
				++$count;
			}
			if (T_DOLLAR_OPEN_CURLY_BRACES == $id) {
				++$count;
			}
			if (ST_CURLY_CLOSE == $id) {
				--$count;
			}
			if (0 == $count) {
				break;
			}
		}
	}

	protected function print_until($tknid) {
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$this->cache = [];
			$this->append_code($text);
			if ($tknid == $id) {
				break;
			}
		}
	}

	protected function print_until_any($tknids) {
		$tknids = array_flip($tknids);
		$whitespace_new_line = false;
		if (isset($tknids[$this->new_line])) {
			$whitespace_new_line = true;
		}
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$this->cache = [];
			$this->append_code($text);
			if ($whitespace_new_line && T_WHITESPACE == $id && $this->has_ln($text)) {
				break;
			}
			if (isset($tknids[$id])) {
				break;
			}
		}
		return $id;
	}

	protected function print_until_the_end_of_string() {
		$this->print_until(ST_QUOTE);
	}

	protected function render($tkns = null) {
		if (null == $tkns) {
			$tkns = $this->tkns;
		}

		$tkns = array_filter($tkns);
		$str = '';
		foreach ($tkns as $token) {
			list($id, $text) = $this->get_token($token);
			$str .= $text;
		}
		return $str;
	}

	protected function render_light($tkns = null) {
		if (null == $tkns) {
			$tkns = $this->tkns;
		}
		$str = '';
		foreach ($tkns as $token) {
			$str .= $token[1];
		}
		return $str;
	}

	private function resolve_ignore_list($ignore_list = []) {
		if (empty($ignore_list)) {
			$ignore_list[T_WHITESPACE] = true;
		} else {
			$ignore_list = array_flip($ignore_list);
		}
		return $ignore_list;
	}

	private function resolve_token_match($tkns, $idx, $token) {
		if (!isset($tkns[$idx])) {
			return false;
		}

		$found_token = $tkns[$idx];
		if ($found_token === $token) {
			return true;
		} elseif (is_array($token) && isset($found_token[1]) && in_array($found_token[0], $token)) {
			return true;
		} elseif (is_array($token) && !isset($found_token[1]) && in_array($found_token, $token)) {
			return true;
		} elseif (isset($found_token[1]) && $found_token[0] == $token) {
			return true;
		}

		return false;
	}

	protected function right_token($ignore_list = []) {
		$i = $this->right_token_idx($ignore_list);

		return $this->tkns[$i];
	}

	protected function right_token_idx($ignore_list = []) {
		$ignore_list = $this->resolve_ignore_list($ignore_list);

		$i = $this->walk_right($this->tkns, $this->ptr, $ignore_list);

		return $i;
	}

	protected function right_token_is($token, $ignore_list = []) {
		return $this->token_is('right', $token, $ignore_list);
	}

	protected function right_token_subset_is_at_idx($tkns, $idx, $token, $ignore_list = []) {
		$ignore_list = $this->resolve_ignore_list($ignore_list);

		$idx = $this->walk_right($tkns, $idx, $ignore_list);

		return $this->resolve_token_match($tkns, $idx, $token);
	}

	protected function right_useful_token() {
		return $this->right_token($this->ignore_futile_tokens);
	}

	// protected function right_useful_token_idx($idx = false) {
	// 	return $this->right_token_idx($this->ignore_futile_tokens);
	// }

	protected function right_useful_token_is($token) {
		return $this->right_token_is($token, $this->ignore_futile_tokens);
	}

	protected function rtrim_and_append_code($code = "") {
		$this->code = rtrim($this->code) . $code;
	}

	protected function scan_and_replace(&$tkns, &$ptr, $start, $end, $call, $look_for) {
		$look_for = array_flip($look_for);
		$placeholder = '<?php' . ' /*\x2 PHPOPEN \x3*/';
		$tmp = '';
		$tkn_count = 1;
		$found_potential_tokens = false;
		while (list($ptr, $token) = each($tkns)) {
			list($id, $text) = $this->get_token($token);
			if (isset($look_for[$id])) {
				$found_potential_tokens = true;
			}
			if ($start == $id) {
				++$tkn_count;
			}
			if ($end == $id) {
				--$tkn_count;
			}
			$tkns[$ptr] = null;
			if (0 == $tkn_count) {
				break;
			}
			$tmp .= $text;
		}
		if ($found_potential_tokens) {
			return $start . str_replace($placeholder, '', $this->{$call}($placeholder . $tmp)) . $end;
		}
		return $start . $tmp . $end;

	}

	protected function set_indent($increment) {
		$this->indent += $increment;
		if ($this->indent < 0) {
			$this->indent = 0;
		}
	}

	protected function siblings($tkns, $ptr) {
		$ignore_list = $this->resolve_ignore_list([T_WHITESPACE]);
		$left = $this->walk_left($tkns, $ptr, $ignore_list);
		$right = $this->walk_right($tkns, $ptr, $ignore_list);
		return [$left, $right];
	}

	protected function substr_count_trailing($haystack, $needle) {
		return strlen(rtrim($haystack, " \t")) - strlen(rtrim($haystack, " \t" . $needle));
	}

	protected function token_is($direction, $token, $ignore_list = []) {
		if ('left' != $direction) {
			$direction = 'right';
		}
		if (!$this->use_cache) {
			return $this->{$direction . '_token_subset_is_at_idx'}($this->tkns, $this->ptr, $token, $ignore_list);
		}

		$key = $this->calculate_cache_key($direction, $ignore_list, $token);
		if (isset($this->cache[$key])) {
			return $this->cache[$key];
		}

		$ret = $this->{$direction . '_token_subset_is_at_idx'}($this->tkns, $this->ptr, $token, $ignore_list);
		$this->cache[$key] = $ret;

		return $ret;
	}

	protected function walk_and_accumulate_until(&$tkns, $tknid) {
		$ret = '';
		while (list($index, $token) = each($tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$ret .= $text;
			if ($tknid == $id) {
				break;
			}
		}
		return $ret;
	}

	private function walk_left($tkns, $idx, $ignore_list) {
		$i = $idx;
		while (--$i >= 0 && isset($tkns[$i][1]) && isset($ignore_list[$tkns[$i][0]]));
		return $i;
	}

	private function walk_right($tkns, $idx, $ignore_list) {
		$i = $idx;
		$tkns_size = sizeof($tkns) - 1;
		while (++$i < $tkns_size && isset($tkns[$i][1]) && isset($ignore_list[$tkns[$i][0]]));
		return $i;
	}

	protected function walk_until($tknid) {
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			if ($id == $tknid) {
				return [$id, $text];
			}
		}
	}
}
;
abstract class AdditionalPass extends FormatterPass {
	abstract public function get_description();
	abstract public function get_example();
}
;
final class CodeFormatter {
	private $passes = [];
	public function addPass(FormatterPass $pass) {
		array_unshift($this->passes, $pass);
	}

	public function formatCode($source = '') {
		$passes = array_map(
			function ($pass) {
				return clone $pass;
			},
			$this->passes
		);
		$found_tokens = [];
		$tkns = token_get_all($source);
		foreach ($tkns as $token) {
			list($id, $text) = $this->get_token($token);
			$found_tokens[$id] = $id;
		}
		while (($pass = array_pop($passes))) {
			if ($pass->candidate($source, $found_tokens)) {
				$source = $pass->format($source);
			}
		}
		return $source;
	}

	protected function get_token($token) {
		if (isset($token[1])) {
			return $token;
		} else {
			return [$token, $token];
		}
	}
};

final class AddMissingCurlyBraces extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		list($tmp, $changed) = $this->addBraces($source);
		while ($changed) {
			list($source, $changed) = $this->addBraces($tmp);
			if ($source === $tmp) {
				break;
			}
			$tmp = $source;
		}
		return $source;
	}
	private function addBraces($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$this->use_cache = true;
		$changed = false;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$this->cache = [];
			switch ($id) {
				case T_WHILE:
				case T_FOREACH:
				case T_FOR:
					$this->append_code($text);
					$paren_count = null;
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						$this->cache = [];
						if (ST_PARENTHESES_OPEN === $id) {
							++$paren_count;
						} elseif (ST_PARENTHESES_CLOSE === $id) {
							--$paren_count;
						}
						$this->append_code($text);
						if (0 === $paren_count && !$this->right_token_is([T_COMMENT, T_DOC_COMMENT])) {
							break;
						}
					}
					if (!$this->right_token_is([ST_CURLY_OPEN, ST_COLON, ST_SEMI_COLON])) {
						$while_in_next_token = $this->right_token_is([T_WHILE, T_DO]);
						$ignore_count = 0;
						if (!$this->left_token_is([T_COMMENT, T_DOC_COMMENT])) {
							$this->rtrim_and_append_code($this->new_line . '{');
						}
						while (list($index, $token) = each($this->tkns)) {
							list($id, $text) = $this->get_token($token);
							$this->ptr = $index;
							$this->cache = [];

							if (ST_QUOTE == $id) {
								$this->append_code($text);
								$this->print_until_the_end_of_string();
								continue;
							}

							if (ST_PARENTHESES_OPEN === $id || ST_CURLY_OPEN === $id || ST_BRACKET_OPEN === $id) {
								++$ignore_count;
							} elseif (ST_PARENTHESES_CLOSE === $id || ST_CURLY_CLOSE === $id || ST_BRACKET_CLOSE === $id) {
								--$ignore_count;
							}
							$this->append_code($text);
							if (ST_SEMI_COLON != $id && $this->right_token_is(T_CLOSE_TAG)) {
								$this->append_code(ST_SEMI_COLON);
								break;
							}
							if (T_INLINE_HTML == $id && !$this->right_token_is(T_OPEN_TAG)) {
								$this->append_code('<?php');
							}
							if ($ignore_count <= 0 && !($this->right_token_is([ST_CURLY_CLOSE, ST_SEMI_COLON, T_OBJECT_OPERATOR, ST_PARENTHESES_OPEN, ST_EQUAL]) || ($while_in_next_token && $this->right_token_is([T_WHILE]))) && (ST_CURLY_CLOSE === $id || ST_SEMI_COLON === $id || T_ELSE === $id || T_ELSEIF === $id)) {
								break;
							}
						}
						$this->append_code($this->get_crlf_indent() . '}' . $this->get_crlf_indent());
						$changed = true;
						break 2;
					}
					break;
				case T_IF:
				case T_ELSEIF:
					$this->append_code($text);
					$paren_count = null;
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						$this->cache = [];
						if (ST_PARENTHESES_OPEN === $id) {
							++$paren_count;
						} elseif (ST_PARENTHESES_CLOSE === $id) {
							--$paren_count;
						}
						$this->append_code($text);
						if (0 === $paren_count && !$this->right_token_is([T_COMMENT, T_DOC_COMMENT])) {
							break;
						}
					}
					if (!$this->right_token_is([ST_CURLY_OPEN, ST_COLON])) {
						$while_in_next_token = $this->right_token_is([T_WHILE, T_DO]);
						$ignore_count = 0;
						if (!$this->left_token_is([T_COMMENT, T_DOC_COMMENT])) {
							$this->rtrim_and_append_code($this->new_line . '{');
						}
						while (list($index, $token) = each($this->tkns)) {
							list($id, $text) = $this->get_token($token);
							$this->ptr = $index;
							$this->cache = [];

							if (ST_QUOTE == $id) {
								$this->append_code($text);
								$this->print_until_the_end_of_string();
								continue;
							}
							if (ST_PARENTHESES_OPEN === $id || ST_CURLY_OPEN === $id || ST_BRACKET_OPEN === $id) {
								++$ignore_count;
							} elseif (ST_PARENTHESES_CLOSE === $id || ST_CURLY_CLOSE === $id || ST_BRACKET_CLOSE === $id) {
								--$ignore_count;
							}
							$this->append_code($text);
							if (T_INLINE_HTML == $id && !$this->right_token_is(T_OPEN_TAG)) {
								$this->append_code('<?php');
							}
							if ($ignore_count <= 0 && !($this->right_token_is([ST_CURLY_CLOSE, ST_SEMI_COLON, T_OBJECT_OPERATOR, ST_PARENTHESES_OPEN]) || ($while_in_next_token && $this->right_token_is([T_WHILE]))) && (ST_CURLY_CLOSE === $id || ST_SEMI_COLON === $id || T_ELSE === $id || T_ELSEIF === $id)) {
								break;
							}
						}
						$this->append_code($this->get_crlf_indent() . '}' . $this->get_crlf_indent());
						$changed = true;
						break 2;
					}
					break;
				case T_ELSE:
					$this->append_code($text);
					if (!$this->right_token_is([ST_CURLY_OPEN, ST_COLON, T_IF])) {
						$while_in_next_token = $this->right_token_is([T_WHILE, T_DO]);
						$ignore_count = 0;
						$this->rtrim_and_append_code('{');
						while (list($index, $token) = each($this->tkns)) {
							list($id, $text) = $this->get_token($token);
							$this->ptr = $index;
							$this->cache = [];

							if (ST_QUOTE == $id) {
								$this->append_code($text);
								$this->print_until_the_end_of_string();
								continue;
							}

							if (ST_PARENTHESES_OPEN === $id || ST_CURLY_OPEN === $id || ST_BRACKET_OPEN === $id) {
								++$ignore_count;
							} elseif (ST_PARENTHESES_CLOSE === $id || ST_CURLY_CLOSE === $id || ST_BRACKET_CLOSE === $id) {
								--$ignore_count;
							}
							$this->append_code($text);
							if (T_INLINE_HTML == $id && !$this->right_token_is(T_OPEN_TAG)) {
								$this->append_code('<?php');
							}
							if ($ignore_count <= 0 && !($this->right_token_is([ST_CURLY_CLOSE, ST_SEMI_COLON, T_OBJECT_OPERATOR, ST_PARENTHESES_OPEN]) || ($while_in_next_token && $this->right_token_is([T_WHILE]))) && (ST_CURLY_CLOSE === $id || ST_SEMI_COLON === $id || T_ELSE === $id || T_ELSEIF === $id)) {
								break;
							}
						}
						$this->append_code($this->get_crlf_indent() . '}' . $this->get_crlf_indent());
						$changed = true;
						break 2;
					}
					break;
				default:
					$this->append_code($text);
					break;
			}
		}
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$this->append_code($text);
		}

		return [$this->code, $changed];
	}
}
;
/**
 * @codeCoverageIgnore
 */
final class AutoImportPass extends FormatterPass {
	const OPENER_PLACEHOLDER = "<?php /*\x2 AUTOIMPORTNS \x3*/";
	const AUTOIMPORT_PLACEHOLDER = "/*\x2 AUTOIMPORT \x3*/";
	private $oracle = null;

	public function __construct($oracleFn) {
		$this->oracle = new SQLite3($oracleFn);
	}

	public function candidate($source, $found_tokens) {
		return true;
	}

	private function used_alias_list($source) {
		$tokens = token_get_all($source);
		$use_stack = [];
		$new_tokens = [];
		$next_tokens = [];
		$touched_namespace = false;
		while (list(, $pop_token) = each($tokens)) {
			$next_tokens[] = $pop_token;
			while (($token = array_shift($next_tokens))) {
				list($id, $text) = $this->get_token($token);
				if (T_NAMESPACE == $id) {
					$touched_namespace = true;
				}
				if (T_USE === $id) {
					$use_item = $text;
					while (list(, $token) = each($tokens)) {
						list($id, $text) = $this->get_token($token);
						if (ST_SEMI_COLON === $id) {
							$use_item .= $text;
							break;
						} elseif (ST_COMMA === $id) {
							$use_item .= ST_SEMI_COLON . $this->new_line;
							$next_tokens[] = [T_USE, 'use', ];
							break;
						} else {
							$use_item .= $text;
						}
					}
					$use_stack[] = $use_item;
					$token = new SurrogateToken();
				}
				if (T_FINAL === $id || T_ABSTRACT === $id || T_INTERFACE === $id || T_CLASS === $id || T_FUNCTION === $id || T_TRAIT === $id || T_VARIABLE === $id) {
					if (sizeof($use_stack) > 0) {
						$new_tokens[] = $this->new_line;
						$new_tokens[] = $this->new_line;
					}
					$new_tokens[] = $token;
					break 2;
				} elseif ($touched_namespace && (T_DOC_COMMENT === $id || T_COMMENT === $id)) {
					if (sizeof($use_stack) > 0) {
						$new_tokens[] = $this->new_line;
					}
					$new_tokens[] = $token;
					break 2;
				}
				$new_tokens[] = $token;
			}
		}

		natcasesort($use_stack);
		$alias_list = [];
		$alias_count = [];
		foreach ($use_stack as $use) {
			if (false !== stripos($use, ' as ')) {
				$alias = substr(strstr($use, ' as '), strlen(' as '), -1);
			} else {
				$alias = basename(str_replace('\\', '/', trim(substr($use, strlen('use'), -1))));
			}
			$alias = strtolower($alias);
			$alias_list[$alias] = strtolower($use);
			$alias_count[$alias] = 0;
		}
		foreach ($new_tokens as $token) {
			if (!($token instanceof SurrogateToken)) {
				list($id, $text) = $this->get_token($token);
				$lower_text = strtolower($text);
				if (T_STRING === $id && isset($alias_list[$lower_text])) {
					++$alias_count[$lower_text];
				}
			}
		}

		while (list(, $token) = each($tokens)) {
			list($id, $text) = $this->get_token($token);
			$lower_text = strtolower($text);
			if (T_STRING === $id && isset($alias_list[$lower_text])) {
				++$alias_count[$lower_text];
			} elseif (T_DOC_COMMENT === $id) {
				foreach ($alias_list as $alias => $use) {
					if (false !== stripos($text, $alias)) {
						++$alias_count[$alias];
					}
				}
			}
		}
		return $alias_count;
	}

	private function singleNamespace($source) {
		$class_list = [];
		$results = $this->oracle->query("SELECT class FROM classes ORDER BY class");
		while (($row = $results->fetchArray())) {
			$class_name = $row['class'];
			$class_name_parts = explode('\\', $class_name);
			$base_class_name = '';
			while (($cnp = array_pop($class_name_parts))) {
				$base_class_name = $cnp . $base_class_name;
				$class_list[strtolower($base_class_name)][] = ltrim(str_replace('\\\\', '\\', '\\' . $class_name) . ' as ' . $base_class_name, '\\');
			}
		}

		$tokens = token_get_all($source);
		$alias_count = [];
		$namespace_name = '';
		while (list($index, $token) = each($tokens)) {
			list($id, $text) = $this->get_token($token);
			if (T_NAMESPACE == $id) {
				while (list($index, $token) = each($tokens)) {
					list($id, $text) = $this->get_token($token);
					if (T_NS_SEPARATOR == $id || T_STRING == $id) {
						$namespace_name .= $text;
					}
					if (ST_SEMI_COLON == $id || ST_CURLY_OPEN == $id) {
						break;
					}
				}
			}
			if (T_USE == $id || T_NAMESPACE == $id || T_FUNCTION == $id || T_DOUBLE_COLON == $id || T_OBJECT_OPERATOR == $id) {
				while (list($index, $token) = each($tokens)) {
					list($id, $text) = $this->get_token($token);
					if (ST_SEMI_COLON == $id || ST_PARENTHESES_OPEN == $id || ST_CURLY_OPEN == $id) {
						break;
					}
				}
			}
			if (T_CLASS == $id) {
				while (list($index, $token) = each($tokens)) {
					list($id, $text) = $this->get_token($token);
					if (T_EXTENDS == $id || T_IMPLEMENTS == $id || ST_CURLY_OPEN == $id) {
						break;
					}
				}
			}

			$lower_text = strtolower($text);
			if (T_STRING === $id) {
				if (!isset($alias_count[$lower_text])) {
					$alias_count[$lower_text] = 0;
				}
				++$alias_count[$lower_text];
			}
		}
		$auto_import_candidates = array_intersect_key($class_list, $alias_count);

		$tokens = token_get_all($source);
		$touched_namespace = false;
		$touched_function = false;
		$return = '';
		while (list(, $token) = each($tokens)) {
			list($id, $text) = $this->get_token($token);

			if (T_NAMESPACE == $id) {
				$touched_namespace = true;
			}
			if (T_FUNCTION == $id) {
				$touched_function = true;
			}
			if (!$touched_function && $touched_namespace && (T_FINAL == $id || T_STATIC == $id || T_USE == $id || T_CLASS == $id || T_INTERFACE == $id || T_TRAIT == $id)) {
				$return .= self::AUTOIMPORT_PLACEHOLDER . $this->new_line;
				$return .= $text;

				break;
			}
			$return .= $text;
		}
		while (list(, $token) = each($tokens)) {
			list($id, $text) = $this->get_token($token);
			$return .= $text;
		}

		$used_alias = $this->used_alias_list($source);
		$replacement = '';
		foreach ($auto_import_candidates as $alias => $candidates) {
			if (isset($used_alias[$alias])) {
				continue;
			}
			usort($candidates, function ($a, $b) use ($namespace_name) {
				return similar_text($a, $namespace_name) < similar_text($b, $namespace_name);
			});
			$replacement .= 'use ' . implode(';' . $this->new_line . '//use ', $candidates) . ';' . $this->new_line;
		}

		$return = str_replace(self::AUTOIMPORT_PLACEHOLDER . $this->new_line, $replacement, $return);
		return $return;
	}
	public function format($source = '') {
		$namespace_count = 0;
		$tokens = token_get_all($source);
		while (list(, $token) = each($tokens)) {
			list($id, $text) = $this->get_token($token);
			if (T_NAMESPACE == $id) {
				++$namespace_count;
			}
		}
		if ($namespace_count <= 1) {
			return $this->singleNamespace($source);
		}

		$return = '';
		reset($tokens);
		while (list($index, $token) = each($tokens)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_NAMESPACE:
					$return .= $text;
					while (list($index, $token) = each($tokens)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						$return .= $text;
						if (ST_CURLY_OPEN == $id) {
							break;
						}
					}
					$namespace_block = '';
					$curly_count = 1;
					while (list($index, $token) = each($tokens)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						$namespace_block .= $text;
						if (ST_CURLY_OPEN == $id) {
							++$curly_count;
						} elseif (ST_CURLY_CLOSE == $id) {
							--$curly_count;
						}

						if (0 == $curly_count) {
							break;
						}
					}
					$return .= str_replace(
						self::OPENER_PLACEHOLDER,
						'',
						$this->singleNamespace(self::OPENER_PLACEHOLDER . $namespace_block)
					);
					break;
				default:
					$return .= $text;
			}
		}

		return $return;
	}
};
final class ConstructorPass extends FormatterPass {
	const TYPE_CAMEL_CASE = 'camel';
	const TYPE_SNAKE_CASE = 'snake';
	const TYPE_GOLANG = 'golang';

	public function __construct($type = self::TYPE_CAMEL_CASE) {
		if (self::TYPE_CAMEL_CASE == $type || self::TYPE_SNAKE_CASE == $type || self::TYPE_GOLANG == $type) {
			$this->type = $type;
		} else {
			$this->type = self::TYPE_CAMEL_CASE;
		}
	}

	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_CLASS])) {
			return true;
		}
		return false;
	}

	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_CLASS:
					$attributes = [];
					$function_list = [];
					$touched_visibility = false;
					$touched_function = false;
					$curly_count = null;
					$this->append_code($text);
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						if (ST_CURLY_OPEN == $id) {
							++$curly_count;
						}
						if (ST_CURLY_CLOSE == $id) {
							--$curly_count;
						}
						if (0 === $curly_count) {
							break;
						}
						$this->append_code($text);
						if (T_PUBLIC == $id) {
							$touched_visibility = T_PUBLIC;
						} elseif (T_PRIVATE == $id) {
							$touched_visibility = T_PRIVATE;
						} elseif (T_PROTECTED == $id) {
							$touched_visibility = T_PROTECTED;
						}
						if (
							T_VARIABLE == $id &&
							(
								T_PUBLIC == $touched_visibility ||
								T_PRIVATE == $touched_visibility ||
								T_PROTECTED == $touched_visibility
							)
						) {
							$attributes[] = $text;
							$touched_visibility = null;
						} elseif (T_FUNCTION == $id) {
							$touched_function = true;
						} elseif ($touched_function && T_STRING == $id) {
							$function_list[] = $text;
							$touched_visibility = null;
							$touched_function = false;
						}
					}
					$function_list = array_combine($function_list, $function_list);
					if (!isset($function_list['__construct'])) {
						$this->append_code('function __construct(' . implode(', ', $attributes) . '){' . $this->new_line);
						foreach ($attributes as $var) {
							$this->append_code($this->generate($var));
						}
						$this->append_code('}' . $this->new_line);
					}

					$this->append_code($text);
					break;
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}

	private function generate($var) {
		switch ($this->type) {
			case self::TYPE_SNAKE_CASE:
				$ret = $this->generateSnakeCase($var);
				break;
			case self::TYPE_GOLANG:
				$ret = $this->generateGolang($var);
				break;
			case self::TYPE_CAMEL_CASE:
			default:
				$ret = $this->generateCamelCase($var);
				break;
		}
		return $ret;
	}
	private function generateCamelCase($var) {
		$str = '$this->set' . ucfirst(str_replace('$', '', $var)) . '(' . $var . ');' . $this->new_line;
		return $str;
	}
	private function generateSnakeCase($var) {
		$str = '$this->set_' . (str_replace('$', '', $var)) . '(' . $var . ');' . $this->new_line;
		return $str;
	}
	private function generateGolang($var) {
		$str = '$this->Set' . ucfirst(str_replace('$', '', $var)) . '(' . $var . ');' . $this->new_line;
		return $str;
	}
};
final class EliminateDuplicatedEmptyLines extends FormatterPass {
	const EMPTY_LINE = "\x2 EMPTYLINE \x3";

	public function candidate($source, $found_tokens) {
		return true;
	}

	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$paren_count = 0;
		$bracket_count = 0;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_WHITESPACE:
					$text = str_replace($this->new_line, self::EMPTY_LINE . $this->new_line, $text);
					$this->append_code($text);
					break;
				default:
					$this->append_code($text);
					break;
			}
		}

		$lines = explode($this->new_line, $this->code);
		$empty_lines = [];
		$block_count = 0;

		foreach ($lines as $idx => $line) {
			if (trim($line) === self::EMPTY_LINE) {
				$empty_lines[$block_count][] = $idx;
			} else {
				++$block_count;
				$empty_lines[$block_count] = [];
			}
		}

		foreach ($empty_lines as $group) {
			array_pop($group);
			foreach ($group as $line_number) {
				unset($lines[$line_number]);
			}
		}

		$this->code = str_replace(self::EMPTY_LINE, '', implode($this->new_line, $lines));

		list($id, $text) = $this->get_token(array_pop($this->tkns));
		if (T_WHITESPACE === $id && '' === trim($text)) {
			$this->code = rtrim($this->code) . $this->new_line;
		}

		return $this->code;
	}
};
final class ExtraCommaInArray extends FormatterPass {
	const ST_SHORT_ARRAY_OPEN = 'SHORT_ARRAY_OPEN';
	const EMPTY_ARRAY = 'ST_EMPTY_ARRAY';

	public function candidate($source, $found_tokens) {
		return true;
	}

	public function format($source) {
		$this->tkns = token_get_all($source);

		$context_stack = [];
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case ST_BRACKET_OPEN:
					if (!$this->left_token_is([ST_BRACKET_CLOSE, T_STRING, T_VARIABLE, T_ARRAY_CAST])) {
						$context_stack[] = self::ST_SHORT_ARRAY_OPEN;
					} else {
						$context_stack[] = ST_BRACKET_OPEN;
					}
					break;
				case ST_BRACKET_CLOSE:
					if (isset($context_stack[0]) && !$this->left_token_is(ST_BRACKET_OPEN)) {
						if (self::ST_SHORT_ARRAY_OPEN == end($context_stack) && $this->has_ln_before() && !$this->left_useful_token_is(ST_COMMA)) {
							$prev_token_idx = $this->left_useful_token_idx();
							list($tkn_id, $tkn_text) = $this->get_token($this->tkns[$prev_token_idx]);
							if (T_END_HEREDOC != $tkn_id && ST_BRACKET_OPEN != $tkn_id) {
								$this->tkns[$prev_token_idx] = [$tkn_id, $tkn_text . ','];
							}
						}
						array_pop($context_stack);
					}
					break;
				case T_STRING:
					if ($this->right_token_is(ST_PARENTHESES_OPEN)) {
						$context_stack[] = T_STRING;
					}
					break;
				case T_ARRAY:
					if ($this->right_token_is(ST_PARENTHESES_OPEN)) {
						$context_stack[] = T_ARRAY;
					}
					break;
				case ST_PARENTHESES_OPEN:
					if (isset($context_stack[0]) && T_ARRAY == end($context_stack) && $this->right_token_is(ST_PARENTHESES_CLOSE)) {
						array_pop($context_stack);
						$context_stack[] = self::EMPTY_ARRAY;
					} elseif (!$this->left_token_is([T_ARRAY, T_STRING])) {
						$context_stack[] = ST_PARENTHESES_OPEN;
					}
					break;
				case ST_PARENTHESES_CLOSE:
					if (isset($context_stack[0])) {
						if (T_ARRAY == end($context_stack) && ($this->has_ln_left_token() || $this->has_ln_before()) && !$this->left_useful_token_is(ST_COMMA)) {
							$prev_token_idx = $this->left_useful_token_idx();
							list($tkn_id, $tkn_text) = $this->get_token($this->tkns[$prev_token_idx]);
							if (T_END_HEREDOC != $tkn_id && ST_PARENTHESES_OPEN != $tkn_id) {
								$this->tkns[$prev_token_idx] = [$tkn_id, $tkn_text . ','];
							}
						}
						array_pop($context_stack);
					}
					break;
			}
			$this->tkns[$this->ptr] = [$id, $text];
		}
		return $this->render_light();
	}
};
final class LeftAlignComment extends FormatterPass {
	const NON_INDENTABLE_COMMENT = "/*\x2 COMMENT \x3*/";
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			if (self::NON_INDENTABLE_COMMENT === $text) {
				continue;
			}
			switch ($id) {
				case T_COMMENT:
				case T_DOC_COMMENT:
					list(, $prev_text) = $this->inspect_token(-1);
					if (self::NON_INDENTABLE_COMMENT === $prev_text) {
						// Benchmark me
						// $new_text = '';
						// $tok = strtok($text, $this->new_line);
						// while (false !== $tok) {
						// 	$v = ltrim($tok);
						// 	if ('*' === substr($v, 0, 1)) {
						// 		$v = ' ' . $v;
						// 	}
						// 	$new_text .= $v;
						// 	if (substr($v, -2, 2) != '*/') {
						// 		$new_text .= $this->new_line;
						// 	}
						// 	$tok = strtok($this->new_line);
						// }
						// $this->append_code($new_text);
						$lines = explode($this->new_line, $text);
						$lines = array_map(function ($v) {
							$v = ltrim($v);
							if ('*' === substr($v, 0, 1)) {
								$v = ' ' . $v;
							}
							return $v;
						}, $lines);
						$this->append_code(implode($this->new_line, $lines));
						break;
					}
				case T_WHITESPACE:
					list(, $next_text) = $this->inspect_token(1);
					if (self::NON_INDENTABLE_COMMENT === $next_text && substr_count($text, "\n") >= 2) {
						$text = substr($text, 0, strrpos($text, "\n") + 1);
						$this->append_code($text);
						break;
					} elseif (self::NON_INDENTABLE_COMMENT === $next_text && substr_count($text, "\n") === 1) {
						$text = substr($text, 0, strrpos($text, "\n") + 1);
						$this->append_code($text);
						break;
					}
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
}
;
final class MergeCurlyCloseAndDoWhile extends FormatterPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_WHILE])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_WHILE:
					$str = $text;
					list($pt_id, $pt_text) = $this->get_token($this->left_token());
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						$str .= $text;
						if (
							ST_CURLY_OPEN == $id ||
							ST_COLON == $id ||
							(ST_SEMI_COLON == $id && (ST_SEMI_COLON == $pt_id || ST_CURLY_OPEN == $pt_id || T_COMMENT == $pt_id || T_DOC_COMMENT == $pt_id))
						) {
							$this->append_code($str);
							break;
						} elseif (ST_SEMI_COLON == $id && !(ST_SEMI_COLON == $pt_id || ST_CURLY_OPEN == $pt_id || T_COMMENT == $pt_id || T_DOC_COMMENT == $pt_id)) {
							$this->rtrim_and_append_code($str);
							break;
						}
					}
					break;
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
}
;
final class MergeDoubleArrowAndArray extends FormatterPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_ARRAY])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$in_do_while_context = 0;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_ARRAY:
					if ($this->left_token_is([T_DOUBLE_ARROW])) {
						--$in_do_while_context;
						$this->rtrim_and_append_code($text);
						break;
					}
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
};
final class MergeParenCloseWithCurlyOpen extends FormatterPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[ST_CURLY_OPEN]) || isset($found_tokens[T_ELSE]) || isset($found_tokens[T_ELSEIF])) {
			return true;
		}

		return false;
	}

	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';

		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case ST_CURLY_OPEN:
					if ($this->left_token_is([T_ELSE, T_STRING, ST_PARENTHESES_CLOSE])) {
						$this->rtrim_and_append_code($text);
					} else {
						$this->append_code($text);
					}
					break;
				case T_ELSE:
				case T_ELSEIF:
					if ($this->left_token_is(ST_CURLY_CLOSE)) {
						$this->rtrim_and_append_code($text);
					} else {
						$this->append_code($text);
					}
					break;
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
}
;
final class NormalizeIsNotEquals extends FormatterPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_IS_NOT_EQUAL])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';

		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_IS_NOT_EQUAL:
					$this->append_code(str_replace('<>', '!=', $text) . $this->get_space());
					break;
				default:
					$this->append_code($text);
					break;
			}
		}

		return $this->code;
	}
}
;
final class NormalizeLnAndLtrimLines extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$source = str_replace(["\r\n", "\n\r", "\r", "\n"], $this->new_line, $source);
		$source = preg_replace('/\h+$/mu', '', $source);

		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case ST_QUOTE:
					$this->append_code($text);
					$this->print_until_the_end_of_string();
					break;
				case T_START_HEREDOC:
					$this->append_code($text);
					$this->print_until(T_END_HEREDOC);
					break;
				case T_COMMENT:
				case T_DOC_COMMENT:
					list($prev_id, $prev_text) = $this->inspect_token(-1);

					if (T_WHITESPACE === $prev_id && ("\n" === $prev_text || "\n\n" == substr($prev_text, -2, 2))) {
						$this->append_code(LeftAlignComment::NON_INDENTABLE_COMMENT);
					}

					$lines = explode($this->new_line, $text);
					$new_text = '';
					foreach ($lines as $v) {
						$v = ltrim($v);
						if ('*' === substr($v, 0, 1)) {
							$v = ' ' . $v;
						}
						$new_text .= $this->new_line . $v;
					}

					$this->append_code(ltrim($new_text));
					break;
				case T_CONSTANT_ENCAPSED_STRING:
					$this->append_code($text);
					break;
				default:
					if ($this->has_ln($text)) {
						$trailing_new_line = $this->substr_count_trailing($text, $this->new_line);
						if ($trailing_new_line > 0) {
							$text = trim($text) . str_repeat($this->new_line, $trailing_new_line);
						}
					}
					$this->append_code($text);
					break;
			}
		}

		return $this->code;
	}
}
;
final class OrderUseClauses extends FormatterPass {
	const OPENER_PLACEHOLDER = "<?php /*\x2 ORDERBY \x3*/";
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_USE])) {
			return true;
		}

		return false;
	}
	private function singleNamespace($source) {
		$tokens = token_get_all($source);
		$use_stack = [];
		$new_tokens = [];
		$next_tokens = [];
		$touched_namespace = false;
		while (list(, $pop_token) = each($tokens)) {
			$next_tokens[] = $pop_token;
			while (($token = array_shift($next_tokens))) {
				list($id, $text) = $this->get_token($token);
				if (T_NAMESPACE == $id) {
					$touched_namespace = true;
				}
				if (T_USE === $id) {
					$use_item = $text;
					while (list(, $token) = each($tokens)) {
						list($id, $text) = $this->get_token($token);
						if (ST_SEMI_COLON === $id) {
							$use_item .= $text;
							break;
						} elseif (ST_COMMA === $id) {
							$use_item .= ST_SEMI_COLON;
							$next_tokens[] = [T_WHITESPACE, $this->new_line, ];
							$next_tokens[] = [T_USE, 'use', ];
							break;
						} else {
							$use_item .= $text;
						}
					}
					$use_stack[] = trim($use_item);
					$token = new SurrogateToken();
				}
				if (T_FINAL === $id || T_ABSTRACT === $id || T_INTERFACE === $id || T_CLASS === $id || T_FUNCTION === $id || T_TRAIT === $id || T_VARIABLE === $id) {
					if (sizeof($use_stack) > 0) {
						$new_tokens[] = $this->new_line;
						$new_tokens[] = $this->new_line;
					}
					$new_tokens[] = $token;
					break 2;
				} elseif ($touched_namespace && (T_DOC_COMMENT === $id || T_COMMENT === $id)) {
					if (sizeof($use_stack) > 0) {
						$new_tokens[] = $this->new_line;
					}
					$new_tokens[] = $token;
					break 2;
				}
				$new_tokens[] = $token;
			}
		}
		if (empty($use_stack)) {
			return $source;
		}
		natcasesort($use_stack);
		$alias_list = [];
		$alias_count = [];
		foreach ($use_stack as $use) {
			if (false !== stripos($use, ' as ')) {
				$alias = substr(strstr($use, ' as '), strlen(' as '), -1);
			} else {
				$alias = basename(str_replace('\\', '/', trim(substr($use, strlen('use'), -1))));
			}
			$alias = str_replace(ST_SEMI_COLON, '', strtolower($alias));
			$alias_list[$alias] = trim(strtolower($use));
			$alias_count[$alias] = 0;
		}

		$return = '';
		foreach ($new_tokens as $idx => $token) {
			if ($token instanceof SurrogateToken) {
				$return .= array_shift($use_stack);
			} elseif (T_WHITESPACE == $token[0] && isset($new_tokens[$idx - 1], $new_tokens[$idx + 1]) && $new_tokens[$idx - 1] instanceof SurrogateToken && $new_tokens[$idx + 1] instanceof SurrogateToken) {
				$return .= $this->new_line;
				continue;
			} else {
				list($id, $text) = $this->get_token($token);
				$lower_text = strtolower($text);
				if (T_STRING === $id && isset($alias_list[$lower_text])) {
					++$alias_count[$lower_text];
				} elseif (T_DOC_COMMENT === $id) {
					foreach ($alias_list as $alias => $use) {
						if (false !== stripos($text, $alias)) {
							++$alias_count[$alias];
						}
					}
				}
				$return .= $text;
			}
		}

		while (list(, $token) = each($tokens)) {
			list($id, $text) = $this->get_token($token);
			$lower_text = strtolower($text);
			if (T_STRING === $id && isset($alias_list[$lower_text])) {
				++$alias_count[$lower_text];
			} elseif (T_DOC_COMMENT === $id) {
				foreach ($alias_list as $alias => $use) {
					if (false !== stripos($text, $alias)) {
						++$alias_count[$alias];
					}
				}
			}
			$return .= $text;
		}

		$unused_import = array_keys(
			array_filter(
				$alias_count, function ($v) {
					return 0 === $v;
				}
			)
		);
		foreach ($unused_import as $v) {
			$return = str_ireplace($alias_list[$v] . $this->new_line, null, $return);
		}

		return $return;
	}
	public function format($source = '') {
		$namespace_count = 0;
		$tokens = token_get_all($source);
		$touched_t_use = false;
		while (list(, $token) = each($tokens)) {
			list($id, $text) = $this->get_token($token);
			if (T_USE === $id) {
				$touched_t_use = true;
			}
			if (T_NAMESPACE == $id) {
				++$namespace_count;
			}
		}
		if ($namespace_count <= 1 && $touched_t_use) {
			return $this->singleNamespace($source);
		}

		$return = '';
		reset($tokens);
		while (list($index, $token) = each($tokens)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_NAMESPACE:
					$return .= $text;
					$touched_t_use = false;
					while (list($index, $token) = each($tokens)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						$return .= $text;
						if (ST_CURLY_OPEN == $id || ST_SEMI_COLON == $id) {
							break;
						}
					}
					if (ST_CURLY_OPEN === $id) {
						$namespace_block = '';
						$curly_count = 1;
						while (list($index, $token) = each($tokens)) {
							list($id, $text) = $this->get_token($token);
							$this->ptr = $index;
							$namespace_block .= $text;

							if (T_USE === $id) {
								$touched_t_use = true;
							}

							if (ST_CURLY_OPEN == $id) {
								++$curly_count;
							} elseif (ST_CURLY_CLOSE == $id) {
								--$curly_count;
							}

							if (0 == $curly_count) {
								break;
							}
						}
					} elseif (ST_SEMI_COLON === $id) {
						$namespace_block = '';
						while (list($index, $token) = each($tokens)) {
							list($id, $text) = $this->get_token($token);
							$this->ptr = $index;

							if (T_USE === $id) {
								$touched_t_use = true;
							}

							if (T_NAMESPACE == $id) {
								prev($tokens);
								break;
							}

							$namespace_block .= $text;
						}
					}

					$return .= str_replace(
						self::OPENER_PLACEHOLDER,
						'',
						$this->singleNamespace(self::OPENER_PLACEHOLDER . $namespace_block)
					);

					break;
				default:
					$return .= $text;
			}
		}

		return $return;
	}

}
;
final class Reindent extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$this->use_cache = true;
		$found_stack = [];
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$this->cache = [];

			if (
				(
					T_WHITESPACE === $id ||
					(T_COMMENT === $id && '//' == substr($text, 0, 2))
				) && $this->has_ln($text)
			) {
				$bottom_found_stack = end($found_stack);
				if (isset($bottom_found_stack['implicit']) && $bottom_found_stack['implicit']) {
					$idx = sizeof($found_stack) - 1;
					$found_stack[$idx]['implicit'] = false;
					$this->set_indent(+1);
				}
			}
			switch ($id) {
				case ST_QUOTE:
					$this->append_code($text);
					$this->print_until_the_end_of_string();
					break;
				case T_CLOSE_TAG:
					$this->append_code($text);
					$this->print_until(T_OPEN_TAG);
					break;
				case T_START_HEREDOC:
					$this->append_code(rtrim($text) . $this->get_crlf());
					break;
				case T_CONSTANT_ENCAPSED_STRING:
				case T_ENCAPSED_AND_WHITESPACE:
				case T_STRING_VARNAME:
				case T_NUM_STRING:
					$this->append_code($text);
					break;
				case T_DOLLAR_OPEN_CURLY_BRACES:
				case T_CURLY_OPEN:
				case ST_CURLY_OPEN:
				case ST_PARENTHESES_OPEN:
				case ST_BRACKET_OPEN:
					$indent_token = [
						'id' => $id,
						'implicit' => true,
					];
					$this->append_code($text);
					if ($this->has_ln_after()) {
						$indent_token['implicit'] = false;
						$this->set_indent(+1);
					}
					$found_stack[] = $indent_token;
					break;
				case ST_CURLY_CLOSE:
				case ST_PARENTHESES_CLOSE:
				case ST_BRACKET_CLOSE:
					$popped_id = array_pop($found_stack);
					if (false === $popped_id['implicit']) {
						$this->set_indent(-1);
					}
					$this->append_code($text);
					break;

				case T_DOC_COMMENT:
					$text = str_replace($this->new_line, $this->new_line . $this->get_indent(), $text);
					$this->append_code($text);
					break;
				default:
					$has_ln = ($this->has_ln($text));
					if ($has_ln) {
						$is_next_curly_paren_bracket_close = $this->right_token_is([ST_CURLY_CLOSE, ST_PARENTHESES_CLOSE, ST_BRACKET_CLOSE]);
						if (!$is_next_curly_paren_bracket_close) {
							$text = str_replace($this->new_line, $this->new_line . $this->get_indent(), $text);
						} elseif ($is_next_curly_paren_bracket_close) {
							$this->set_indent(-1);
							$text = str_replace($this->new_line, $this->new_line . $this->get_indent(), $text);
							$this->set_indent(+1);
						}
					}
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}

}
;
final class ReindentColonBlocks extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->use_cache = true;
		$this->code = '';

		$found_colon = false;
		foreach ($this->tkns as $token) {
			list($id, $text) = $this->get_token($token);
			if (T_DEFAULT == $id || T_CASE == $id || T_SWITCH == $id) {
				$found_colon = true;
				break;
			}
			$this->append_code($text);
		}
		if (!$found_colon) {
			return $source;
		}

		prev($this->tkns);
		$switch_level = 0;
		$switch_curly_count = [];
		$switch_curly_count[$switch_level] = 0;
		$is_next_case_or_default = false;
		$touched_colon = false;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$this->cache = [];
			switch ($id) {
				case ST_QUOTE:
					$this->append_code($text);
					$this->print_until_the_end_of_string();
					break;

				case T_SWITCH:
					++$switch_level;
					$switch_curly_count[$switch_level] = 0;
					$touched_colon = false;
					$this->append_code($text);
					break;

				case ST_CURLY_OPEN:
					$this->append_code($text);
					if ($this->left_token_is([T_VARIABLE, T_OBJECT_OPERATOR, ST_DOLLAR])) {
						$this->print_curly_block();
						break;
					}
					++$switch_curly_count[$switch_level];
					break;

				case ST_CURLY_CLOSE:
					--$switch_curly_count[$switch_level];
					if (0 === $switch_curly_count[$switch_level] && $switch_level > 0) {
						--$switch_level;
					}
					$this->append_code($this->get_indent($switch_level) . $text);
					break;

				case T_DEFAULT:
				case T_CASE:
					$touched_colon = false;
					$this->append_code($text);
					break;

				case ST_COLON:
					$touched_colon = true;
					$this->append_code($text);
					break;

				default:
					$has_ln = $this->has_ln($text);
					if ($has_ln) {
						$is_next_case_or_default = $this->right_useful_token_is([T_CASE, T_DEFAULT]);
						if ($touched_colon && T_COMMENT == $id && $is_next_case_or_default) {
							$this->append_code($text);
						} elseif ($touched_colon && T_COMMENT == $id && !$is_next_case_or_default) {
							$this->append_code($this->get_indent($switch_level) . $text);
							if (!$this->right_token_is([ST_CURLY_CLOSE, T_COMMENT, T_DOC_COMMENT])) {
								$this->append_code($this->get_indent($switch_level));
							}
						} elseif (!$is_next_case_or_default && !$this->right_token_is([ST_CURLY_CLOSE, T_COMMENT, T_DOC_COMMENT])) {
							$this->append_code($text . $this->get_indent($switch_level));
						} else {
							$this->append_code($text);
						}
					} else {
						$this->append_code($text);
					}
					break;
			}
		}
		return $this->code;
	}
};
final class ReindentIfColonBlocks extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$found_colon = false;
		foreach ($this->tkns as $token) {
			list($id, $text) = $this->get_token($token);
			if (ST_COLON == trim($text)) {
				$found_colon = true;
				break;
			}
		}
		if (!$found_colon) {
			return $source;
		}
		reset($this->tkns);
		$this->code = '';

		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_ENDIF:
					$this->set_indent(-1);
					$this->append_code($text);
					break;
				case T_ELSE:
				case T_ELSEIF:
					$this->set_indent(-1);
				case T_IF:
					$this->append_code($text);
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						$this->append_code($text);
						if (ST_PARENTHESES_OPEN === $id) {
							$paren_count = 1;
							while (list($index, $token) = each($this->tkns)) {
								list($id, $text) = $this->get_token($token);
								$this->ptr = $index;
								$this->append_code($text);
								if (ST_PARENTHESES_OPEN === $id) {
									++$paren_count;
								}
								if (ST_PARENTHESES_CLOSE === $id) {
									--$paren_count;
								}
								if (0 == $paren_count) {
									break;
								}
							}
						} elseif (ST_CURLY_OPEN === $id) {
							break;
						} elseif (ST_COLON === $id && !$this->right_token_is([T_CLOSE_TAG])) {
							$this->set_indent(+1);
							break;
						} elseif (ST_COLON === $id) {
							break;
						}
					}
					break;
				default:
					$has_ln = $this->has_ln($text);
					if ($has_ln && !$this->right_token_is([T_ENDIF, T_ELSE, T_ELSEIF])) {
						$text = str_replace($this->new_line, $this->new_line . $this->get_indent(), $text);
					} elseif ($has_ln && $this->right_token_is([T_ENDIF, T_ELSE, T_ELSEIF])) {
						$this->set_indent(-1);
						$text = str_replace($this->new_line, $this->new_line . $this->get_indent(), $text);
						$this->set_indent(+1);
					}
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
};
final class ReindentLoopColonBlocks extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}

	public function format($source) {
		$tkns = token_get_all($source);
		$found_endwhile = false;
		$found_endforeach = false;
		$found_endfor = false;
		foreach ($tkns as $token) {
			list($id, $text) = $this->get_token($token);
			if (!$found_endwhile && T_ENDWHILE == $id) {
				$source = $this->format_while_blocks($source);
				$found_endwhile = true;
			} elseif (!$found_endforeach && T_ENDFOREACH == $id) {
				$source = $this->format_foreach_blocks($source);
				$found_endforeach = true;
			} elseif (!$found_endfor && T_ENDFOR == $id) {
				$source = $this->format_for_blocks($source);
				$found_endfor = true;
			} elseif ($found_endwhile && $found_endforeach && $found_endfor) {
				break;
			}
		}
		return $source;
	}

	private function format_blocks($source, $open_token, $close_token) {
		$this->tkns = token_get_all($source);
		$this->code = '';

		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case $close_token:
					$this->set_indent(-1);
					$this->append_code($text);
					break;
				case $open_token:
					$this->append_code($text);
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						$this->append_code($text);
						if (ST_CURLY_OPEN === $id) {
							break;
						} elseif (ST_COLON === $id && !$this->right_token_is([T_CLOSE_TAG])) {
							$this->set_indent(+1);
							break;
						} elseif (ST_COLON === $id) {
							break;
						}
					}
					break;
				default:
					if ($this->has_ln($text) && !$this->right_token_is([$close_token])) {
						$text = str_replace($this->new_line, $this->new_line . $this->get_indent(), $text);
					} elseif ($this->has_ln($text) && $this->right_token_is([$close_token])) {
						$this->set_indent(-1);
						$text = str_replace($this->new_line, $this->new_line . $this->get_indent(), $text);
						$this->set_indent(+1);
					}
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
	private function format_for_blocks($source) {
		return $this->format_blocks($source, T_FOR, T_ENDFOR);
	}
	private function format_foreach_blocks($source) {
		return $this->format_blocks($source, T_FOREACH, T_ENDFOREACH);
	}
	private function format_while_blocks($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';

		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_ENDWHILE:
					$this->set_indent(-1);
					$this->append_code($text);
					break;
				case T_WHILE:
					$this->append_code($text);
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						$this->append_code($text);
						if (ST_CURLY_OPEN === $id) {
							break;
						} elseif (ST_SEMI_COLON === $id) {
							break;
						} elseif (ST_COLON === $id) {
							$this->set_indent(+1);
							break;
						}
					}
					break;
				default:
					if ($this->has_ln($text) && !$this->right_token_is([T_ENDWHILE])) {
						$text = str_replace($this->new_line, $this->new_line . $this->get_indent(), $text);
					} elseif ($this->has_ln($text) && $this->right_token_is([T_ENDWHILE])) {
						$this->set_indent(-1);
						$text = str_replace($this->new_line, $this->new_line . $this->get_indent(), $text);
						$this->set_indent(+1);
					}
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
};
final class ReindentObjOps extends FormatterPass {
	const ALIGNABLE_OBJOP = "\x2 OBJOP%d.%d.%d \x3";

	const ALIGN_WITH_INDENT = 1;
	const ALIGN_WITH_SPACES = 2;

	public function candidate($source, $found_tokens) {
		return true;
	}

	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';

		$level_counter = 0;
		$level_entrance_counter = [];
		$context_counter = [];
		$touch_counter = [];
		$align_type = [];
		$printed_placeholder = [];
		$max_context_counter = [];

		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_WHILE:
				case T_IF:
				case T_FOR:
				case T_FOREACH:
				case T_SWITCH:
					$this->append_code($text);
					$this->print_until(ST_PARENTHESES_OPEN);
					$this->print_block(ST_PARENTHESES_OPEN, ST_PARENTHESES_CLOSE);
					break;

				case T_NEW:
					$this->append_code($text);
					if ($this->left_useful_token_is(ST_PARENTHESES_OPEN)) {
						$found_token = $this->print_until_any([ST_PARENTHESES_OPEN, ST_PARENTHESES_CLOSE, ST_COMMA]);
						if (ST_PARENTHESES_OPEN == $found_token) {
							$this->increment_counters($level_counter, $level_entrance_counter, $context_counter, $max_context_counter, $touch_counter, $align_type, $printed_placeholder);
							$this->print_block(ST_PARENTHESES_OPEN, ST_PARENTHESES_CLOSE);
							$this->print_until_any([ST_PARENTHESES_CLOSE, ST_COMMA]);
						}
					}
					break;

				case T_FUNCTION:
					$this->append_code($text);
					if (!$this->right_useful_token_is(T_STRING)) {
						// $this->increment_counters($level_counter, $level_entrance_counter, $context_counter, $max_context_counter, $touch_counter, $align_type, $printed_placeholder);
						$this->print_until(ST_PARENTHESES_OPEN);
						$this->print_block(ST_PARENTHESES_OPEN, ST_PARENTHESES_CLOSE);
						$this->print_until(ST_CURLY_OPEN);
						$this->print_curly_block();
					}
					break;

				case T_VARIABLE:
				case T_STRING:
					$this->append_code($text);
					if (!isset($level_entrance_counter[$level_counter])) {
						$level_entrance_counter[$level_counter] = 0;
					}
					if (!isset($context_counter[$level_counter][$level_entrance_counter[$level_counter]])) {
						$context_counter[$level_counter][$level_entrance_counter[$level_counter]] = 0;
						$max_context_counter[$level_counter][$level_entrance_counter[$level_counter]] = 0;
						$touch_counter[$level_counter][$level_entrance_counter[$level_counter]] = 0;
						$align_type[$level_counter][$level_entrance_counter[$level_counter]] = 0;
						$printed_placeholder[$level_counter][$level_entrance_counter[$level_counter]][$context_counter[$level_counter][$level_entrance_counter[$level_counter]]] = 0;
					}
					break;

				case ST_PARENTHESES_OPEN:
				case ST_BRACKET_OPEN:
					$this->increment_counters($level_counter, $level_entrance_counter, $context_counter, $max_context_counter, $touch_counter, $align_type, $printed_placeholder);
					$this->append_code($text);
					break;

				case ST_PARENTHESES_CLOSE:
				case ST_BRACKET_CLOSE:
					--$level_counter;
					$this->append_code($text);
					break;

				case T_OBJECT_OPERATOR:
					if (0 == $touch_counter[$level_counter][$level_entrance_counter[$level_counter]]) {
						++$touch_counter[$level_counter][$level_entrance_counter[$level_counter]];
						if ($this->has_ln_before()) {
							$align_type[$level_counter][$level_entrance_counter[$level_counter]] = self::ALIGN_WITH_INDENT;
							$this->append_code($this->get_indent(+1) . $text);
							$found_token = $this->print_until_any([ST_PARENTHESES_OPEN, ST_SEMI_COLON, $this->new_line]);
							if (ST_SEMI_COLON == $found_token) {
								$this->increment_counters($level_counter, $level_entrance_counter, $context_counter, $max_context_counter, $touch_counter, $align_type, $printed_placeholder);
							} elseif (ST_PARENTHESES_OPEN == $found_token) {
								$this->increment_counters($level_counter, $level_entrance_counter, $context_counter, $max_context_counter, $touch_counter, $align_type, $printed_placeholder);
								$this->indent_parentheses_content();
							}
						} else {
							$align_type[$level_counter][$level_entrance_counter[$level_counter]] = self::ALIGN_WITH_SPACES;
							if (!isset($printed_placeholder[$level_counter][$level_entrance_counter[$level_counter]][$context_counter[$level_counter][$level_entrance_counter[$level_counter]]])) {
								$printed_placeholder[$level_counter][$level_entrance_counter[$level_counter]][$context_counter[$level_counter][$level_entrance_counter[$level_counter]]] = 0;
							}
							++$printed_placeholder[$level_counter][$level_entrance_counter[$level_counter]][$context_counter[$level_counter][$level_entrance_counter[$level_counter]]];
							$placeholder = sprintf(
								self::ALIGNABLE_OBJOP,
								$level_counter,
								$level_entrance_counter[$level_counter],
								$context_counter[$level_counter][$level_entrance_counter[$level_counter]]
							);
							$this->append_code($placeholder . $text);
							$found_token = $this->print_until_any([ST_PARENTHESES_OPEN, ST_SEMI_COLON, $this->new_line]);
							if (ST_SEMI_COLON == $found_token) {
								$this->increment_counters($level_counter, $level_entrance_counter, $context_counter, $max_context_counter, $touch_counter, $align_type, $printed_placeholder);
							} elseif (ST_PARENTHESES_OPEN == $found_token) {
								$this->increment_counters($level_counter, $level_entrance_counter, $context_counter, $max_context_counter, $touch_counter, $align_type, $printed_placeholder);
								$this->inject_placeholder_parentheses_content($placeholder);
							}
						}
					} elseif ($this->has_ln_before() || $this->has_ln_left_token()) {
						++$touch_counter[$level_counter][$level_entrance_counter[$level_counter]];
						if (self::ALIGN_WITH_SPACES == $align_type[$level_counter][$level_entrance_counter[$level_counter]]) {
							++$printed_placeholder[$level_counter][$level_entrance_counter[$level_counter]][$context_counter[$level_counter][$level_entrance_counter[$level_counter]]];
							$placeholder = sprintf(
								self::ALIGNABLE_OBJOP,
								$level_counter,
								$level_entrance_counter[$level_counter],
								$context_counter[$level_counter][$level_entrance_counter[$level_counter]]
							);
							$this->append_code($placeholder . $text);
							$found_token = $this->print_until_any([ST_PARENTHESES_OPEN, ST_SEMI_COLON, $this->new_line]);
							if (ST_SEMI_COLON == $found_token) {
								$this->increment_counters($level_counter, $level_entrance_counter, $context_counter, $max_context_counter, $touch_counter, $align_type, $printed_placeholder);
							} elseif (ST_PARENTHESES_OPEN == $found_token) {
								$this->increment_counters($level_counter, $level_entrance_counter, $context_counter, $max_context_counter, $touch_counter, $align_type, $printed_placeholder);
								$this->inject_placeholder_parentheses_content($placeholder);
							}
						} else {
							$this->append_code($this->get_indent(+1) . $text);
							$found_token = $this->print_until_any([ST_PARENTHESES_OPEN, ST_SEMI_COLON, $this->new_line]);
							if (ST_SEMI_COLON == $found_token) {
								$this->increment_counters($level_counter, $level_entrance_counter, $context_counter, $max_context_counter, $touch_counter, $align_type, $printed_placeholder);
							} elseif (ST_PARENTHESES_OPEN == $found_token) {
								$this->increment_counters($level_counter, $level_entrance_counter, $context_counter, $max_context_counter, $touch_counter, $align_type, $printed_placeholder);
								$this->indent_parentheses_content();
							}
						}
					} else {
						$this->append_code($text);
					}
					break;

				case T_COMMENT:
				case T_DOC_COMMENT:
					if (
						isset($align_type[$level_counter]) &&
						isset($level_entrance_counter[$level_counter]) &&
						isset($align_type[$level_counter][$level_entrance_counter[$level_counter]]) &&
						($this->has_ln_before() || $this->has_ln_left_token())
					) {
						if (self::ALIGN_WITH_SPACES == $align_type[$level_counter][$level_entrance_counter[$level_counter]]) {
							++$printed_placeholder[$level_counter][$level_entrance_counter[$level_counter]][$context_counter[$level_counter][$level_entrance_counter[$level_counter]]];
							$this->append_code(
								sprintf(
									self::ALIGNABLE_OBJOP,
									$level_counter,
									$level_entrance_counter[$level_counter],
									$context_counter[$level_counter][$level_entrance_counter[$level_counter]]
								)
							);
						} elseif (self::ALIGN_WITH_INDENT == $align_type[$level_counter][$level_entrance_counter[$level_counter]]) {
							$this->append_code($this->get_indent(+1));
						}
					}
					$this->append_code($text);
					break;

				case ST_COMMA:
				case ST_SEMI_COLON:
					if (!isset($level_entrance_counter[$level_counter])) {
						$level_entrance_counter[$level_counter] = 0;
					}
					++$level_entrance_counter[$level_counter];
					$this->append_code($text);
					break;

				default:
					$this->append_code($text);
					break;
			}
		}
		$orig_code = $this->code;
		foreach ($max_context_counter as $level => $entrances) {
			foreach ($entrances as $entrance => $context) {
				for ($j = 0; $j <= $context; ++$j) {
					if (!isset($printed_placeholder[$level][$entrance][$j])) {
						continue;
					}
					if (0 === $printed_placeholder[$level][$entrance][$j]) {
						continue;
					}

					$placeholder = sprintf(self::ALIGNABLE_OBJOP, $level, $entrance, $j);
					if (1 === $printed_placeholder[$level][$entrance][$j]) {
						$this->code = str_replace($placeholder, '', $this->code);
						continue;
					}

					$lines = explode($this->new_line, $this->code);
					$lines_with_objop = [];
					$block_count = 0;

					foreach ($lines as $idx => $line) {
						if (false !== strpos($line, $placeholder)) {
							$lines_with_objop[] = $idx;
						}
					}

					$farthest = 0;
					foreach ($lines_with_objop as $idx) {
						$farthest = max($farthest, strpos($lines[$idx], $placeholder . '->'));
					}
					foreach ($lines_with_objop as $idx) {
						$line = $lines[$idx];
						$current = strpos($line, $placeholder);
						$delta = abs($farthest - $current);
						if ($delta > 0) {
							$line = str_replace($placeholder, str_repeat(' ', $delta) . $placeholder, $line);
							$lines[$idx] = $line;
						}
					}

					$this->code = str_replace($placeholder, '', implode($this->new_line, $lines));
				}
			}
		}
		return $this->code;
	}

	private function indent_parentheses_content() {
		$count = 0;
		$i = $this->ptr;
		$sizeof_tokens = sizeof($this->tkns);
		for ($i = $this->ptr; $i < $sizeof_tokens; ++$i) {
			$token = &$this->tkns[$i];
			list($id, $text) = $this->get_token($token);
			if (T_WHITESPACE == $id && $this->has_ln($text)) {
				$token[1] = $text . $this->get_indent(+1);
				continue;
			}
			if (ST_PARENTHESES_OPEN == $id) {
				++$count;
			}
			if (ST_PARENTHESES_CLOSE == $id) {
				--$count;
			}
			if (0 == $count) {
				break;
			}
		}
	}

	private function inject_placeholder_parentheses_content($placeholder) {
		$count = 0;
		$i = $this->ptr;
		$sizeof_tokens = sizeof($this->tkns);
		for ($i = $this->ptr; $i < $sizeof_tokens; ++$i) {
			$token = &$this->tkns[$i];
			list($id, $text) = $this->get_token($token);
			if (T_WHITESPACE == $id && $this->has_ln($text)) {
				$token[1] = str_replace($this->new_line, $this->new_line . $placeholder, $text);
				continue;
			}
			if (ST_PARENTHESES_OPEN == $id) {
				++$count;
			}
			if (ST_PARENTHESES_CLOSE == $id) {
				--$count;
			}
			if (0 == $count) {
				break;
			}
		}
	}

	private function increment_counters(
		&$level_counter,
		&$level_entrance_counter,
		&$context_counter,
		&$max_context_counter,
		&$touch_counter,
		&$align_type,
		&$printed_placeholder
	) {
		++$level_counter;
		if (!isset($level_entrance_counter[$level_counter])) {
			$level_entrance_counter[$level_counter] = 0;
		}
		++$level_entrance_counter[$level_counter];
		if (!isset($context_counter[$level_counter][$level_entrance_counter[$level_counter]])) {
			$context_counter[$level_counter][$level_entrance_counter[$level_counter]] = 0;
			$max_context_counter[$level_counter][$level_entrance_counter[$level_counter]] = 0;
			$touch_counter[$level_counter][$level_entrance_counter[$level_counter]] = 0;
			$align_type[$level_counter][$level_entrance_counter[$level_counter]] = 0;
			$printed_placeholder[$level_counter][$level_entrance_counter[$level_counter]][$context_counter[$level_counter][$level_entrance_counter[$level_counter]]] = 0;
		}
		++$context_counter[$level_counter][$level_entrance_counter[$level_counter]];
		$max_context_counter[$level_counter][$level_entrance_counter[$level_counter]] = max($max_context_counter[$level_counter][$level_entrance_counter[$level_counter]], $context_counter[$level_counter][$level_entrance_counter[$level_counter]]);

	}
}
;
final class RemoveIncludeParentheses extends FormatterPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_INCLUDE]) || isset($found_tokens[T_REQUIRE]) || isset($found_tokens[T_INCLUDE_ONCE]) || isset($found_tokens[T_REQUIRE_ONCE])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_INCLUDE:
				case T_REQUIRE:
				case T_INCLUDE_ONCE:
				case T_REQUIRE_ONCE:
					$this->append_code($text . $this->get_space());

					if (!$this->right_token_is(ST_PARENTHESES_OPEN)) {
						break;
					}
					$this->walk_until(ST_PARENTHESES_OPEN);
					$count = 1;
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						$this->cache = [];

						if (ST_PARENTHESES_OPEN == $id) {
							++$count;
						}
						if (ST_PARENTHESES_CLOSE == $id) {
							--$count;
						}
						if (0 == $count) {
							break;
						}
						$this->append_code($text);
					}
					break;
				default:
					$this->append_code($text);
					break;
			}
		}

		return $this->code;
	}
}
;
final class ResizeSpaces extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	private function filterWhitespaces($source) {
		$tkns = token_get_all($source);

		$new_tkns = [];
		foreach ($tkns as $idx => $token) {
			if (T_WHITESPACE === $token[0] && !$this->has_ln($token[1])) {
				continue;
			}
			$new_tkns[] = $token;
		}

		return $new_tkns;
	}

	public function format($source) {
		$this->tkns = $this->filterWhitespaces($source);
		$this->code = '';
		$this->use_cache = true;

		$in_ternary_operator = false;
		$short_ternary_operator = false;
		$touched_function = false;
		$touched_use = false;

		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$this->cache = [];
			switch ($id) {
				case T_START_HEREDOC:
					$this->append_code($text);
					$this->print_until(ST_SEMI_COLON);
					break;

				case T_CALLABLE:
					$this->append_code($text . $this->get_space());
					break;

				case '+':
				case '-':
					if (
						$this->left_useful_token_is([T_LNUMBER, T_DNUMBER, T_VARIABLE, ST_PARENTHESES_CLOSE, T_STRING, T_ARRAY, T_ARRAY_CAST, T_BOOL_CAST, T_DOUBLE_CAST, T_INT_CAST, T_OBJECT_CAST, T_STRING_CAST, T_UNSET_CAST, ST_BRACKET_CLOSE])
						&&
						$this->right_useful_token_is([T_LNUMBER, T_DNUMBER, T_VARIABLE, ST_PARENTHESES_OPEN, ST_PARENTHESES_CLOSE, T_STRING, T_ARRAY, T_ARRAY_CAST, T_BOOL_CAST, T_DOUBLE_CAST, T_INT_CAST, T_OBJECT_CAST, T_STRING_CAST, T_UNSET_CAST, ST_BRACKET_CLOSE])
					) {
						$this->append_code($this->get_space() . $text . $this->get_space());
					} else {
						$this->append_code($text);
					}
					break;
				case '*':
					list($prev_id, $prev_text) = $this->inspect_token(-1);
					list($next_id, $next_text) = $this->inspect_token(+1);
					if (
						T_WHITESPACE === $prev_id &&
						T_WHITESPACE !== $next_id
					) {
						$this->append_code($text . $this->get_space());
					} elseif (
						T_WHITESPACE !== $prev_id &&
						T_WHITESPACE === $next_id
					) {
						$this->append_code($this->get_space() . $text);
					} elseif (
						T_WHITESPACE !== $prev_id &&
						T_WHITESPACE !== $next_id
					) {
						$this->append_code($this->get_space() . $text . $this->get_space());
					} else {
						$this->append_code($text);
					}
					break;

				case '%':
				case '/':
				case T_POW:

				case ST_QUESTION:
				case ST_CONCAT:
					if (ST_QUESTION == $id) {
						$in_ternary_operator = true;
						$short_ternary_operator = $this->right_token_is(ST_COLON);
					}
					list($prev_id, $prev_text) = $this->inspect_token(-1);
					list($next_id, $next_text) = $this->inspect_token(+1);
					if (
						T_WHITESPACE === $prev_id &&
						T_WHITESPACE !== $next_id
					) {
						$this->append_code($text . $this->get_space(!$this->right_token_is(ST_COLON)));
						break;
					} elseif (
						T_WHITESPACE !== $prev_id &&
						T_WHITESPACE === $next_id
					) {
						$this->append_code($this->get_space() . $text);
						break;
					} elseif (
						T_WHITESPACE !== $prev_id &&
						T_WHITESPACE !== $next_id
					) {
						$this->append_code($this->get_space() . $text . $this->get_space(!$this->right_token_is(ST_COLON)));
						break;
					}
				case ST_COLON:
					list($prev_id, $prev_text) = $this->inspect_token(-1);
					list($next_id, $next_text) = $this->inspect_token(+1);
					if (
						$in_ternary_operator &&
						T_WHITESPACE === $prev_id &&
						T_WHITESPACE !== $next_id
					) {
						$this->append_code($text . $this->get_space());
						$in_ternary_operator = false;
					} elseif (
						$in_ternary_operator &&
						T_WHITESPACE !== $prev_id &&
						T_WHITESPACE === $next_id
					) {
						$this->append_code($this->get_space(!$short_ternary_operator) . $text);
						$in_ternary_operator = false;
					} elseif (
						$in_ternary_operator &&
						T_WHITESPACE !== $prev_id &&
						T_WHITESPACE !== $next_id
					) {
						$this->append_code($this->get_space(!$short_ternary_operator) . $text . $this->get_space());
						$in_ternary_operator = false;
					} else {
						$this->append_code($text);
					}
					break;

				case T_PRINT:
					$this->append_code($text . $this->get_space(!$this->right_token_is([ST_PARENTHESES_OPEN])));
					break;
				case T_ARRAY:
					if ($this->right_token_is([T_VARIABLE, ST_REFERENCE])) {
						$this->append_code($text . $this->get_space());
						break;
					} elseif ($this->right_token_is(ST_PARENTHESES_OPEN)) {
						$this->append_code($text);
						break;
					}
				case T_STRING:
					if ($this->right_token_is([T_VARIABLE, T_DOUBLE_ARROW])) {
						$this->append_code($text . $this->get_space());
						break;
					} else {
						$this->append_code($text);
						break;
					}
				case ST_CURLY_OPEN:
					$touched_function = false;
					if (!$touched_use && $this->left_useful_token_is([T_VARIABLE, T_STRING]) && $this->right_useful_token_is([T_VARIABLE, T_STRING])) {
						$this->append_code($text);
						break;
					} elseif (!$this->has_ln_left_token() && $this->left_useful_token_is([T_STRING, T_DO, T_FINALLY, ST_PARENTHESES_CLOSE])) {
						$this->rtrim_and_append_code($this->get_space() . $text);
						break;
					} elseif ($this->right_token_is(ST_CURLY_CLOSE) || ($this->right_token_is([T_VARIABLE]) && $this->left_token_is([T_OBJECT_OPERATOR, ST_DOLLAR]))) {
						$this->append_code($text);
						break;
					} elseif ($this->right_token_is([T_VARIABLE, T_INC, T_DEC])) {
						$this->append_code($text . $this->get_space());
						break;
					} else {
						$this->append_code($text);
						break;
					}

				case ST_SEMI_COLON:
					$touched_use = false;
					if ($this->right_token_is([T_VARIABLE, T_INC, T_DEC, T_LNUMBER, T_DNUMBER, T_COMMENT, T_DOC_COMMENT])) {
						$this->append_code($text . $this->get_space());
						break;
					}
				case ST_PARENTHESES_OPEN:
					if (!$this->has_ln_left_token() && $this->left_useful_token_is([T_WHILE, T_CATCH])) {
						$this->rtrim_and_append_code($this->get_space() . $text);
					} else {
						$this->append_code($text);
					}
					break;
				case ST_PARENTHESES_CLOSE:
					$this->append_code($text);
					break;
				case T_USE:
					if ($this->left_token_is(ST_PARENTHESES_CLOSE)) {
						$this->append_code($this->get_space() . $text . $this->get_space());
					} else {
						$this->append_code($text . $this->get_space());
					}
					$touched_use = true;
					break;
				case T_RETURN:
				case T_YIELD:
				case T_ECHO:
				case T_NAMESPACE:
				case T_VAR:
				case T_NEW:
				case T_CONST:
				case T_FINAL:
				case T_CASE:
				case T_BREAK:
					$this->append_code($text . $this->get_space(!$this->right_token_is(ST_SEMI_COLON)));
					break;
				case T_WHILE:
					if ($this->left_token_is(ST_CURLY_CLOSE) && !$this->has_ln_before()) {
						$this->append_code($this->get_space() . $text . $this->get_space());
						break;
					}
				case T_DOUBLE_ARROW:
					if (T_DOUBLE_ARROW == $id && $this->left_token_is([T_CONSTANT_ENCAPSED_STRING, T_STRING, T_VARIABLE, T_LNUMBER, T_DNUMBER, ST_PARENTHESES_CLOSE, ST_BRACKET_CLOSE, ST_CURLY_CLOSE, ST_QUOTE])) {
						$this->rtrim_and_append_code($this->get_space() . $text . $this->get_space());
						break;
					}
				case T_STATIC:
					$this->append_code($text . $this->get_space(!$this->right_token_is([ST_SEMI_COLON, T_DOUBLE_COLON, ST_PARENTHESES_OPEN])));
					break;
				case T_FUNCTION:
					$touched_function = true;
					$this->append_code($text . $this->get_space(!$this->right_token_is(ST_SEMI_COLON)));
					break;
				case T_PUBLIC:
				case T_PRIVATE:
				case T_PROTECTED:
				case T_TRAIT:
				case T_INTERFACE:
				case T_THROW:
				case T_GLOBAL:
				case T_ABSTRACT:
				case T_INCLUDE:
				case T_REQUIRE:
				case T_INCLUDE_ONCE:
				case T_REQUIRE_ONCE:
				case T_DECLARE:
				case T_IF:
				case T_FOR:
				case T_FOREACH:
				case T_SWITCH:
				case T_TRY:
				case ST_COMMA:
				case T_CLONE:
				case T_CONTINUE:
					$this->append_code($text . $this->get_space(!$this->right_token_is(ST_SEMI_COLON)));
					break;
				case T_CLASS:
					$this->append_code($text . $this->get_space(!$this->right_token_is(ST_SEMI_COLON) && !$this->left_token_is([T_DOUBLE_COLON])));
					break;
				case T_EXTENDS:
				case T_IMPLEMENTS:
				case T_INSTANCEOF:
				case T_INSTEADOF:
				case T_AS:
					$this->append_code($this->get_space() . $text . $this->get_space());
					break;
				case T_LOGICAL_AND:
				case T_LOGICAL_OR:
				case T_LOGICAL_XOR:
				case T_AND_EQUAL:
				case T_BOOLEAN_AND:
				case T_BOOLEAN_OR:
				case T_CONCAT_EQUAL:
				case T_DIV_EQUAL:
				case T_IS_EQUAL:
				case T_IS_GREATER_OR_EQUAL:
				case T_IS_IDENTICAL:
				case T_IS_NOT_EQUAL:
				case T_IS_NOT_IDENTICAL:
				case T_IS_SMALLER_OR_EQUAL:
				case T_MINUS_EQUAL:
				case T_MOD_EQUAL:
				case T_MUL_EQUAL:
				case T_OR_EQUAL:
				case T_PLUS_EQUAL:
				case T_SL:
				case T_SL_EQUAL:
				case T_SR:
				case T_SR_EQUAL:
				case T_XOR_EQUAL:
				case ST_IS_GREATER:
				case ST_IS_SMALLER:
				case ST_EQUAL:
					$this->append_code($this->get_space(!$this->has_ln_before()) . $text . $this->get_space());
					break;
				case T_CATCH:
				case T_FINALLY:
					if ($this->has_ln_left_token()) {
						$this->append_code($this->get_space() . $text . $this->get_space());
					} else {
						$this->rtrim_and_append_code($this->get_space() . $text . $this->get_space());
					}
					break;
				case T_ELSEIF:
					if (!$this->left_token_is(ST_CURLY_CLOSE)) {
						$this->append_code($text . $this->get_space());
					} else {
						$this->append_code($this->get_space() . $text . $this->get_space());
					}
					break;
				case T_ELSE:
					if (!$this->left_useful_token_is(ST_CURLY_CLOSE)) {
						$this->append_code($text);
					} else {
						$this->append_code($this->get_space(!$this->left_token_is([T_COMMENT, T_DOC_COMMENT])) . $text . $this->get_space());
					}
					break;
				case T_ARRAY_CAST:
				case T_BOOL_CAST:
				case T_DOUBLE_CAST:
				case T_INT_CAST:
				case T_OBJECT_CAST:
				case T_STRING_CAST:
				case T_UNSET_CAST:
				case T_GOTO:
					$this->append_code(str_replace([' ', "\t"], '', $text) . $this->get_space());
					break;
				case ST_REFERENCE:
					$space_before = !$this->left_useful_token_is([ST_EQUAL, ST_PARENTHESES_OPEN, T_AS, T_DOUBLE_ARROW, ST_COMMA]) && !$this->left_useful_token_is(T_ARRAY);
					$space_after = !$touched_function && !$this->left_useful_token_is([ST_EQUAL, ST_PARENTHESES_OPEN, T_AS, T_DOUBLE_ARROW, ST_COMMA]);
					$this->append_code($this->get_space($space_before) . $text . $this->get_space($space_after));
					break;
				default:
					$this->append_code($text);
					break;
			}
		}

		return $this->code;
	}
}
;
final class RTrim extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		return preg_replace('/\h+$/mu', '', $source);
	}
};
final class SettersAndGettersPass extends FormatterPass {
	const TYPE_CAMEL_CASE = 'camel';
	const TYPE_SNAKE_CASE = 'snake';
	const TYPE_GOLANG = 'golang';
	public function __construct($type = self::TYPE_CAMEL_CASE) {
		if (self::TYPE_CAMEL_CASE == $type || self::TYPE_SNAKE_CASE == $type || self::TYPE_GOLANG == $type) {
			$this->type = $type;
		} else {
			$this->type = self::TYPE_CAMEL_CASE;
		}
	}
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_CLASS])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_CLASS:
					$attributes = [
						'private' => [],
						'public' => [],
						'protected' => [],
					];
					$function_list = [];
					$touched_visibility = false;
					$touched_function = false;
					$curly_count = null;
					$this->append_code($text);
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						if (ST_CURLY_OPEN == $id) {
							++$curly_count;
						}
						if (ST_CURLY_CLOSE == $id) {
							--$curly_count;
						}
						if (0 === $curly_count) {
							break;
						}
						$this->append_code($text);
						if (T_PUBLIC == $id) {
							$touched_visibility = T_PUBLIC;
						} elseif (T_PRIVATE == $id) {
							$touched_visibility = T_PRIVATE;
						} elseif (T_PROTECTED == $id) {
							$touched_visibility = T_PROTECTED;
						}
						if (T_VARIABLE == $id && T_PUBLIC == $touched_visibility) {
							$attributes['public'][] = $text;
							$touched_visibility = null;
						} elseif (T_VARIABLE == $id && T_PRIVATE == $touched_visibility) {
							$attributes['private'][] = $text;
							$touched_visibility = null;
						} elseif (T_VARIABLE == $id && T_PROTECTED == $touched_visibility) {
							$attributes['protected'][] = $text;
							$touched_visibility = null;
						} elseif (T_FUNCTION == $id) {
							$touched_function = true;
						} elseif ($touched_function && T_STRING == $id) {
							$function_list[] = $text;
							$touched_visibility = null;
							$touched_function = false;
						}
					}
					$function_list = array_combine($function_list, $function_list);
					foreach ($attributes as $visibility => $variables) {
						foreach ($variables as $var) {
							$str = $this->generate($visibility, $var);
							foreach ($function_list as $k => $v) {
								if (false !== stripos($str, $v)) {
									unset($function_list[$k]);
									continue 2;
								}
							}
							$this->append_code($str);
						}
					}

					$this->append_code($text);
					break;
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}

	private function generate($visibility, $var) {
		switch ($this->type) {
			case self::TYPE_SNAKE_CASE:
				$ret = $this->generateSnakeCase($visibility, $var);
				break;
			case self::TYPE_GOLANG:
				$ret = $this->generateGolang($visibility, $var);
				break;
			case self::TYPE_CAMEL_CASE:
			default:
				$ret = $this->generateCamelCase($visibility, $var);
				break;
		}
		return $ret;
	}
	private function generateCamelCase($visibility, $var) {
		$str = $visibility . ' function set' . ucfirst(str_replace('$', '', $var)) . '(' . $var . '){' . $this->new_line . '$this->' . str_replace('$', '', $var) . ' = ' . $var . ';' . $this->new_line . '}' . $this->new_line;
		$str .= $visibility . ' function get' . ucfirst(str_replace('$', '', $var)) . '(){' . $this->new_line . 'return $this->' . str_replace('$', '', $var) . ';' . $this->new_line . '}' . $this->new_line;
		return $str;
	}
	private function generateSnakeCase($visibility, $var) {
		$str = $visibility . ' function set_' . (str_replace('$', '', $var)) . '(' . $var . '){' . $this->new_line . '$this->' . str_replace('$', '', $var) . ' = ' . $var . ';' . $this->new_line . '}' . $this->new_line;
		$str .= $visibility . ' function get_' . (str_replace('$', '', $var)) . '(){' . $this->new_line . 'return $this->' . str_replace('$', '', $var) . ';' . $this->new_line . '}' . $this->new_line;
		return $str;
	}
	private function generateGolang($visibility, $var) {
		$str = $visibility . ' function Set' . ucfirst(str_replace('$', '', $var)) . '(' . $var . '){' . $this->new_line . '$this->' . str_replace('$', '', $var) . ' = ' . $var . ';' . $this->new_line . '}' . $this->new_line;
		$str .= $visibility . ' function ' . ucfirst(str_replace('$', '', $var)) . '(){' . $this->new_line . 'return $this->' . str_replace('$', '', $var) . ';' . $this->new_line . '}' . $this->new_line;
		return $str;
	}
};
final class SurrogateToken {
}
;
final class TwoCommandsInSameLine extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;

			switch ($id) {
				case ST_SEMI_COLON:
					if ($this->left_token_is(ST_SEMI_COLON)) {
						break;
					}
					$this->append_code($text);
					if (!$this->has_ln_after() && $this->right_token_is([T_VARIABLE, T_STRING])) {
						$this->append_code($this->new_line);
					}
					break;

				case ST_PARENTHESES_OPEN:
					$this->append_code($text);
					$this->print_block(ST_PARENTHESES_OPEN, ST_PARENTHESES_CLOSE);
					break;
				default:
					$this->append_code($text);
					break;

			}
		}
		return $this->code;
	}
}
;

final class PSR1BOMMark extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$bom = "\xef\xbb\xbf";
		if (substr($source, 0, 3) === $bom) {
			return substr($source, 3);
		}
		return $source;
	}
}
;
final class PSR1ClassConstants extends FormatterPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_CONST]) || isset($found_tokens[T_STRING])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$uc_const = false;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_CONST:
					$uc_const = true;
					$this->append_code($text);
					break;
				case T_STRING:
					if ($uc_const) {
						$text = strtoupper($text);
						$uc_const = false;
					}
					$this->append_code($text);
					break;
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
};
final class PSR1ClassNames extends FormatterPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_CLASS]) || isset($found_tokens[T_STRING])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$found_class = false;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_CLASS:
					$found_class = true;
					$this->append_code($text);
					break;
				case T_STRING:
					if ($found_class) {
						$count = 0;
						$tmp = ucwords(str_replace(['-', '_'], ' ', strtolower($text), $count));
						if ($count > 0) {
							$text = str_replace(' ', '', $tmp);
						}
						$this->append_code($text);

						$found_class = false;
						break;
					}
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
}
;
final class PSR1MethodNames extends FormatterPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_FUNCTION]) || isset($found_tokens[T_STRING]) || isset($found_tokens[ST_PARENTHESES_OPEN])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$found_method = false;
		$method_replace_list = [];
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_FUNCTION:
					$found_method = true;
					$this->append_code($text);
					break;
				case T_STRING:
					if ($found_method) {
						$count = 0;
						$orig_text = $text;
						$tmp = ucwords(str_replace(['-', '_'], ' ', strtolower($text), $count));
						if ($count > 0 && '' !== trim($tmp) && '_' !== substr($text, 0, 1)) {
							$text = lcfirst(str_replace(' ', '', $tmp));
						}

						$method_replace_list[$orig_text] = $text;
						$this->append_code($text);

						$found_method = false;
						break;
					}
				case ST_PARENTHESES_OPEN:
					$found_method = false;
				default:
					$this->append_code($text);
					break;
			}
		}

		$this->tkns = token_get_all($this->code);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_STRING:
					if (isset($method_replace_list[$text]) && $this->right_useful_token_is(ST_PARENTHESES_OPEN)) {

						$this->append_code($method_replace_list[$text]);
						break;
					}

				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
}
;
final class PSR1OpenTags extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_OPEN_TAG:
					if ('<?php' !== $text) {
						$this->append_code('<?php' . $this->new_line);
						break;
					}
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
}
;
final class PSR2AlignObjOp extends FormatterPass {
	const ALIGNABLE_TOKEN = "\x2 OBJOP%d \x3";
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[ST_SEMI_COLON]) || isset($found_tokens[T_ARRAY]) || isset($found_tokens[T_DOUBLE_ARROW]) || isset($found_tokens[T_OBJECT_OPERATOR])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$context_counter = 0;
		$context_meta_count = [];
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case ST_SEMI_COLON:
				case T_ARRAY:
				case T_DOUBLE_ARROW:
					++$context_counter;
					$this->append_code($text);
					break;

				case T_OBJECT_OPERATOR:
					if (!isset($context_meta_count[$context_counter])) {
						$context_meta_count[$context_counter] = 0;
					}
					if ($this->has_ln_before() || 0 == $context_meta_count[$context_counter]) {
						$this->append_code(sprintf(self::ALIGNABLE_TOKEN, $context_counter) . $text);
						++$context_meta_count[$context_counter];
						break;
					}
				default:
					$this->append_code($text);
					break;
			}
		}

		for ($j = 0; $j <= $context_counter; ++$j) {
			$placeholder = sprintf(self::ALIGNABLE_TOKEN, $j);
			if (false === strpos($this->code, $placeholder)) {
				continue;
			}
			if (1 === substr_count($this->code, $placeholder)) {
				$this->code = str_replace($placeholder, '', $this->code);
				continue;
			}

			$lines = explode($this->new_line, $this->code);
			$lines_with_objop = [];
			$block_count = 0;

			foreach ($lines as $idx => $line) {
				if (false !== strpos($line, $placeholder)) {
					$lines_with_objop[$block_count][] = $idx;
				} else {
					++$block_count;
					$lines_with_objop[$block_count] = [];
				}
			}

			foreach ($lines_with_objop as $group) {
				$first_line = reset($group);
				$position_at_first_line = strpos($lines[$first_line], $placeholder);

				foreach ($group as $idx) {
					if ($idx == $first_line) {
						continue;
					}
					$line = ltrim($lines[$idx]);
					$line = str_replace($placeholder, str_repeat(' ', $position_at_first_line) . $placeholder, $line);
					$lines[$idx] = $line;
				}
			}

			$this->code = str_replace($placeholder, '', implode($this->new_line, $lines));
		}
		return $this->code;
	}
}
;
final class PSR2CurlyOpenNextLine extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$this->indent_char = '    ';
		$this->tkns = token_get_all($source);
		$this->code = '';

		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_START_HEREDOC:
					$this->append_code($text);
					$this->print_until(T_END_HEREDOC);
					break;
				case ST_QUOTE:
					$this->append_code($text);
					$this->print_until_the_end_of_string();
					break;
				case T_INTERFACE:
				case T_TRAIT:
				case T_CLASS:
					$this->append_code($text);
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						if (ST_CURLY_OPEN === $id) {
							$this->append_code($this->get_crlf_indent());
							prev($this->tkns);
							break;
						} else {
							$this->append_code($text);
						}
					}
					break;
				case T_FUNCTION:
					if (!$this->left_token_is([T_DOUBLE_ARROW, T_RETURN, ST_EQUAL, ST_PARENTHESES_OPEN, ST_COMMA]) && $this->right_useful_token_is(T_STRING)) {
						$this->append_code($text);
						$touched_ln = false;
						while (list($index, $token) = each($this->tkns)) {
							list($id, $text) = $this->get_token($token);
							$this->ptr = $index;
							if (T_WHITESPACE == $id && $this->has_ln($text)) {
								$touched_ln = true;
							}
							if (ST_CURLY_OPEN === $id && !$touched_ln) {
								$this->append_code($this->get_crlf_indent());
								prev($this->tkns);
								break;
							} elseif (ST_CURLY_OPEN === $id) {
								prev($this->tkns);
								break;
							} else {
								$this->append_code($text);
							}
						}
						break;
					} else {
						$this->append_code($text);
					}
					break;
				case ST_CURLY_OPEN:
					$this->append_code($text);
					$this->set_indent(+1);
					break;
				case ST_CURLY_CLOSE:
					$this->set_indent(-1);
					$this->append_code($text);
					break;
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
};
final class PSR2IndentWithSpace extends FormatterPass {
	private $size = 4;

	public function __construct($size = null) {
		if ($size > 0) {
			$this->size = $size;
		}
	}
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$indent_spaces = str_repeat(' ', (int) $this->size);
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_COMMENT:
				case T_DOC_COMMENT:
				case T_WHITESPACE:
					$this->append_code(str_replace($this->indent_char, $indent_spaces, $text));
					break;
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
};
final class PSR2KeywordsLowerCase extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_ABSTRACT:
				case T_ARRAY:
				case T_ARRAY_CAST:
				case T_AS:
				case T_BOOL_CAST:
				case T_BREAK:
				case T_CASE:
				case T_CATCH:
				case T_CLASS:
				case T_CLONE:
				case T_CONST:
				case T_CONTINUE:
				case T_DECLARE:
				case T_DEFAULT:
				case T_DO:
				case T_DOUBLE_CAST:
				case T_ECHO:
				case T_ELSE:
				case T_ELSEIF:
				case T_EMPTY:
				case T_ENDDECLARE:
				case T_ENDFOR:
				case T_ENDFOREACH:
				case T_ENDIF:
				case T_ENDSWITCH:
				case T_ENDWHILE:
				case T_EVAL:
				case T_EXIT:
				case T_EXTENDS:
				case T_FINAL:
				case T_FINALLY:
				case T_FOR:
				case T_FOREACH:
				case T_FUNCTION:
				case T_GLOBAL:
				case T_GOTO:
				case T_IF:
				case T_IMPLEMENTS:
				case T_INCLUDE:
				case T_INCLUDE_ONCE:
				case T_INSTANCEOF:
				case T_INSTEADOF:
				case T_INT_CAST:
				case T_INTERFACE:
				case T_ISSET:
				case T_LIST:
				case T_LOGICAL_AND:
				case T_LOGICAL_OR:
				case T_LOGICAL_XOR:
				case T_NAMESPACE:
				case T_NEW:
				case T_OBJECT_CAST:
				case T_PRINT:
				case T_PRIVATE:
				case T_PUBLIC:
				case T_PROTECTED:
				case T_REQUIRE:
				case T_REQUIRE_ONCE:
				case T_RETURN:
				case T_STATIC:
				case T_STRING_CAST:
				case T_SWITCH:
				case T_THROW:
				case T_TRAIT:
				case T_TRY:
				case T_UNSET:
				case T_UNSET_CAST:
				case T_USE:
				case T_VAR:
				case T_WHILE:
				case T_XOR_EQUAL:
				case T_YIELD:
					$this->append_code(strtolower($text));
					break;
				default:
					$lc_text = strtolower($text);
					if (
						!$this->left_useful_token_is([
							T_NS_SEPARATOR, T_AS, T_CLASS, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF, T_INTERFACE, T_NEW, T_NS_SEPARATOR, T_PAAMAYIM_NEKUDOTAYIM, T_USE, T_TRAIT, T_INSTEADOF, T_CONST,
						]) &&
						!$this->right_useful_token_is([
							T_NS_SEPARATOR, T_AS, T_CLASS, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF, T_INTERFACE, T_NEW, T_NS_SEPARATOR, T_PAAMAYIM_NEKUDOTAYIM, T_USE, T_TRAIT, T_INSTEADOF, T_CONST,
						]) &&
						('true' === $lc_text || 'false' === $lc_text || 'null' === $lc_text)) {
						$text = $lc_text;
					}
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
};
final class PSR2LnAfterNamespace extends FormatterPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_NAMESPACE])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';

		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_NAMESPACE:
					$this->append_code($this->get_crlf($this->left_token_is(ST_CURLY_CLOSE)) . $text);
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						if (ST_SEMI_COLON === $id) {
							$this->append_code($text);
							list(, $text) = $this->inspect_token();
							if (1 === substr_count($text, $this->new_line)) {
								$this->append_code($this->new_line);
							}
							break;
						} elseif (ST_CURLY_OPEN === $id) {
							$this->append_code($text);
							break;
						} else {
							$this->append_code($text);
						}
					}
					break;
				default:
					$this->append_code($text);
					break;
			}
		}

		return $this->code;
	}
};
final class PSR2ModifierVisibilityStaticOrder extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';

		$found = [];
		$visibility = null;
		$final_or_abstract = null;
		$static = null;
		$skip_whitespaces = false;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_START_HEREDOC:
					$this->append_code($text);
					$this->print_until(T_END_HEREDOC);
					break;
				case ST_QUOTE:
					$this->append_code($text);
					$this->print_until_the_end_of_string();
					break;
				case T_CLASS:
					$found[] = T_CLASS;
					$this->append_code($text);
					break;
				case T_INTERFACE:
					$found[] = T_INTERFACE;
					$this->append_code($text);
					break;
				case T_TRAIT:
					$found[] = T_TRAIT;
					$this->append_code($text);
					break;
				case ST_CURLY_OPEN:
				case ST_PARENTHESES_OPEN:
					$found[] = $text;
					$this->append_code($text);
					break;
				case ST_CURLY_CLOSE:
				case ST_PARENTHESES_CLOSE:
					array_pop($found);
					if (1 === sizeof($found)) {
						array_pop($found);
					}
					$this->append_code($text);
					break;
				case T_WHITESPACE:
					if (!$skip_whitespaces) {
						$this->append_code($text);
					}
					break;
				case T_PUBLIC:
				case T_PRIVATE:
				case T_PROTECTED:
					$visibility = $text;
					$skip_whitespaces = true;
					break;
				case T_FINAL:
				case T_ABSTRACT:
					if (!$this->right_token_is([T_CLASS])) {
						$final_or_abstract = $text;
						$skip_whitespaces = true;
					} else {
						$this->append_code($text);
					}
					break;
				case T_STATIC:
					if (!is_null($visibility)) {
						$static = $text;
						$skip_whitespaces = true;
					} elseif (!$this->right_token_is([T_VARIABLE, T_DOUBLE_COLON]) && !$this->left_token_is([T_NEW])) {
						$static = $text;
						$skip_whitespaces = true;
					} else {
						$this->append_code($text);
					}
					break;
				case T_VARIABLE:
					if (
						null !== $visibility ||
						null !== $final_or_abstract ||
						null !== $static
					) {
						null !== $final_or_abstract && $this->append_code($final_or_abstract . $this->get_space());
						null !== $visibility && $this->append_code($visibility . $this->get_space());
						null !== $static && $this->append_code($static . $this->get_space());
						$final_or_abstract = null;
						$visibility = null;
						$static = null;
						$skip_whitespaces = false;
					}
					$this->append_code($text);
					break;
				case T_FUNCTION:
					$has_found_class_or_interface = isset($found[0]) && (T_CLASS === $found[0] || T_INTERFACE === $found[0] || T_TRAIT === $found[0]) && $this->right_useful_token_is(T_STRING);
					if (isset($found[0]) && $has_found_class_or_interface && null !== $final_or_abstract) {
						$this->append_code($final_or_abstract . $this->get_space());
					}
					if (isset($found[0]) && $has_found_class_or_interface && null !== $visibility) {
						$this->append_code($visibility . $this->get_space());
					} elseif (
						isset($found[0]) && $has_found_class_or_interface &&
						!$this->left_token_is([T_DOUBLE_ARROW, T_RETURN, ST_EQUAL, ST_COMMA, ST_PARENTHESES_OPEN])
					) {
						$this->append_code('public' . $this->get_space());
					}
					if (isset($found[0]) && $has_found_class_or_interface && null !== $static) {
						$this->append_code($static . $this->get_space());
					}
					$this->append_code($text);
					if ('abstract' == strtolower($final_or_abstract)) {
						$this->print_until(ST_SEMI_COLON);
					} else {
						$this->print_until(ST_CURLY_OPEN);
						$this->print_curly_block();
					}
					$final_or_abstract = null;
					$visibility = null;
					$static = null;
					$skip_whitespaces = false;
					break;
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
};
final class PSR2SingleEmptyLineAndStripClosingTag extends FormatterPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$token_count = count($this->tkns) - 1;
		while (list($index, $token) = each($this->tkns)) {
			list($id, ) = $this->get_token($token);
			$this->ptr = $index;
			if (T_INLINE_HTML == $id && $this->ptr != $token_count) {
				return $source;
			}
		}

		list($id, $text) = $this->get_token(end($this->tkns));
		$this->ptr = key($this->tkns);

		if (T_CLOSE_TAG == $id) {
			unset($this->tkns[$this->ptr]);
		} elseif (T_INLINE_HTML == $id && '' == trim($text) && $this->left_token_is(T_CLOSE_TAG)) {
			unset($this->tkns[$this->ptr]);
			$ptr = $this->left_token_idx([]);
			unset($this->tkns[$ptr]);
		}

		return rtrim($this->render()) . $this->new_line;
	}
}
;
class PsrDecorator {
	public static function PSR1(CodeFormatter $fmt) {
		$fmt->addPass(new PSR1OpenTags());
		$fmt->addPass(new PSR1BOMMark());
		$fmt->addPass(new PSR1ClassConstants());
	}

	public static function PSR1_naming(CodeFormatter $fmt) {
		$fmt->addPass(new PSR1ClassNames());
		$fmt->addPass(new PSR1MethodNames());
	}

	public static function PSR2(CodeFormatter $fmt) {
		$fmt->addPass(new PSR2KeywordsLowerCase());
		$fmt->addPass(new PSR2IndentWithSpace());
		$fmt->addPass(new PSR2LnAfterNamespace());
		$fmt->addPass(new PSR2CurlyOpenNextLine());
		$fmt->addPass(new PSR2ModifierVisibilityStaticOrder());
		$fmt->addPass(new PSR2SingleEmptyLineAndStripClosingTag());
	}

	public static function decorate(CodeFormatter $fmt) {
		self::PSR1($fmt);
		self::PSR1_naming($fmt);
		self::PSR2($fmt);
	}
};

class AddMissingParentheses extends AdditionalPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_NEW])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_NEW:
					$this->append_code($text);
					list($found_id, $found_text) = $this->print_and_stop_at([ST_PARENTHESES_OPEN, T_COMMENT, T_DOC_COMMENT, ST_SEMI_COLON]);
					if (ST_PARENTHESES_OPEN != $found_id) {
						$this->append_code('()' . $found_text);
					}
					break;
				default:
					$this->append_code($text);
			}
		}

		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Add extra parentheses in new instantiations.';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
$a = new SomeClass;

$a = new SomeClass();
?>
EOT;
	}
}
;
final class AlignDoubleArrow extends AdditionalPass {
	const ALIGNABLE_EQUAL = "\x2 EQUAL%d.%d.%d \x3"; // level.levelentracecounter.counter
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';

		$level_counter = 0;
		$level_entrance_counter = [];
		$context_counter = [];
		$max_context_counter = [];

		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case ST_COMMA:
					if (!$this->has_ln_after() && !$this->has_ln_right_token()) {
						if (!isset($level_entrance_counter[$level_counter])) {
							$level_entrance_counter[$level_counter] = 0;
						}
						if (!isset($context_counter[$level_counter][$level_entrance_counter[$level_counter]])) {
							$context_counter[$level_counter][$level_entrance_counter[$level_counter]] = 0;
							$max_context_counter[$level_counter][$level_entrance_counter[$level_counter]] = 0;
						}
						++$context_counter[$level_counter][$level_entrance_counter[$level_counter]];
						$max_context_counter[$level_counter][$level_entrance_counter[$level_counter]] = max($max_context_counter[$level_counter][$level_entrance_counter[$level_counter]], $context_counter[$level_counter][$level_entrance_counter[$level_counter]]);
					} elseif ($context_counter[$level_counter][$level_entrance_counter[$level_counter]] > 1) {
						$context_counter[$level_counter][$level_entrance_counter[$level_counter]] = 1;
					}
					$this->append_code($text);
					break;

				case T_DOUBLE_ARROW:
					$this->append_code(
						sprintf(
							self::ALIGNABLE_EQUAL,
							$level_counter,
							$level_entrance_counter[$level_counter],
							$context_counter[$level_counter][$level_entrance_counter[$level_counter]]
						) . $text
					);
					break;

				case ST_PARENTHESES_OPEN:
				case ST_BRACKET_OPEN:
					++$level_counter;
					if (!isset($level_entrance_counter[$level_counter])) {
						$level_entrance_counter[$level_counter] = 0;
					}
					++$level_entrance_counter[$level_counter];
					if (!isset($context_counter[$level_counter][$level_entrance_counter[$level_counter]])) {
						$context_counter[$level_counter][$level_entrance_counter[$level_counter]] = 0;
						$max_context_counter[$level_counter][$level_entrance_counter[$level_counter]] = 0;
					}
					++$context_counter[$level_counter][$level_entrance_counter[$level_counter]];
					$max_context_counter[$level_counter][$level_entrance_counter[$level_counter]] = max($max_context_counter[$level_counter][$level_entrance_counter[$level_counter]], $context_counter[$level_counter][$level_entrance_counter[$level_counter]]);

					$this->append_code($text);
					break;

				case ST_PARENTHESES_CLOSE:
				case ST_BRACKET_CLOSE:
					--$level_counter;
					$this->append_code($text);
					break;

				default:
					$this->append_code($text);
					break;
			}
		}

		foreach ($max_context_counter as $level => $entrances) {
			foreach ($entrances as $entrance => $context) {
				for ($j = 0; $j <= $context; ++$j) {
					$placeholder = sprintf(self::ALIGNABLE_EQUAL, $level, $entrance, $j);
					if (false === strpos($this->code, $placeholder)) {
						continue;
					}
					if (1 === substr_count($this->code, $placeholder)) {
						$this->code = str_replace($placeholder, '', $this->code);
						continue;
					}

					$lines = explode($this->new_line, $this->code);
					$lines_with_objop = [];
					$block_count = 0;

					foreach ($lines as $idx => $line) {
						if (false !== strpos($line, $placeholder)) {
							$lines_with_objop[] = $idx;
						}
					}

					$farthest = 0;
					foreach ($lines_with_objop as $idx) {
						$farthest = max($farthest, strpos($lines[$idx], $placeholder));
					}
					foreach ($lines_with_objop as $idx) {
						$line = $lines[$idx];
						$current = strpos($line, $placeholder);
						$delta = abs($farthest - $current);
						if ($delta > 0) {
							$line = str_replace($placeholder, str_repeat(' ', $delta) . $placeholder, $line);
							$lines[$idx] = $line;
						}
					}

					$this->code = str_replace($placeholder, '', implode($this->new_line, $lines));
				}
			}
		}

		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Vertically align T_DOUBLE_ARROW (=>).';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
$a = [
	1 => 1,
	22 => 22,
	333 => 333,
];

$a = [
	1   => 1,
	22  => 22,
	333 => 333,
];
?>
EOT;
	}
}
;
final class AlignEquals extends AdditionalPass {
	const ALIGNABLE_EQUAL = "\x2 EQUAL%d \x3";
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$paren_count = 0;
		$bracket_count = 0;
		$context_counter = 0;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_FUNCTION:
					++$context_counter;
					$this->append_code($text);
					break;
				case ST_PARENTHESES_OPEN:
					++$paren_count;
					$this->append_code($text);
					break;
				case ST_PARENTHESES_CLOSE:
					--$paren_count;
					$this->append_code($text);
					break;
				case ST_BRACKET_OPEN:
					++$bracket_count;
					$this->append_code($text);
					break;
				case ST_BRACKET_CLOSE:
					--$bracket_count;
					$this->append_code($text);
					break;
				case ST_EQUAL:
					if (!$paren_count && !$bracket_count) {
						$this->append_code(sprintf(self::ALIGNABLE_EQUAL, $context_counter) . $text);
						break;
					}

				default:
					$this->append_code($text);
					break;
			}
		}

		for ($j = 0; $j <= $context_counter; ++$j) {
			$placeholder = sprintf(self::ALIGNABLE_EQUAL, $j);
			if (false === strpos($this->code, $placeholder)) {
				continue;
			}
			if (1 === substr_count($this->code, $placeholder)) {
				$this->code = str_replace($placeholder, '', $this->code);
				continue;
			}

			$lines = explode($this->new_line, $this->code);
			$lines_with_objop = [];
			$block_count = 0;

			foreach ($lines as $idx => $line) {
				if (false !== strpos($line, $placeholder)) {
					$lines_with_objop[$block_count][] = $idx;
				} else {
					++$block_count;
					$lines_with_objop[$block_count] = [];
				}
			}

			$i = 0;
			foreach ($lines_with_objop as $group) {
				++$i;
				$farthest = 0;
				foreach ($group as $idx) {
					$farthest = max($farthest, strpos($lines[$idx], $placeholder));
				}
				foreach ($group as $idx) {
					$line = $lines[$idx];
					$current = strpos($line, $placeholder);
					$delta = abs($farthest - $current);
					if ($delta > 0) {
						$line = str_replace($placeholder, str_repeat(' ', $delta) . $placeholder, $line);
						$lines[$idx] = $line;
					}
				}
			}

			$this->code = str_replace($placeholder, '', implode($this->new_line, $lines));
		}

		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Vertically align "=".';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
$a = 1;
$bb = 22;
$ccc = 333;

$a   = 1;
$bb  = 22;
$ccc = 333;

?>
EOT;
	}
};
class AutoPreincrement extends AdditionalPass {
	protected $candidate_tokens = [T_INC, T_DEC];
	protected $check_against_concat = false;
	const CHAIN_VARIABLE = 'CHAIN_VARIABLE';
	const CHAIN_LITERAL = 'CHAIN_LITERAL';
	const CHAIN_FUNC = 'CHAIN_FUNC';
	const CHAIN_STRING = 'CHAIN_STRING';
	const PARENTHESES_BLOCK = 'PARENTHESES_BLOCK';
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_INC]) || isset($found_tokens[T_DEC])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		return $this->swap($source);
	}
	protected function swap($source) {
		$tkns = $this->aggregate_variables($source);
		$touched_concat = false;
		while (list($ptr, $token) = each($tkns)) {
			list($id, $text) = $this->get_token($token);
			switch ($id) {
				case ST_CONCAT:
					$touched_concat = true;
					break;
				case T_INC:
				case T_DEC:
					$prev_token = $tkns[$ptr - 1];
					list($prev_id, ) = $prev_token;
					if (
						(
							!$this->check_against_concat
							||
							($this->check_against_concat && !$touched_concat)
						) &&
						(T_VARIABLE == $prev_id || self::CHAIN_VARIABLE == $prev_id)
					) {
						list($tkns[$ptr], $tkns[$ptr - 1]) = [$tkns[$ptr - 1], $tkns[$ptr]];
						break;
					}
					$touched_concat = false;
			}
		}
		return $this->render($tkns);
	}

	private function aggregate_variables($source) {
		$tkns = token_get_all($source);
		reset($tkns);
		while (list($ptr, $token) = each($tkns)) {
			list($id, $text) = $this->get_token($token);

			if (ST_PARENTHESES_OPEN == $id) {
				$initial_ptr = $ptr;
				$tmp = $this->scan_and_replace($tkns, $ptr, ST_PARENTHESES_OPEN, ST_PARENTHESES_CLOSE, 'swap', $this->candidate_tokens);
				$tkns[$initial_ptr] = [self::PARENTHESES_BLOCK, $tmp];
				continue;
			}
			if (ST_QUOTE == $id) {
				$stack = $text;
				$initial_ptr = $ptr;
				while (list($ptr, $token) = each($tkns)) {
					list($id, $text) = $this->get_token($token);
					$stack .= $text;
					$tkns[$ptr] = null;
					if (ST_QUOTE == $id) {
						break;
					}
				}

				$tkns[$initial_ptr] = [self::CHAIN_STRING, $stack];
				continue;
			}

			if (ST_DOLLAR == $id) {
				$initial_index = $ptr;
				$tkns[$ptr] = null;
				$stack = '';
				do {
					list($ptr, $token) = each($tkns);
					list($id, $text) = $this->get_token($token);
					$tkns[$ptr] = null;
					$stack .= $text;
				} while (ST_CURLY_OPEN != $id);
				$stack = $this->scan_and_replace($tkns, $ptr, ST_CURLY_OPEN, ST_CURLY_CLOSE, 'swap', $this->candidate_tokens);
				$tkns[$initial_index] = [self::CHAIN_VARIABLE, '$' . $stack];
			}

			if (T_STRING == $id || T_VARIABLE == $id || T_NS_SEPARATOR == $id) {
				$initial_index = $ptr;
				$stack = $text;
				$touched_variable = false;
				if (T_VARIABLE == $id) {
					$touched_variable = true;
				}
				if (!$this->right_token_subset_is_at_idx(
					$tkns,
					$ptr,
					[T_STRING, T_VARIABLE, T_NS_SEPARATOR, T_OBJECT_OPERATOR, T_DOUBLE_COLON, ST_CURLY_OPEN, ST_PARENTHESES_OPEN, ST_BRACKET_OPEN]
				)) {
					continue;
				}

				while (list($ptr, $token) = each($tkns)) {
					list($id, $text) = $this->get_token($token);
					$tkns[$ptr] = null;
					if (ST_CURLY_OPEN == $id) {
						$text = $this->scan_and_replace($tkns, $ptr, ST_CURLY_OPEN, ST_CURLY_CLOSE, 'swap', $this->candidate_tokens);
					} elseif (ST_BRACKET_OPEN == $id) {
						$text = $this->scan_and_replace($tkns, $ptr, ST_BRACKET_OPEN, ST_BRACKET_CLOSE, 'swap', $this->candidate_tokens);
					} elseif (ST_PARENTHESES_OPEN == $id) {
						$text = $this->scan_and_replace($tkns, $ptr, ST_PARENTHESES_OPEN, ST_PARENTHESES_CLOSE, 'swap', $this->candidate_tokens);
					}

					$stack .= $text;

					if (!$touched_variable && T_VARIABLE == $id) {
						$touched_variable = true;
					}

					if (
						!$this->right_token_subset_is_at_idx(
							$tkns,
							$ptr,
							[T_STRING, T_VARIABLE, T_NS_SEPARATOR, T_OBJECT_OPERATOR, T_DOUBLE_COLON, ST_CURLY_OPEN, ST_PARENTHESES_OPEN, ST_BRACKET_OPEN]
						)
					) {
						break;
					}
				}
				if (substr(trim($stack), -1, 1) == ST_PARENTHESES_CLOSE) {
					$tkns[$initial_index] = [self::CHAIN_FUNC, $stack];
				} elseif ($touched_variable) {
					$tkns[$initial_index] = [self::CHAIN_VARIABLE, $stack];
				} else {
					$tkns[$initial_index] = [self::CHAIN_LITERAL, $stack];
				}
			}
		}
		$tkns = array_values(array_filter($tkns));
		return $tkns;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Automatically convert postincrement to preincrement.';
	}
	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
$a++;
$b--;

++$a;
--$b;
?>
EOT;
	}
};
class CakePHPStyle extends AdditionalPass {
	private $found_tokens;

	public function candidate($source, $found_tokens) {
		$this->found_tokens = $found_tokens;
		return true;
	}

	public function format($source) {
		$fmt = new PSR2ModifierVisibilityStaticOrder();
		if ($fmt->candidate($source, $this->found_tokens)) {
			$source = $fmt->format($source);
		}
		$fmt = new MergeElseIf();
		if ($fmt->candidate($source, $this->found_tokens)) {
			$source = $fmt->format($source);
		}
		$source = $this->add_underscores_before_name($source);
		$source = $this->remove_space_after_casts($source);
		$source = $this->merge_equals_with_reference($source);
		$source = $this->resize_spaces($source);
		return $source;
	}
	private function resize_spaces($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_COMMENT:
				case T_DOC_COMMENT:
					if (!$this->has_ln_before() && $this->left_token_is(ST_CURLY_OPEN)) {
						$this->rtrim_and_append_code($this->get_space() . $text);
						break;
					} elseif ($this->right_useful_token_is(T_CONSTANT_ENCAPSED_STRING)) {
						$this->append_code($text . $this->get_space());
						break;
					}
					$this->append_code($text);
					break;
				case T_CLOSE_TAG:
					if (!$this->has_ln_before()) {
						$this->rtrim_and_append_code($this->get_space() . $text);
						break;
					}
					$this->append_code($text);
					break;
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
	private function merge_equals_with_reference($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case ST_REFERENCE:
					if ($this->left_useful_token_is(ST_EQUAL)) {
						$this->rtrim_and_append_code($text . $this->get_space());
						break;
					}
				default:
					$this->append_code($text);
			}
		}
		return $this->code;
	}
	private function remove_space_after_casts($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_ARRAY_CAST:
				case T_BOOL_CAST:
				case T_DOUBLE_CAST:
				case T_INT_CAST:
				case T_OBJECT_CAST:
				case T_STRING_CAST:
				case T_UNSET_CAST:
				case T_STRING:
				case T_VARIABLE:
				case ST_PARENTHESES_OPEN:
					if (
						$this->left_useful_token_is([
							T_ARRAY_CAST,
							T_BOOL_CAST,
							T_DOUBLE_CAST,
							T_INT_CAST,
							T_OBJECT_CAST,
							T_STRING_CAST,
							T_UNSET_CAST,
						])
					) {
						$this->rtrim_and_append_code($text);
						break;
					}
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}
	private function add_underscores_before_name($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$level_touched = null;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_PUBLIC:
				case T_PRIVATE:
				case T_PROTECTED:
					$level_touched = $id;
					$this->append_code($text);
					break;

				case T_VARIABLE:
					if (null !== $level_touched && $this->left_useful_token_is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC])) {
						$text = str_replace('$_', '$', $text);
						$text = str_replace('$_', '$', $text);
						if (T_PROTECTED == $level_touched) {
							$text = str_replace('$', '$_', $text);
						} elseif (T_PRIVATE == $level_touched) {
							$text = str_replace('$', '$__', $text);
						}
					}
					$this->append_code($text);
					$level_touched = null;
					break;
				case T_STRING:
					if (
						null !== $level_touched &&
						$this->left_useful_token_is(T_FUNCTION) &&
						'_' != $text &&
						'__' != $text &&
						'__construct' != $text &&
						'__destruct' != $text &&
						'__call' != $text &&
						'__callStatic' != $text &&
						'__get' != $text &&
						'__set' != $text &&
						'__isset' != $text &&
						'__unset' != $text &&
						'__sleep' != $text &&
						'__wakeup' != $text &&
						'__toString' != $text &&
						'__invoke' != $text &&
						'__set_state' != $text &&
						'__clone' != $text &&
						' __debugInfo' != $text
					) {
						if (substr($text, 0, 2) == '__') {
							$text = substr($text, 2);
						}
						if (substr($text, 0, 1) == '_') {
							$text = substr($text, 1);
						}
						if (T_PROTECTED == $level_touched) {
							$text = '_' . $text;
						} elseif (T_PRIVATE == $level_touched) {
							$text = '__' . $text;
						}
					}
					$this->append_code($text);
					$level_touched = null;
					break;
				default:
					$this->append_code($text);
					break;
			}
		}

		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Applies CakePHP Coding Style';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
namespace A;

class A {
	private $__a;
	protected $_b;
	public $c;

	public function b() {
		if($a) {
			noop();
		} else {
			noop();
		}
	}

	protected function _c() {
		if($a) {
			noop();
		} else {
			noop();
		}
	}
}
?>
EOT;
	}
}
;
class EncapsulateNamespaces extends AdditionalPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_NAMESPACE])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$in_namespace_context = false;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_NAMESPACE:
					$this->append_code($text);
					list($found_id, $found_text) = $this->print_and_stop_at([ST_CURLY_OPEN, ST_SEMI_COLON]);
					if (ST_CURLY_OPEN == $found_id) {
						$this->append_code($found_text);
						$this->print_curly_block();
					} elseif (ST_SEMI_COLON == $found_id) {
						$in_namespace_context = true;
						$this->append_code(ST_CURLY_OPEN);
						list($found_id, $found_text) = $this->print_and_stop_at([T_NAMESPACE, T_CLOSE_TAG]);
						if (T_CLOSE_TAG == $found_id) {
							return $source;
						}
						$this->append_code($this->get_crlf() . ST_CURLY_CLOSE . $this->get_crlf());
						prev($this->tkns);
						continue;
					}
					break;
				default:
					$this->append_code($text);
			}
		}

		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Encapsulate namespaces with curly braces';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
namespace NS1;
class A {
}
?>
to
<?php
namespace NS1 {
	class A {
	}
}
?>
EOT;
	}
}
;
final class GeneratePHPDoc extends AdditionalPass {
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$touched_visibility = false;
		$touched_doc_comment = false;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_DOC_COMMENT:
					$touched_doc_comment = true;
				case T_FINAL:
				case T_ABSTRACT:
				case T_PUBLIC:
				case T_PROTECTED:
				case T_PRIVATE:
				case T_STATIC:
					if (!$this->left_token_is([T_FINAL, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT])) {
						$touched_visibility = true;
						$visibility_idx = $this->ptr;
					}
				case T_FUNCTION:
					if ($touched_doc_comment) {
						$touched_doc_comment = false;
						break;
					}
					if (!$touched_visibility) {
						$orig_idx = $this->ptr;
					} else {
						$orig_idx = $visibility_idx;
					}
					list($nt_id, $nt_text) = $this->get_token($this->right_token());
					if (T_STRING != $nt_id) {
						$this->append_code($text);
						break;
					}
					$this->walk_until(ST_PARENTHESES_OPEN);
					$param_stack = [];
					$tmp = ['type' => '', 'name' => ''];
					$count = 1;
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;

						if (ST_PARENTHESES_OPEN == $id) {
							++$count;
						}
						if (ST_PARENTHESES_CLOSE == $id) {
							--$count;
						}
						if (0 == $count) {
							break;
						}
						if (T_STRING == $id || T_NS_SEPARATOR == $id) {
							$tmp['type'] .= $text;
							continue;
						}
						if (T_VARIABLE == $id) {
							if ($this->right_token_is([ST_EQUAL]) && $this->walk_until(ST_EQUAL) && $this->right_token_is([T_ARRAY])) {
								$tmp['type'] = 'array';
							}
							$tmp['name'] = $text;
							$param_stack[] = $tmp;
							$tmp = ['type' => '', 'name' => ''];
							continue;
						}
					}

					$return_stack = '';
					if (!$this->left_useful_token_is(ST_SEMI_COLON)) {
						$this->walk_until(ST_CURLY_OPEN);
						$count = 1;
						while (list($index, $token) = each($this->tkns)) {
							list($id, $text) = $this->get_token($token);
							$this->ptr = $index;

							if (ST_CURLY_OPEN == $id) {
								++$count;
							}
							if (ST_CURLY_CLOSE == $id) {
								--$count;
							}
							if (0 == $count) {
								break;
							}
							if (T_RETURN == $id) {
								if ($this->right_token_is([T_DNUMBER])) {
									$return_stack = 'float';
								} elseif ($this->right_token_is([T_LNUMBER])) {
									$return_stack = 'int';
								} elseif ($this->right_token_is([T_VARIABLE])) {
									$return_stack = 'mixed';
								} elseif ($this->right_token_is([ST_SEMI_COLON])) {
									$return_stack = 'null';
								}
							}
						}
					}

					$func_token = &$this->tkns[$orig_idx];
					$func_token[1] = $this->render_doc_block($param_stack, $return_stack) . $func_token[1];
					$touched_visibility = false;
			}
		}

		return implode('', array_map(function ($token) {
			list(, $text) = $this->get_token($token);
			return $text;
		}, $this->tkns));
	}

	private function render_doc_block(array $param_stack, $return_stack) {
		if (empty($param_stack) && empty($return_stack)) {
			return '';
		}
		$str = '/**' . $this->new_line;
		foreach ($param_stack as $param) {
			$str .= rtrim(' * @param ' . $param['type']) . ' ' . $param['name'] . $this->new_line;
		}
		if (!empty($return_stack)) {
			$str .= ' * @return ' . $return_stack . $this->new_line;
		}
		$str .= ' */' . $this->new_line;
		return $str;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Automatically generates PHPDoc blocks';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
class A {
	function a(Someclass $a) {
		return 1;
	}
}
?>
to
<?php
class A {
	/**
	 * @param Someclass $a
	 * @return int
	 */
	function a(Someclass $a) {
		return 1;
	}
}
?>
EOT;
	}
}
;
class JoinToImplode extends AdditionalPass {
	public function candidate($source, $found_tokens) {
		$this->tkns = token_get_all($source);
		$this->code = '';

		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_STRING:
					if (strtolower($text) == 'join') {
						prev($this->tkns);
						return true;
					}
			}
			$this->append_code($text);
		}
		return false;
	}
	public function format($source) {
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			if (T_STRING == $id && strtolower($text) == 'join' && !($this->left_useful_token_is([T_NEW, T_NS_SEPARATOR, T_STRING, T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_FUNCTION]) || $this->right_useful_token_is([T_NS_SEPARATOR, T_DOUBLE_COLON]))) {
				$this->append_code('implode');
				continue;
			}
			$this->append_code($text);
		}

		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Replace implode() alias (join() -> implode()).';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
$a = join(',', $arr);

$a = implode(',', $arr);
?>
EOT;
	}

}
;
class LaravelStyle extends AdditionalPass {
	private $found_tokens;
	public function candidate($source, $found_tokens) {
		$this->found_tokens = $found_tokens;
		return true;
	}

	public function format($source) {
		$source = $this->namespace_merge_with_open_tag($source);
		$source = $this->allman_style_braces($source);
		$source = (new RTrim())->format($source);

		$fmt = new TightConcat();
		if ($fmt->candidate($source, $this->found_tokens)) {
			$source = $fmt->format($source);
		}
		return $source;
	}

	private function namespace_merge_with_open_tag($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_NAMESPACE:
					if ($this->left_token_is(T_OPEN_TAG)) {
						$this->rtrim_and_append_code($this->get_space() . $text);
						break;
					}
				default:
					$this->append_code($text);
			}
		}

		return $this->code;
	}

	private function allman_style_braces($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$max_detected_indent = 0;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_WHITESPACE:
					if ($this->has_ln($text) && false !== strpos($text, $this->indent_char)) {
						$max_detected_indent = 0;
						$current_detected_indent = 0;
						$len = strlen($text);
						for ($i = 0; $i < $len; ++$i) {
							if ($this->new_line == $text[$i]) {
								$max_detected_indent = max($max_detected_indent, $current_detected_indent);
								$current_detected_indent = 0;
							}
							if ($this->indent_char == $text[$i]) {
								++$current_detected_indent;
							}
						}
						$max_detected_indent = max($max_detected_indent, $current_detected_indent);
					}
					$this->append_code($text);
					break;
				case ST_CURLY_OPEN:
					if ($this->left_useful_token_is([ST_PARENTHESES_CLOSE, T_ELSE, T_FINALLY])) {
						list($prev_id, $prev_text) = $this->get_token($this->left_token());
						if (!$this->has_ln($prev_text)) {
							$this->append_code($this->get_crlf() . $this->get_indent($max_detected_indent));
						}
					}
					$this->append_code($text);
					break;
				case T_ELSE:
				case T_ELSEIF:
				case T_FINALLY:
					list($prev_id, $prev_text) = $this->get_token($this->left_token());
					if (!$this->has_ln($prev_text)) {
						$this->append_code($this->get_crlf() . $this->get_indent($max_detected_indent));
					}
					$this->append_code($text);
					break;
				case T_CATCH:
					if (' ' == substr($this->code, -1, 1)) {
						$this->code = substr($this->code, 0, -1);
					}
					list($prev_id, $prev_text) = $this->get_token($this->left_token());
					if (!$this->has_ln($prev_text)) {
						$this->append_code($this->get_crlf() . $this->get_indent($max_detected_indent));
					}
					$this->append_code($text);
					break;
				default:
					$this->append_code($text);
			}
		}

		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Applies Laravel Coding Style';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php namespace A;

class A {
	function b()
	{
		if($a)
		{
			noop();
		}
		else
		{
			noop();
		}
	}

}
?>
EOT;
	}
}
;
/**
 * From PHP-CS-Fixer
 */
class MergeElseIf extends AdditionalPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_ELSE])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_IF:
					if ($this->left_token_is([T_ELSE]) && !$this->left_token_is([T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO])) {
						$this->rtrim_and_append_code($text);
						break;
					}
				default:
					$this->append_code($text);
					break;
			}
		}

		return $this->code;
	}
	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Merge if with else. ';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
if($a){

} else if($b) {

}

if($a){

} elseif($b) {

}
?>
EOT;
	}
}
;
class MergeNamespaceWithOpenTag extends AdditionalPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_NAMESPACE])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_NAMESPACE:
					if ($this->left_token_is(T_OPEN_TAG)) {
						$this->rtrim_and_append_code($this->new_line . $text);
						break 2;
					}

				default:
					$this->append_code($text);
					break;
			}
		}
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->append_code($text);
		}
		return $this->code;
	}
	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Ensure there is no more than one linebreak before namespace';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php

namespace A;
?>
to
<?php
namespace A;
?>
EOT;
	}
}
;
class MildAutoPreincrement extends AutoPreincrement {
	protected $candidate_tokens = [];
	protected $check_against_concat = true;
};
class NoSpaceAfterPHPDocBlocks extends FormatterPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_DOC_COMMENT])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_WHITESPACE:
					if ($this->has_ln($text) && $this->left_token_is(T_DOC_COMMENT)) {
						$text = substr(strrchr($text, 10), 0);
						$this->append_code($text);
						break;
					}
				default:
					$this->append_code($text);
			}
		}

		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Remove empty lines after PHPDoc blocks.';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
/**
 * @param int $myInt
 */

function a($myInt){
}

/**
 * @param int $myInt
 */
function a($myInt){
}
?>
EOT;
	}
};
final class OrderMethod extends AdditionalPass {
	const OPENER_PLACEHOLDER = "<?php /*\x2 ORDERMETHOD \x3*/";
	const METHOD_REPLACEMENT_PLACEHOLDER = "\x2 METHODPLACEHOLDER \x3";

	public function orderMethods($source) {
		$tokens = token_get_all($source);
		$return = '';
		$function_list = [];
		while (list($index, $token) = each($tokens)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_ABSTRACT:
				case T_STATIC:
				case T_PRIVATE:
				case T_PROTECTED:
				case T_PUBLIC:
					$stack = $text;
					$curly_count = null;
					$touched_method = false;
					$function_name = '';
					while (list($index, $token) = each($tokens)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;

						$stack .= $text;
						if (T_FUNCTION == $id) {
							$touched_method = true;
						}
						if (T_VARIABLE == $id && !$touched_method) {
							break;
						}
						if (T_STRING == $id && $touched_method && empty($function_name)) {
							$function_name = $text;
						}

						if (null === $curly_count && ST_SEMI_COLON == $id) {
							break;
						}

						if (ST_CURLY_OPEN == $id) {
							++$curly_count;
						}
						if (ST_CURLY_CLOSE == $id) {
							--$curly_count;
						}
						if (0 === $curly_count) {
							break;
						}
					}
					if (!$touched_method) {
						$return .= $stack;
					} else {
						$function_list[$function_name] = $stack;
						$return .= self::METHOD_REPLACEMENT_PLACEHOLDER;
					}
					break;
				default:
					$return .= $text;
					break;
			}
		}
		ksort($function_list);
		foreach ($function_list as $function_body) {
			$return = preg_replace('/' . self::METHOD_REPLACEMENT_PLACEHOLDER . '/', $function_body, $return, 1);
		}
		return $return;
	}

	public function candidate($source, $found_tokens) {
		return true;
	}

	public function format($source) {
		$this->tkns = token_get_all($source);
		$return = '';
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_CLASS:
					$return .= $text;
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						$return .= $text;
						if (ST_CURLY_OPEN == $id) {
							break;
						}
					}
					$class_block = '';
					$curly_count = 1;
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						$class_block .= $text;
						if (ST_CURLY_OPEN == $id) {
							++$curly_count;
						} elseif (ST_CURLY_CLOSE == $id) {
							--$curly_count;
						}

						if (0 == $curly_count) {
							break;
						}
					}
					$return .= str_replace(
						self::OPENER_PLACEHOLDER,
						'',
						$this->orderMethods(self::OPENER_PLACEHOLDER . $class_block)
					);
					$this->append_code($return);
					break;
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Sort methods within class in alphabetic order.';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
class A {
	function b(){}
	function c(){}
	function a(){}
}
?>
to
<?php
class A {
	function a(){}
	function b(){}
	function c(){}
}
?>
EOT;
	}
}
;
class RemoveUseLeadingSlash extends AdditionalPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_NAMESPACE]) || isset($found_tokens[T_TRAIT]) || isset($found_tokens[T_CLASS]) || isset($found_tokens[T_FUNCTION]) || isset($found_tokens[T_NS_SEPARATOR])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$last_touched_token = null;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_NAMESPACE:
				case T_TRAIT:
				case T_CLASS:
				case T_FUNCTION:
					$last_touched_token = $id;
				case T_NS_SEPARATOR:
					if (T_NAMESPACE == $last_touched_token && $this->left_token_is([T_USE])) {
						continue;
					}
				default:
					$this->append_code($text);
			}
		}

		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Remove leading slash in T_USE imports.';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
namespace NS1;
use \B;
use \D;

new B();
new D();
?>
to
<?php
namespace NS1;
use B;
use D;

new B();
new D();
?>
EOT;
	}
}
;
class ReturnNull extends AdditionalPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_RETURN])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$this->use_cache = true;
		$touched_return = false;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$this->cache = [];

			if (ST_PARENTHESES_OPEN == $id && $this->left_token_is([T_RETURN])) {
				$paren_count = 1;
				$touched_another_valid_token = false;
				$stack = $text;
				while (list($index, $token) = each($this->tkns)) {
					list($id, $text) = $this->get_token($token);
					$this->ptr = $index;
					$this->cache = [];
					if (ST_PARENTHESES_OPEN == $id) {
						++$paren_count;
					}
					if (ST_PARENTHESES_CLOSE == $id) {
						--$paren_count;
					}
					$stack .= $text;
					if (0 == $paren_count) {
						break;
					}
					if (
						!(
							(T_STRING == $id && strtolower($text) == 'null') ||
							ST_PARENTHESES_OPEN == $id ||
							ST_PARENTHESES_CLOSE == $id
						)
					) {
						$touched_another_valid_token = true;
					}
				}
				if ($touched_another_valid_token) {
					$this->append_code($stack);
				}
				continue;
			}
			if (T_STRING == $id && strtolower($text) == 'null') {
				list($prev_id, ) = $this->left_useful_token();
				list($next_id, ) = $this->right_useful_token();
				if (T_RETURN == $prev_id && ST_SEMI_COLON == $next_id) {
					continue;
				}
			}

			$this->append_code($text);
		}

		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Simplify empty returns.';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
function a(){
	return null;
}
?>
to
<?php
function a(){
	return;
}
?>
EOT;
	}
}
;
/**
 * From PHP-CS-Fixer
 */
class ShortArray extends AdditionalPass {
	const FOUND_ARRAY = 'array';
	const FOUND_PARENTHESES = 'paren';
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_ARRAY])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$found_paren = [];
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_ARRAY:
					if ($this->right_token_is([ST_PARENTHESES_OPEN])) {
						$found_paren[] = self::FOUND_ARRAY;
						$this->print_and_stop_at(ST_PARENTHESES_OPEN);
						$this->append_code(ST_BRACKET_OPEN);
						break;
					}
				case ST_PARENTHESES_OPEN:
					$found_paren[] = self::FOUND_PARENTHESES;
					$this->append_code($text);
					break;

				case ST_PARENTHESES_CLOSE:
					$pop_token = array_pop($found_paren);
					if (self::FOUND_ARRAY == $pop_token) {
						$this->append_code(ST_BRACKET_CLOSE);
						break;
					}
				default:
					$this->append_code($text);
					break;
			}
		}

		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Convert old array into new array. (array() -> [])';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
echo array();
?>
to
<?php
echo [];
?>
EOT;
	}
}
;
final class SmartLnAfterCurlyOpen extends AdditionalPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[ST_CURLY_OPEN])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$curly_count = 0;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case ST_CURLY_OPEN:
					$this->append_code($text);
					$curly_count = 1;
					$stack = '';
					$found_line_break = false;
					$has_ln_after = $this->has_ln_after();
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						$stack .= $text;
						if (T_START_HEREDOC == $id) {
							$stack .= $this->walk_and_accumulate_until($this->tkns, T_END_HEREDOC);
							continue;
						}
						if (ST_QUOTE == $id) {
							$stack .= $this->walk_and_accumulate_until($this->tkns, ST_QUOTE);
							continue;
						}
						if (ST_CURLY_OPEN == $id) {
							++$curly_count;
						}
						if (ST_CURLY_CLOSE == $id) {
							--$curly_count;
						}
						if (T_WHITESPACE === $id && $this->has_ln($text)) {
							$found_line_break = true;
							break;
						}
						if (0 == $curly_count) {
							break;
						}
					}
					if ($found_line_break && !$has_ln_after) {
						$this->append_code($this->new_line);
					}
					$this->append_code($stack);
					break;
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Add line break when implicit curly block is added.';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
if($a) echo array();
?>
to
<?php
if($a) {
	echo array();
}
?>
EOT;
	}
}
;
class SpaceBetweenMethods extends AdditionalPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_FUNCTION])) {
			return true;
		}

		return false;
	}

	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$last_touched_token = null;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_FUNCTION:
					$this->append_code($text);
					$this->print_until(ST_CURLY_OPEN);
					$this->print_curly_block();
					if (!$this->right_token_is([ST_CURLY_CLOSE, ST_SEMI_COLON, ST_COMMA, ST_PARENTHESES_CLOSE])) {
						$this->append_code($this->get_crlf());
					}
					break;
				default:
					$this->append_code($text);
					break;
			}
		}

		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Put space between methods.';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
class A {
	function b(){

	}
	function c(){

	}
}
?>
to
<?php
class A {
	function b(){

	}

	function c(){

	}

}
?>
EOT;
	}
}
;
class TightConcat extends AdditionalPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[ST_CONCAT])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$whitespaces = " \t";
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case ST_CONCAT:
					if (!$this->left_token_is([T_LNUMBER, T_DNUMBER])) {
						$this->code = rtrim($this->code, $whitespaces);
					}
					if (!$this->right_token_is([T_LNUMBER, T_DNUMBER])) {
						each($this->tkns);
					}
				default:
					$this->append_code($text);
					break;
			}
		}
		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Ensure string concatenation does not have spaces, except when close to numbers.';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
// From
$a = 'a' . 'b';
$a = 'a' . 1 . 'b';
// To
$a = 'a'.'b';
$a = 'a'. 1 .'b';
?>
EOT;
	}
};
class WrongConstructorName extends AdditionalPass {
	public function candidate($source, $found_tokens) {
		if (isset($found_tokens[T_NAMESPACE]) || isset($found_tokens[T_CLASS])) {
			return true;
		}

		return false;
	}
	public function format($source) {
		$this->tkns = token_get_all($source);
		$this->code = '';
		$touched_namespace = false;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			switch ($id) {
				case T_NAMESPACE:
					$touched_namespace = true;
					$this->append_code($text);
					break;
				case T_CLASS:
					$this->append_code($text);
					if ($this->left_useful_token_is([T_DOUBLE_COLON])) {
						break;
					}
					if ($touched_namespace) {
						break;
					}
					$class_local_name = '';
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;
						$this->append_code($text);
						if (T_STRING == $id) {
							$class_local_name = strtolower($text);
						}
						if (T_EXTENDS == $id || T_IMPLEMENTS == $id || ST_CURLY_OPEN == $id) {
							break;
						}
					}
					$count = 1;
					while (list($index, $token) = each($this->tkns)) {
						list($id, $text) = $this->get_token($token);
						$this->ptr = $index;

						if (T_STRING == $id && $this->left_useful_token_is([T_FUNCTION]) && strtolower($text) == $class_local_name) {
							$text = '__construct';
						}
						$this->append_code($text);

						if (ST_CURLY_OPEN == $id) {
							++$count;
						}
						if (ST_CURLY_CLOSE == $id) {
							--$count;
						}
						if (0 == $count) {
							break;
						}
					}
					break;
				default:
					$this->append_code($text);
			}
		}

		return $this->code;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Update old constructor names into new ones. http://php.net/manual/en/language.oop5.decon.php';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
class A {
	function A(){

	}
}
?>
to
<?php
class A {
	function __construct(){

	}
}
?>
EOT;
	}
};
final class YodaComparisons extends AdditionalPass {
	const CHAIN_VARIABLE = 'CHAIN_VARIABLE';
	const CHAIN_LITERAL = 'CHAIN_LITERAL';
	const CHAIN_FUNC = 'CHAIN_FUNC';
	const CHAIN_STRING = 'CHAIN_STRING';
	const PARENTHESES_BLOCK = 'PARENTHESES_BLOCK';
	public function candidate($source, $found_tokens) {
		return true;
	}
	public function format($source) {
		return $this->yodise($source);
	}
	protected function yodise($source) {
		$tkns = $this->aggregate_variables($source);
		while (list($ptr, $token) = each($tkns)) {
			if (is_null($token)) {
				continue;
			}
			list($id, $text) = $this->get_token($token);
			switch ($id) {
				case T_IS_EQUAL:
				case T_IS_IDENTICAL:
				case T_IS_NOT_EQUAL:
				case T_IS_NOT_IDENTICAL:
					list($left, $right) = $this->siblings($tkns, $ptr);
					list($left_id, $left_text) = $tkns[$left];
					list($right_id, $right_text) = $tkns[$right];
					if ($left_id == $right_id) {
						continue;
					}

					$left_pure_variable = $this->is_pure_variable($left_id);
					for ($leftmost = $left; $leftmost >= 0; --$leftmost) {
						list($left_scan_id, $left_scan_text) = $this->get_token($tkns[$leftmost]);
						if ($this->is_lower_precedence($left_scan_id)) {
							++$leftmost;
							break;
						}
						$left_pure_variable &= $this->is_pure_variable($left_scan_id);
					}

					$right_pure_variable = $this->is_pure_variable($right_id);
					for ($rightmost = $right; $rightmost < sizeof($tkns) - 1; ++$rightmost) {
						list($right_scan_id, $right_scan_text) = $this->get_token($tkns[$rightmost]);
						if ($this->is_lower_precedence($right_scan_id)) {
							--$rightmost;
							break;
						}
						$right_pure_variable &= $this->is_pure_variable($right_scan_id);
					}

					if ($left_pure_variable && !$right_pure_variable) {
						$orig_left_tokens = $left_tokens = implode('', array_map(function ($token) {
							return isset($token[1]) ? $token[1] : $token;
						}, array_slice($tkns, $leftmost, $left - $leftmost + 1)));
						$orig_right_tokens = $right_tokens = implode('', array_map(function ($token) {
							return isset($token[1]) ? $token[1] : $token;
						}, array_slice($tkns, $right, $rightmost - $right + 1)));

						$left_tokens = (substr($orig_right_tokens, 0, 1) == ' ' ? ' ' : '') . trim($left_tokens) . (substr($orig_right_tokens, -1, 1) == ' ' ? ' ' : '');
						$right_tokens = (substr($orig_left_tokens, 0, 1) == ' ' ? ' ' : '') . trim($right_tokens) . (substr($orig_left_tokens, -1, 1) == ' ' ? ' ' : '');

						$tkns[$leftmost] = ['REPLACED', $right_tokens];
						$tkns[$right] = ['REPLACED', $left_tokens];

						if ($leftmost != $left) {
							for ($i = $leftmost + 1; $i <= $left; ++$i) {
								$tkns[$i] = null;
							}
						}
						if ($rightmost != $right) {
							for ($i = $right + 1; $i <= $rightmost; ++$i) {
								$tkns[$i] = null;
							}
						}
					}
			}
		}
		return $this->render($tkns);
	}

	private function is_pure_variable($id) {
		return self::CHAIN_VARIABLE == $id || T_VARIABLE == $id || T_INC == $id || T_DEC == $id || ST_EXCLAMATION == $id || T_COMMENT == $id || T_DOC_COMMENT == $id || T_WHITESPACE == $id;
	}
	private function is_lower_precedence($id) {
		switch ($id) {
			case ST_REFERENCE:
			case ST_BITWISE_XOR:
			case ST_BITWISE_OR:
			case T_BOOLEAN_AND:
			case T_BOOLEAN_OR:
			case ST_QUESTION:
			case ST_COLON:
			case ST_EQUAL:
			case T_PLUS_EQUAL:
			case T_MINUS_EQUAL:
			case T_MUL_EQUAL:
			case T_POW_EQUAL:
			case T_DIV_EQUAL:
			case T_CONCAT_EQUAL:
			case T_MOD_EQUAL:
			case T_AND_EQUAL:
			case T_OR_EQUAL:
			case T_XOR_EQUAL:
			case T_SL_EQUAL:
			case T_SR_EQUAL:
			case T_DOUBLE_ARROW:
			case T_LOGICAL_AND:
			case T_LOGICAL_XOR:
			case T_LOGICAL_OR:
			case ST_COMMA:
			case ST_SEMI_COLON:
			case T_RETURN:
			case T_THROW:
			case T_GOTO:
			case T_CASE:
			case T_COMMENT:
			case T_DOC_COMMENT:
			case T_OPEN_TAG:
				return true;
		}
		return false;
	}

	private function aggregate_variables($source) {
		$tkns = token_get_all($source);
		while (list($ptr, $token) = each($tkns)) {
			list($id, $text) = $this->get_token($token);

			if (ST_PARENTHESES_OPEN == $id) {
				$initial_ptr = $ptr;
				$tmp = $this->scan_and_replace($tkns, $ptr, ST_PARENTHESES_OPEN, ST_PARENTHESES_CLOSE, 'yodise', [T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL]);
				$tkns[$initial_ptr] = [self::PARENTHESES_BLOCK, $tmp];
				continue;
			}
			if (ST_QUOTE == $id) {
				$stack = $text;
				$initial_ptr = $ptr;
				while (list($ptr, $token) = each($tkns)) {
					list($id, $text) = $this->get_token($token);
					$stack .= $text;
					$tkns[$ptr] = null;
					if (ST_QUOTE == $id) {
						break;
					}
				}

				$tkns[$initial_ptr] = [self::CHAIN_STRING, $stack];
				continue;
			}

			if (T_STRING == $id || T_VARIABLE == $id || T_NS_SEPARATOR == $id) {
				$initial_index = $ptr;
				$stack = $text;
				$touched_variable = false;
				if (T_VARIABLE == $id) {
					$touched_variable = true;
				}
				if (!$this->right_token_subset_is_at_idx(
					$tkns,
					$ptr,
					[T_STRING, T_VARIABLE, T_NS_SEPARATOR, T_OBJECT_OPERATOR, T_DOUBLE_COLON, ST_CURLY_OPEN, ST_PARENTHESES_OPEN, ST_BRACKET_OPEN]
				)) {
					continue;
				}
				while (list($ptr, $token) = each($tkns)) {
					list($id, $text) = $this->get_token($token);
					$tkns[$ptr] = null;
					if (ST_CURLY_OPEN == $id) {
						$text = $this->scan_and_replace($tkns, $ptr, ST_CURLY_OPEN, ST_CURLY_CLOSE, 'yodise', [T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL]);
					} elseif (ST_BRACKET_OPEN == $id) {
						$text = $this->scan_and_replace($tkns, $ptr, ST_BRACKET_OPEN, ST_BRACKET_CLOSE, 'yodise', [T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL]);
					} elseif (ST_PARENTHESES_OPEN == $id) {
						$text = $this->scan_and_replace($tkns, $ptr, ST_PARENTHESES_OPEN, ST_PARENTHESES_CLOSE, 'yodise', [T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL]);
					}

					$stack .= $text;

					if (!$touched_variable && T_VARIABLE == $id) {
						$touched_variable = true;
					}

					if (
						!$this->right_token_subset_is_at_idx(
							$tkns,
							$ptr,
							[T_STRING, T_VARIABLE, T_NS_SEPARATOR, T_OBJECT_OPERATOR, T_DOUBLE_COLON, ST_CURLY_OPEN, ST_PARENTHESES_OPEN, ST_BRACKET_OPEN]
						)
					) {
						break;
					}
				}
				if (substr(trim($stack), -1, 1) == ST_PARENTHESES_CLOSE) {
					$tkns[$initial_index] = [self::CHAIN_FUNC, $stack];
				} elseif ($touched_variable) {
					$tkns[$initial_index] = [self::CHAIN_VARIABLE, $stack];
				} else {
					$tkns[$initial_index] = [self::CHAIN_LITERAL, $stack];
				}
			}
		}
		$tkns = array_values(array_filter($tkns));
		return $tkns;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_description() {
		return 'Execute Yoda Comparisons.';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function get_example() {
		return <<<'EOT'
<?php
if($a == 1){

}
?>
to
<?php
if(1 == $a){

}
?>
EOT;
	}
};

function extract_from_argv($argv, $item) {
	return array_values(
		array_filter($argv,
			function ($v) use ($item) {
				return substr($v, 0, strlen('--' . $item)) !== '--' . $item;
			}
		)
	);
}

function extract_from_argv_short($argv, $item) {
	return array_values(
		array_filter($argv,
			function ($v) use ($item) {
				return substr($v, 0, strlen('-' . $item)) !== '-' . $item;
			}
		)
	);
}
if (!isset($in_phar)) {
	$in_phar = false;
}
if (!isset($testEnv)) {
	function show_help($argv, $enable_cache, $in_phar) {
		echo 'Usage: ' . $argv[0] . ' [-ho] [--config=FILENAME] ' . ($enable_cache ? '[--cache[=FILENAME]] ' : '') . '[--setters_and_getters=type] [--constructor=type] [--psr] [--psr1] [--psr1-naming] [--psr2] [--indent_with_space=SIZE] [--enable_auto_align] [--visibility_order] <target>', PHP_EOL;
		$options = [
			'--cache[=FILENAME]' => 'cache file. Default: ',
			'--cakephp' => 'Apply CakePHP coding style',
			'--config=FILENAME' => 'configuration file. Default: .php.tools.ini',
			'--constructor=type' => 'analyse classes for attributes and generate constructor - camel, snake, golang',
			'--enable_auto_align' => 'disable auto align of ST_EQUAL and T_DOUBLE_ARROW',
			'--ignore=PATTERN1,PATTERN2' => 'ignore file names whose names contain any PATTERN-N',
			'--indent_with_space=SIZE' => 'use spaces instead of tabs for indentation. Default 4',
			'--laravel' => 'Apply Laravel coding style',
			'--list' => 'list possible transformations',
			'--no-backup' => 'no backup file (original.php~)',
			'--passes=pass1,passN' => 'call specific compiler pass',
			'--prepasses=pass1,passN' => 'call specific compiler pass, before the rest of stack',
			'--profile=NAME' => 'use one of profiles present in configuration file',
			'--psr' => 'activate PSR1 and PSR2 styles',
			'--psr1' => 'activate PSR1 style',
			'--psr1-naming' => 'activate PSR1 style - Section 3 and 4.3 - Class and method names case.',
			'--psr2' => 'activate PSR2 style',
			'--setters_and_getters=type' => 'analyse classes for attributes and generate setters and getters - camel, snake, golang',
			'--smart_linebreak_after_curly' => 'convert multistatement blocks into multiline blocks',
			'--visibility_order' => 'fixes visibiliy order for method in classes. PSR-2 4.2',
			'--yoda' => 'yoda-style comparisons',
			'-h, --help' => 'this help message',
			'-o=file' => 'output the formatted code to "file"',
		];
		if ($in_phar) {
			$options['--selfupdate'] = 'self-update fmt.phar from Github';
		}
		if (!$enable_cache) {
			unset($options['--cache[=FILENAME]']);
		} else {
			$options['--cache[=FILENAME]'] .= (Cache::DEFAULT_CACHE_FILENAME);
		}
		ksort($options);
		$maxLen = max(array_map(function ($v) {
			return strlen($v);
		}, array_keys($options)));
		foreach ($options as $k => $v) {
			echo '  ', str_pad($k, $maxLen), '  ', $v, PHP_EOL;
		}
		echo PHP_EOL, 'If - is blank, it reads from stdin', PHP_EOL;
		die();
	}
	$getopt_long_options = [
		'cache::',
		'cakephp',
		'config:',
		'constructor:',
		'enable_auto_align',
		'help',
		'help-pass:',
		'ignore:',
		'indent_with_space::',
		'laravel',
		'list',
		'no-backup',
		'oracleDB::',
		'passes:',
		'prepasses:',
		'profile:',
		'psr',
		'psr1',
		'psr1-naming',
		'psr2',
		'setters_and_getters:',
		'smart_linebreak_after_curly',
		'visibility_order',
		'yoda',
	];
	if ($in_phar) {
		$getopt_long_options[] = 'selfupdate';
	}
	if (!$enable_cache) {
		unset($getopt_long_options['cache::']);
	}
	$opts = getopt(
		'iho:',
		$getopt_long_options
	);
	if (isset($opts['selfupdate'])) {
		$opts = [
			'http' => [
				'method' => "GET",
				'header' => "User-agent: php.tools fmt.phar selfupdate\r\n",
			],
		];

		$context = stream_context_create($opts);

		// current release
		$releases = json_decode(file_get_contents('https://api.github.com/repos/dericofilho/php.tools/tags', false, $context), true);
		$commit = json_decode(file_get_contents($releases[0]['commit']['url'], false, $context), true);
		$files = json_decode(file_get_contents($commit['commit']['tree']['url'], false, $context), true);
		foreach ($files['tree'] as $file) {
			if ('fmt.phar' == $file['path']) {
				$phar_file = base64_decode(json_decode(file_get_contents($file['url'], false, $context), true)['content']);
			}
			if ('fmt.phar.sha1' == $file['path']) {
				$phar_sha1 = base64_decode(json_decode(file_get_contents($file['url'], false, $context), true)['content']);
			}
		}
		if (!isset($phar_sha1) || !isset($phar_file)) {
			fwrite(STDERR, 'Could not autoupdate - not release found' . PHP_EOL);
			exit(255);
		}
		if ($in_phar) {
			$argv[0] = dirname(Phar::running(false)) . DIRECTORY_SEPARATOR . $argv[0];
		}
		if (sha1_file($argv[0]) != $phar_sha1) {
			copy($argv[0], $argv[0] . "~");
			file_put_contents($argv[0], $phar_file);
			chmod($argv[0], 0777 & ~umask());
			fwrite(STDERR, 'Updated successfully' . PHP_EOL);
		} else {
			fwrite(STDERR, 'Up-to-date!' . PHP_EOL);
		}
		exit(0);
	}
	if (isset($opts['config'])) {
		$argv = extract_from_argv($argv, 'config');
		if (!file_exists($opts['config']) || !is_file($opts['config'])) {
			fwrite(STDERR, 'Custom configuration not file found' . PHP_EOL);
			exit(255);
		}
		$ini_opts = parse_ini_file($opts['config'], true);
		if (!empty($ini_opts)) {
			$opts = $ini_opts;
		}
	} elseif (file_exists('.php.tools.ini') && is_file('.php.tools.ini')) {
		fwrite(STDERR, 'Configuration file found' . PHP_EOL);
		$ini_opts = parse_ini_file('.php.tools.ini', true);
		if (isset($opts['profile'])) {
			$argv = extract_from_argv($argv, 'profile');
			$profile = &$ini_opts[$opts['profile']];
			if (isset($profile)) {
				$ini_opts = $profile;
			}
		}
		$opts = array_merge($ini_opts, $opts);
	}
	if (isset($opts['h']) || isset($opts['help'])) {
		show_help($argv, $enable_cache, $in_phar);
	}

	if (isset($opts['help-pass'])) {
		$optPass = $opts['help-pass'];
		if (class_exists($optPass)) {
			$pass = new $optPass();
			echo $argv[0], ': "', $optPass, '" - ', $pass->get_description(), PHP_EOL, PHP_EOL;
			echo 'Example:', PHP_EOL, $pass->get_example(), PHP_EOL;
		}
		die();
	}

	if (isset($opts['list'])) {
		echo 'Usage: ', $argv[0], ' --help-pass=PASSNAME', PHP_EOL;
		$classes = get_declared_classes();
		foreach ($classes as $class_name) {
			if (is_subclass_of($class_name, 'AdditionalPass')) {
				echo "\t- ", $class_name, PHP_EOL;
			}
		}
		die();
	}

	$cache = null;
	$cache_fn = null;
	if ($enable_cache && isset($opts['cache'])) {
		$argv = extract_from_argv($argv, 'cache');
		$cache_fn = $opts['cache'];
		$cache = new Cache($cache_fn);
		fwrite(STDERR, 'Using cache ...' . PHP_EOL);
	}
	$backup = true;
	if (isset($opts['no-backup'])) {
		$argv = extract_from_argv($argv, 'no-backup');
		$backup = false;
	}

	$ignore_list = null;
	if (isset($opts['ignore'])) {
		$argv = extract_from_argv($argv, 'ignore');
		$ignore_list = array_map(function ($v) {
			return trim($v);
		}, explode(',', $opts['ignore']));
	}

	$fmt = new CodeFormatter();
	if (isset($opts['prepasses'])) {
		$optPasses = array_map(function ($v) {
			return trim($v);
		}, explode(',', $opts['prepasses']));
		foreach ($optPasses as $optPass) {
			if (class_exists($optPass)) {
				$fmt->addPass(new $optPass());
			} elseif (is_file('Additionals/' . $optPass . '.php')) {
				include 'Additionals/' . $optPass . '.php';
				$fmt->addPass(new $optPass());
			}
		}
		$argv = extract_from_argv($argv, 'prepasses');
	}
	$fmt->addPass(new TwoCommandsInSameLine());
	$fmt->addPass(new RemoveIncludeParentheses());
	$fmt->addPass(new NormalizeIsNotEquals());
	if (isset($opts['setters_and_getters'])) {
		$argv = extract_from_argv($argv, 'setters_and_getters');
		$fmt->addPass(new SettersAndGettersPass($opts['setters_and_getters']));
	}
	if (isset($opts['constructor'])) {
		$argv = extract_from_argv($argv, 'constructor');
		$fmt->addPass(new ConstructorPass($opts['constructor']));
	}
	if (isset($opts['oracleDB'])) {
		$argv = extract_from_argv($argv, 'oracleDB');
		$fmt->addPass(new AutoImportPass($opts['oracleDB']));
	}

	$fmt->addPass(new OrderUseClauses());
	$fmt->addPass(new AddMissingCurlyBraces());
	if (isset($opts['smart_linebreak_after_curly'])) {
		$fmt->addPass(new SmartLnAfterCurlyOpen());
		$argv = extract_from_argv($argv, 'smart_linebreak_after_curly');
	}
	$fmt->addPass(new ExtraCommaInArray());
	$fmt->addPass(new NormalizeLnAndLtrimLines());
	$fmt->addPass(new MergeParenCloseWithCurlyOpen());
	$fmt->addPass(new MergeCurlyCloseAndDoWhile());
	$fmt->addPass(new MergeDoubleArrowAndArray());

	if (isset($opts['yoda'])) {
		$fmt->addPass(new YodaComparisons());
		$argv = extract_from_argv($argv, 'yoda');
	}

	$fmt->addPass(new ResizeSpaces());
	$fmt->addPass(new ReindentColonBlocks());
	$fmt->addPass(new ReindentLoopColonBlocks());
	$fmt->addPass(new ReindentIfColonBlocks());

	if (isset($opts['enable_auto_align'])) {
		$fmt->addPass(new AlignEquals());
		$fmt->addPass(new AlignDoubleArrow());
		$argv = extract_from_argv($argv, 'enable_auto_align');
	}

	$fmt->addPass(new ReindentObjOps());
	$fmt->addPass(new Reindent());
	$fmt->addPass(new EliminateDuplicatedEmptyLines());

	if (isset($opts['indent_with_space'])) {
		$fmt->addPass(new PSR2IndentWithSpace($opts['indent_with_space']));
		$argv = extract_from_argv($argv, 'indent_with_space');
	}
	if (isset($opts['psr'])) {
		PsrDecorator::decorate($fmt);
		$argv = extract_from_argv($argv, 'psr');
	}
	if (isset($opts['psr1'])) {
		PsrDecorator::PSR1($fmt);
		$argv = extract_from_argv($argv, 'psr1');
	}
	if (isset($opts['psr1-naming'])) {
		PsrDecorator::PSR1_naming($fmt);
		$argv = extract_from_argv($argv, 'psr1-naming');
	}
	if (isset($opts['psr2'])) {
		PsrDecorator::PSR2($fmt);
		$argv = extract_from_argv($argv, 'psr2');
	}
	if ((isset($opts['psr1']) || isset($opts['psr2']) || isset($opts['psr'])) && isset($opts['enable_auto_align'])) {
		$fmt->addPass(new PSR2AlignObjOp());
	}

	if (isset($opts['visibility_order'])) {
		$fmt->addPass(new PSR2ModifierVisibilityStaticOrder());
		$argv = extract_from_argv($argv, 'visibility_order');
	}
	$fmt->addPass(new LeftAlignComment());
	$fmt->addPass(new RTrim());

	if (isset($opts['passes'])) {
		$optPasses = array_map(function ($v) {
			return trim($v);
		}, explode(',', $opts['passes']));
		foreach ($optPasses as $optPass) {
			if (class_exists($optPass)) {
				$fmt->addPass(new $optPass());
			} elseif (is_file('Additionals/' . $optPass . '.php')) {
				include 'Additionals/' . $optPass . '.php';
				$fmt->addPass(new $optPass());
			}
		}
		$argv = extract_from_argv($argv, 'passes');
	}

	if (isset($opts['laravel'])) {
		$fmt->addPass(new LaravelStyle());
		$argv = extract_from_argv($argv, 'laravel');
	}

	if (isset($opts['cakephp'])) {
		$fmt->addPass(new CakePHPStyle());
		$argv = extract_from_argv($argv, 'cakephp');
	}

	if (isset($opts['i'])) {
		echo 'php.tools fmt.php interactive mode.', PHP_EOL;
		echo 'no <?php is necessary', PHP_EOL;
		echo 'type a lone "." to finish input.', PHP_EOL;
		echo 'type "quit" to finish.', PHP_EOL;
		while (true) {
			$str = '';
			do {
				$line = readline('> ');
				$str .= $line;
			} while (!('.' == $line || 'quit' == $line));
			if ('quit' == $line) {
				exit(0);
			}
			readline_add_history(substr($str, 0, -1));
			echo $fmt->formatCode('<?php ' . substr($str, 0, -1)), PHP_EOL;
		}
	} elseif (isset($opts['o'])) {
		$argv = extract_from_argv_short($argv, 'o');
		if ('-' == $opts['o'] && '-' == $argv[1]) {
			echo $fmt->formatCode(file_get_contents('php://stdin'));
			exit(0);
		}
		if ($in_phar) {
			$argv[1] = dirname(Phar::running(false)) . DIRECTORY_SEPARATOR . $argv[1];
		}
		if ('-' == $opts['o']) {
			echo $fmt->formatCode(file_get_contents($argv[1]));
			exit(0);
		}
		if (!is_file($argv[1])) {
			fwrite(STDERR, "File not found: " . $argv[1] . PHP_EOL);
			exit(255);
		}
		$argv = array_values($argv);
		file_put_contents($opts['o'], $fmt->formatCode(file_get_contents($argv[1])));
	} elseif (isset($argv[1])) {
		if ('-' == $argv[1]) {
			echo $fmt->formatCode(file_get_contents('php://stdin'));
			exit(0);
		}
		$file_not_found = false;
		$start = microtime(true);
		fwrite(STDERR, 'Formatting ...' . PHP_EOL);
		$missing_files = [];
		$file_count = 0;

		$cache_hit_count = 0;
		$workers = 4;

		for ($j = 1; $j < $argc; ++$j) {
			$arg = &$argv[$j];
			if (!isset($arg)) {
				continue;
			}
			if ($in_phar) {
				$arg = dirname(Phar::running(false)) . DIRECTORY_SEPARATOR . $arg;
			}
			if (is_file($arg)) {
				$file = $arg;
				++$file_count;
				fwrite(STDERR, '.');
				file_put_contents($file . '-tmp', $fmt->formatCode(file_get_contents($file)));
				rename($file . '-tmp', $file);
			} elseif (is_dir($arg)) {
				fwrite(STDERR, $arg . PHP_EOL);
				$target_dir = $arg;
				$dir = new RecursiveDirectoryIterator($target_dir);
				$it = new RecursiveIteratorIterator($dir);
				$files = new RegexIterator($it, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

				if ($concurrent) {

					$chn = make_channel();
					$chn_done = make_channel();
					if ($concurrent) {
						fwrite(STDERR, 'Starting ' . $workers . ' workers ...' . PHP_EOL);
					}
					for ($i = 0; $i < $workers; ++$i) {
						cofunc(function ($fmt, $backup, $cache_fn, $chn, $chn_done, $id) {
							$cache = null;
							if (null !== $cache_fn) {
								$cache = new Cache($cache_fn);
							}
							$cache_hit_count = 0;
							$cache_miss_count = 0;
							while (true) {
								$msg = $chn->out();
								if (null === $msg) {
									break;
								}
								$target_dir = $msg['target_dir'];
								$file = $msg['file'];
								if (empty($file)) {
									continue;
								}
								if (null !== $cache) {
									$content = $cache->is_changed($target_dir, $file);
									if (!$content) {
										++$cache_hit_count;
										continue;
									}
								} else {
									$content = file_get_contents($file);
								}
								++$cache_miss_count;
								$fmtCode = $fmt->formatCode($content);
								if (null !== $cache) {
									$cache->upsert($target_dir, $file, $fmtCode);
								}
								file_put_contents($file . '-tmp', $fmtCode);
								$backup && rename($file, $file . '~');
								rename($file . '-tmp', $file);
							}
							$chn_done->in([$cache_hit_count, $cache_miss_count]);
						}, $fmt, $backup, $cache_fn, $chn, $chn_done, $i);
					}
				}
				foreach ($files as $file) {
					$file = $file[0];
					if (null !== $ignore_list) {
						foreach ($ignore_list as $pattern) {
							if (false !== strpos($file, $pattern)) {
								continue 2;
							}
						}
					}

					++$file_count;
					if ($concurrent) {
						$chn->in([
							'target_dir' => $target_dir,
							'file' => $file,
						]);
					} else {
						if (0 == ($file_count % 20)) {
							fwrite(STDERR, ' ' . $file_count . PHP_EOL);
						}
						if (null !== $cache) {
							$content = $cache->is_changed($target_dir, $file);
							if (!$content) {
								++$file_count;
								++$cache_hit_count;
								continue;
							}
						} else {
							$content = file_get_contents($file);
						}
						$fmtCode = $fmt->formatCode($content);
						fwrite(STDERR, '.');
						if (null !== $cache) {
							$cache->upsert($target_dir, $file, $fmtCode);
						}
						file_put_contents($file . '-tmp', $fmtCode);
						$backup && rename($file, $file . '~');
						rename($file . '-tmp', $file);
					}

				}
				if ($concurrent) {
					for ($i = 0; $i < $workers; ++$i) {
						$chn->in(null);
					}
					for ($i = 0; $i < $workers; ++$i) {
						list($cache_hit, $cache_miss) = $chn_done->out();
						$cache_hit_count += $cache_hit;
					}
					$chn_done->close();
					$chn->close();
				}
				continue;
			} elseif (!is_file($arg)) {
				$file_not_found = true;
				$missing_files[] = $arg;
				fwrite(STDERR, '!');
			}
			if (0 == ($file_count % 20)) {
				fwrite(STDERR, ' ' . $file_count . PHP_EOL);
			}
		}
		fwrite(STDERR, PHP_EOL);
		if (null !== $cache) {
			fwrite(STDERR, ' ' . $cache_hit_count . ' files untouched (cache hit)' . PHP_EOL);
		}
		fwrite(STDERR, ' ' . $file_count . ' files total' . PHP_EOL);
		fwrite(STDERR, 'Took ' . round(microtime(true) - $start, 2) . 's' . PHP_EOL);
		if (sizeof($missing_files)) {
			fwrite(STDERR, "Files not found: " . PHP_EOL);
			foreach ($missing_files as $file) {
				fwrite(STDERR, "\t - " . $file . PHP_EOL);
			}
		}

		if ($file_not_found) {
			exit(255);
		}
	} else {
		show_help($argv, $enable_cache, $in_phar);
	}
	exit(0);
}
