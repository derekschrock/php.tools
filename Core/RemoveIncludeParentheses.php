<?php
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
