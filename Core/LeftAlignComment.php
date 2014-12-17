<?php
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
