<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2009 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class Xss_Security_Test extends Unit_Test_Case {
  public function find_unescaped_variables_in_views_test() {
    $found = array();
    foreach (glob("*/*/views/*.php") as $view) {
      // List of all tokens without whitespace, simplifying parsing.
      $tokens = array();
      foreach (token_get_all(file_get_contents($view)) as $token) {
	if (!is_array($token) || ($token[0] != T_WHITESPACE)) {
	  $tokens[] = $token;
	}
      }

      $frame  = null;
      $script_block = 0;
      $in_script_block = false;

      for ($token_number = 0; $token_number < count($tokens); $token_number++) {
	$token = $tokens[$token_number];

	// Are we in a <script> ... </script> block?
	if (is_array($token) && $token[0] == T_INLINE_HTML) {
	  $inline_html = $token[1];
	  // T_INLINE_HTML blocks can be split. Need to handle the case
	  // where one token has "<scr" and the next has "ipt"
	  while (self::_token_matches(array(T_INLINE_HTML), $tokens, $token_number + 1)) {
	    $token_number++;
	    $token = $tokens[$token_number];
	    $inline_html .= $token[1];
	  }

	  if ($frame) {
	    $frame->expr_append($inline_html);
	  }

	  // Note: This approach won't catch <script src="..."> blocks if the src
	  // URL is generated via < ? = url::site() ? > or some other PHP.
	  // Assume that all such script blocks with a src URL have an
	  // empty element body.
	  // But we'll catch closing tags for such blocks, so don't keep track
	  // of opening / closing tag count since it would be meaningless.

	  // Handle multiple start / end blocks on the same line?
	  $opening_script_pos = $closing_script_pos = 0;
	  if (preg_match_all('{</script>}i', $inline_html, $matches, PREG_OFFSET_CAPTURE)) {
	    $last_match = array_pop($matches[0]);
	    if (is_array($last_match)) {
	      $closing_script_pos = $last_match[1];
	    } else {
	      $closing_script_pos = $last_match;
	    }
	  }
	  if (preg_match('{<script\b[^>]*>}i', $inline_html, $matches, PREG_OFFSET_CAPTURE)) {
	    $last_match = array_pop($matches[0]);
	    if (is_array($last_match)) {
	      $opening_script_pos = $last_match[1];
	    } else {
	      $opening_script_pos = $last_match;
	    }
	  }
	  if ($opening_script_pos != $closing_script_pos) {
	    $in_script_block = $opening_script_pos > $closing_script_pos;
	  }
	}

	// Look and report each instance of < ? = ... ? >
	if (!is_array($token)) {
	  // A single char token, e.g: ; ( )
	  if ($frame) {
	    $frame->expr_append($token);
	  }
	} else if ($token[0] == T_OPEN_TAG_WITH_ECHO) {
	  // No need for a stack here - assume < ? = cannot be nested.
	  $frame = self::_create_frame($token, $in_script_block);
        } else if ($frame && $token[0] == T_CLOSE_TAG) {
	  // Store the < ? = ... ? > block that just ended here.
	  $found[$view][] = $frame;
	  $frame = null;
        } else if ($frame && $token[0] == T_VARIABLE) {
	  $frame->expr_append($token[1]);
	} else if ($frame && $token[0] == T_STRING) {
	  $frame->expr_append($token[1]);
	  // t() and t2() are special in that they're guaranteed to return a SafeString().
	  if (in_array($token[1], array("t", "t2"))) {
	    if (self::_token_matches("(", $tokens, $token_number + 1)) {
	      $frame->is_safestring(true);
	      $frame->expr_append("(");

	      $token_number++;
	      $token = $tokens[$token_number];
	    }
	  } else if ($token[1] == "SafeString") {
	    // Looking for SafeString::of(...
	    if (self::_token_matches(array(T_DOUBLE_COLON, "::"), $tokens, $token_number + 1) &&
		self::_token_matches(array(T_STRING, "of"), $tokens, $token_number + 2)	&&
		self::_token_matches("(", $tokens, $token_number + 3)) {
	      $frame->is_safestring(true);
	      $frame->expr_append("::of(");

	      $token_number += 3;
	      $token = $tokens[$token_number];
	    }
	  } else if ($token[1] == "json_encode") {
	    if (self::_token_matches("(", $tokens, $token_number + 1)) {
	      $frame->json_encode_called(true);
	      $frame->expr_append("(");

	      $token_number++;
	      $token = $tokens[$token_number];
	    }
	  }
	} else if ($frame && $token[0] == T_OBJECT_OPERATOR) {
	  $frame->expr_append($token[1]);

	  if (self::_token_matches(array(T_STRING), $tokens, $token_number + 1) &&
	      in_array($tokens[$token_number + 1][1],
		       array("for_js", "for_html", "purified_html")) &&
	      self::_token_matches("(", $tokens, $token_number + 2)) {

	    $method = $tokens[$token_number + 1][1];
	    $frame->expr_append("$method(");

	    $token_number += 2;
	    $token = $tokens[$token_number];

	    if ("for_js" == $method) {
	      $frame->for_js_called(true);
	    } else if ("for_html" == $method) {
	      $frame->for_html_called(true);
	    } else if ("purified_html" == $method) {
	      $frame->purified_html_called(true);
	    }
	  }
        } else if ($frame) {
	  $frame->expr_append($token[1]);
	}
      }
    }

    // Generate the report.
    /*
     * States for uses of < ? = X ? >:
     * JS_XSS:
     *   In <script> block
     *     X can be anything without calling ->for_js()
     * UNKNOWN:
     *   Outside <script> block:
     *     X can be anything without a call to ->for_html() or ->purified_html()
     * CLEAN:
     *   Outside <script> block:
     *     X = t() or t2()
     *     X = * and for_html() or purified_html() is called
     *   Inside <script> block:
     *     X = * with ->for_js() or json_encode(...)
     */
    $new = TMPPATH . "xss_data.txt";
    $fd = fopen($new, "wb");
    ksort($found);
    foreach ($found as $view => $frames) {
      foreach ($frames as $frame) {
	$state = "UNKNOWN";
	if ($frame->in_script_block()) {
	  $state = "JS_XSS";
	  if ($frame->for_js_called() || $frame->json_encode_called()) {
	    $state = "CLEAN";
	  }
	} else {
	  if ($frame->is_safestring() || $frame->purified_html_called() || $frame->for_html_called()) {
	    $state = "CLEAN";
	  }
	}
	fprintf($fd, "%-60s %-3s %-8s %s\n",
		$view, $frame->line(), $state, $frame->expr());
      }
    }
    fclose($fd);
    exit;

    // Compare with the expected report from our golden file.
    $canonical = MODPATH . "gallery/tests/xss_data.txt";
    exec("diff $canonical $new", $output, $return_value);
    $this->assert_false(
      $return_value, "XSS golden file mismatch.  Output:\n" . implode("\n", $output) );
  }

  private static function _create_frame($token, $in_script_block) {
    return new Xss_Security_Test_Frame($token[2], $in_script_block);
  }

  private static function _token_matches($expected_token, &$tokens, $token_number) {
    if (!isset($tokens[$token_number])) {
      return false;
    }

    $token = $tokens[$token_number];

    if (is_array($expected_token)) {
      for ($i = 0; $i < count($expected_token); $i++) {
	if ($expected_token[$i] != $token[$i]) {
	  return false;
	}
      }
      return true;
    } else {
      return $expected_token == $token;
    }
  }
}

class Xss_Security_Test_Frame {
  private $_expr = "";
  private $_in_script_block = false;
  private $_is_safestring = false;
  private $_for_js_called = false;
  private $_for_html_called = false;
  private $_purified_html_called = false;
  private $_json_encode_called = false;
  private $_line;

  function __construct($line_number, $in_script_block) {
    $this->_line = $line_number;
    $this->in_script_block($in_script_block);
  }

  function expr() {
    return $this->_expr;
  }

  function expr_append($append_value) {
    return $this->_expr .= $append_value;
  }

  function in_script_block($new_val=NULL) {
    if ($new_val !== NULL) {
      $this->_in_script_block = (bool) $new_val;
    }
    return $this->_in_script_block;
  }

  function is_safestring($new_val=NULL) {
    if ($new_val !== NULL) {
      $this->_is_safestring = (bool) $new_val;
    }
    return $this->_is_safestring;
  }

  function json_encode_called($new_val=NULL) {
    if ($new_val !== NULL) {
      $this->_json_encode_called = (bool) $new_val;
    }
    return $this->_json_encode_called;
  }

  function for_js_called($new_val=NULL) {
    if ($new_val !== NULL) {
      $this->_for_js_called = (bool) $new_val;
    }
    return $this->_for_js_called;
  }

  function for_html_called($new_val=NULL) {
    if ($new_val !== NULL) {
      $this->_for_html_called = (bool) $new_val;
    }
    return $this->_for_html_called;
  }

  function purified_html_called($new_val=NULL) {
    if ($new_val !== NULL) {
      $this->_purified_html_called = (bool) $new_val;
    }
    return $this->_purified_html_called;
  }

  function line() {
    return $this->_line;
  }
}
