<?php


require_once 'html.php';
require_once 'jsmin.php';
require_once 'cssp.php';

class PHP_Obfuscator
{
    private $tokens;
    private $curr_pos = 0;

    private $curr_func_name;
    private $curr_class_name;

    const ST_IN_CLASS = 1;
    const ST_IN_FUNCTION = 2;
    const ST_IN_STRING = 3;

    public static $RESERVED = array(
        '$this', '$_GET', '$_POST', '$_SESSION', '$_SERVER',
        '$GLOBALS', '$_FILES', '$_REQUEST', '$_ENV', '$_COOKIE',
        '$php_errormsg', '$HTTP_RAW_POST_DATA', '$http_response_header', '$argc', '$argv',
    );

    private $reserved_keywords = array();

    public function __construct($source)
    {
        $this->tokens = token_get_all($source);
    }

    public function set_reserved_keywords($keywords)
    {
        $this->reserved_keywords = (array) $keywords;
    }

    public function is_reserved($keyword)
    {
        return in_array($keyword, self::$RESERVED) || in_array($keyword, $this->reserved_keywords);
    }

    public function curr_token()
    {
        return (count($this->tokens) > $this->curr_pos) ? $this->tokens[$this->curr_pos] : null;
    }

    public function move_back($step = 1)
    {
        $this->curr_pos -= $step;

        return $this->curr_pos >= 0;
    }

    public function move_on($step = 1)
    {
        $this->curr_pos += $step;

        return $this->curr_pos < count($this->tokens);
    }

    public function skip_to()
    {
        $matches = func_get_args();
        if (count($matches) > 0) {
            while ($token = $this->curr_token()) {
                if (is_array($token) && in_array($token[0], $matches)) {
                    return $token;
                } elseif (in_array($token, $matches)) {
                    return $token;
                }
                $this->move_on();
            };
        }
    }

    public function parse()
    {
        while ($token = $this->curr_token()) {
            $pos = $this->curr_pos;
            $state = $this->curr_state();
            switch ($state) {
            case self::ST_IN_CLASS:
                $this->parse_in_class($token);
                break;
            case self::ST_IN_FUNCTION:
                $this->parse_in_function($token);
                break;
            case self::ST_IN_STRING:
                $this->parse_in_string($token);
                break;
            default:
                $this->parse_in_global($token);
            }
            $this->move_on();
        }
    }

    public function parse_in_global($token)
    {
        if (is_array($token)) {
            switch ($token[0]) {
            case T_CLASS:
            case T_INTERFACE:
                $this->parse_class($token);
                break;
            case T_FUNCTION:
                $this->parse_function($token);
                break;
            case T_VARIABLE:
                $this->add_var_pos($token[1], $this->curr_pos);
                break;
            }
        } else {
            $this->parse_plain($token);
        }
    }

    public function extract_vars($pos_from, $pos_to, $offset = 0)
    {
        for ($pos = $pos_from; $pos < $pos_to; ++$pos) {
            $ptoken = $this->tokens[$pos];
            if ($ptoken[0] === T_VARIABLE) {
                $this->add_var_pos($ptoken[1], $pos, $offset);
            }
        }
    }

    public function parse_plain($token)
    {
        $state = $this->curr_state();
        if ($state === self::ST_IN_STRING) {
            if ($token === '"') {
                $this->pop_state();
                //$this->tokens[$this->curr_pos] = array(0=>0, 1=>'"', 2=>0, 3=>end($this->states));
            }
        } else {
            switch ($token) {
            case '"':
                $this->push_state(self::ST_IN_STRING);
                //$this->tokens[$this->curr_pos] = array(0=>0, 1=>'"', 2=>0, 3=>end($this->states));
                break;
            case '{':
                $this->push_state();
                //$this->tokens[$this->curr_pos] = array(0=>0, 1=>'{', 2=>0, 3=>end($this->states));
                break;
            case '}':
                $this->pop_state();
                //$this->tokens[$this->curr_pos] = array(0=>0, 1=>'}', 2=>0, 3=>end($this->states));
                break;
            }
        }
    }

    public function parse_class($token)
    {
        $token = $this->skip_to(T_STRING);
        $this->curr_class_name = $token[1];
        $this->skip_to('{');
        $this->push_state(self::ST_IN_CLASS);
        $this->curr_class_var_list = array();
    }

    public function parse_in_class($token)
    {
        if (is_array($token)) {
            switch ($token[0]) {
            case T_FUNCTION:
                $this->parse_function($token);
                break;
            case T_VARIABLE:
                $this->parse_class_decl($token);
                break;
            }
        } else {
            $this->parse_plain($token);
        }
    }

    public function parse_class_decl($token)
    {
        $pos = $this->curr_pos;
        --$pos;
        while ($pos > 0) {
            $t = $this->tokens[$pos];
            if (!is_array($t)) {
                break;
            }
            /*
            // 不能修改PRIVATE 因为程序有可能跨类调用PRIVATE变量
            if ($t[0] === T_PRIVATE) {
                $this->add_var_pos($token[1], $this->curr_pos);
            }*/
            --$pos;
        }
    }

    public function parse_function($token)
    {
        $token = $this->skip_to(T_STRING, '(');
        $this->push_state(self::ST_IN_FUNCTION);

        if ($token === '(') {
            $this->curr_func_name = 'lamda_'.uniqid();
            $this->move_back(1);
        } else {
            $this->curr_func_name = $token[1];
        }
        //$this->tokens[$this->curr_pos][3] = end($this->states);

        $pos_from = $this->curr_pos;
        $token = $this->skip_to(T_USE, '{', ';');
        $pos_to = $this->curr_pos;
        $this->extract_vars($pos_from, $pos_to);

        if ($token[0] == T_USE) {
            $use_from = $this->curr_pos;
            $this->skip_to('{');
            $use_to = $this->curr_pos;
            $this->extract_vars($use_from, $use_to, -1);
        } elseif ($token == ';') {
            $this->pop_state();
        }
    }

    public function parent_token()
    {
        $pos = $this->curr_pos;

        $type = $this->tokens[$pos][0];

        --$pos;
        $fetch_object = false;
        $fetch_static = false;
        while ($pos > 0) {
            $token = $this->tokens[$pos];
            if (!is_array($token)) {
                return;
            }
            switch ($token[0]) {
            case T_WHITESPACE:
                break;
            case T_OBJECT_OPERATOR:
                $fetch_object = true;
                break;
            case T_DOUBLE_COLON:
                $fetch_static = true;
                break;
            case T_VARIABLE:
            case T_STRING:
                if ($fetch_static || ($fetch_object && $type == T_STRING)) {
                    return $token[1];
                }
                break;
            case T_STATIC:
                if ($fetch_static) {
                    return 'static';
                }
            default:
                return;
            }
            --$pos;
        }
    }

    public function parse_in_function($token)
    {
        if (is_array($token)) {
            //echo token_name($token[0]).':'.$token[1]."\n";
            switch ($token[0]) {
            case T_FUNCTION:
                $this->parse_function($token);
                break;
            case T_VARIABLE:
                $var_name = $token[1];
                if (!$this->is_reserved($var_name)) {
                    $parent = $this->parent_token();
                    if ($parent) {
                        if ($parent == 'static' || $parent == 'self' || $parent == $this->curr_class_name) {
                            $this->curr_class_var_list[$var_name][] = $this->curr_pos;
                        }
                    } else {
                        $this->add_var_pos($var_name, $this->curr_pos);
                    }
                }
                break;
            case T_STRING:
                $parent = $this->parent_token();
                if ($parent === '$this') {
                    $pos = $this->curr_pos + 1;
                    $count = count($this->tokens);
                    while ($pos < $count) {
                        $t = $this->tokens[$pos];
                        ++$pos;
                        if (is_array($t) && $t[0] === T_WHITESPACE) {
                            continue;
                        }
                        if ($t !== '(') {
                            $var_name = '$'.$token[1];
                            $this->curr_class_var_list[$var_name][] = $this->curr_pos;
                            break;
                        }
                    }
                }
                break;
            }
        } else {
            $this->parse_plain($token);
        }
    }

    public function parse_in_string($token)
    {
        if (is_array($token)) {
            switch ($token[0]) {
            case T_VARIABLE:
                $var_name = $token[1];
                if (!$this->is_reserved($var_name)) {
                    $parent = $this->parent_token();
                    if ($parent) {
                        if ($parent == 'static' || $parent == 'self' || $parent == $this->curr_class_name) {
                            $this->curr_class_var_list[$var_name][] = $this->curr_pos;
                        }
                    } else {
                        $this->add_var_pos($var_name, $this->curr_pos);
                    }
                }
                break;
            case T_STRING_VARNAME:
                $var_name = $token[1];
                if (!$this->is_reserved($var_name)) {
                    $parent = $this->parent_token();
                    if ($parent) {
                        if ($parent == 'static' || $parent == 'self' || $parent == $this->curr_class_name) {
                            $this->curr_class_var_list[$var_name][] = $this->curr_pos;
                        }
                    } else {
                        $this->add_var_pos($var_name, $this->curr_pos);
                    }
                }
                break;
            case T_STRING:
                $parent = $this->parent_token();
                if ($parent === '$this') {
                    $pos = $this->curr_pos + 1;
                    $count = count($this->tokens);
                    while ($pos < $count) {
                        $t = $this->tokens[$pos];
                        ++$pos;
                        if (is_array($t) && $t[0] === T_WHITESPACE) {
                            continue;
                        }
                        if ($t !== '(') {
                            $var_name = '$'.$token[1];
                            $this->curr_class_var_list[$var_name][] = $this->curr_pos;
                            break;
                        }
                    }
                }
                break;
            }
        } else {
            $this->parse_plain($token);
        }
    }

    private $states = array(array(0, 0));
    public function push_state($state = null)
    {
        $old_state = &$this->states[count($this->states) - 1];
        if ($state === null) {
            ++$old_state[1];
        } else {
            if ($state != self::ST_IN_STRING) {
                $this->push_var_list();
            }
            array_push($this->states, array($state, 0));
        }
    }

    public function pop_state()
    {
        $state = &$this->states[count($this->states) - 1];
        if ($state[1] > 0) {
            --$state[1];

            return $state[0];
        }

        array_pop($this->states);
        if ($state[0] != self::ST_IN_STRING) {
            switch ($state[0]) {
            case self::ST_IN_CLASS:
                $this->curr_class_name = '';
                $var_list = $this->curr_var_list();
                foreach ((array) $this->curr_class_var_list as $key => $vars) {
                    if (isset($var_list[$key])) {
                        foreach ($vars as $var) {
                            $var_list[$key][] = $var;
                        }
                    }
                }
                $this->curr_class_var_list = null;
                break;
            }
            $this->pop_var_list();
        }

        return $state[0];
    }

    public function curr_state()
    {
        $state = end($this->states);

        return $state[0];
    }

    private $var_lists = array();
    private $parsed_var_lists = array();

    public function push_var_list()
    {
        $this->var_lists[] = new ArrayIterator();
    }

    public function pop_var_list()
    {
        if (count($this->var_lists) > 0) {
            $var_list = array_pop($this->var_lists);
            $this->parsed_var_lists[] = $var_list;
        }
    }

    public function curr_var_list()
    {
        if (count($this->var_lists) == 0) {
            $this->var_lists[] = new ArrayIterator();
        }

        return end($this->var_lists);
    }

    public function add_var_pos($var_name, $pos, $offset = 0)
    {
        if (count($this->var_lists) == 0) {
            $this->var_lists[] = new ArrayIterator();
        }

        if ($offset > 0) {
            return;
        }

        $end_level = count($this->var_lists) - 1;
        $level = $end_level + $offset;
        $var_list = $this->var_lists[$level];

        if (!isset($var_list[$var_name])) {
            $var_list[$var_name] = new ArrayIterator();
            if ($level <= 0) {
                $var_list[$var_name]->root_level = true;
            }
        }

        $var_list[$var_name][] = $pos;

        if ($offset != 0) {
            $end_var_list = end($this->var_lists);
            $end_var_list[$var_name] = $var_list[$var_name];
        }
    }

    private $php_tokens = array();
    private function clear_php_tokens()
    {
        $this->php_tokens = array();
    }
    private function php_token($str)
    {
        $token = '__OBB_'.uniqid().'_OBE__';
        $this->php_tokens[$token] = trim($str);

        return $token;
    }
    private function convert_php_tokens($str)
    {
        return strtr($str, $this->php_tokens);
    }

    public static function minify_js($js)
    {
        //$packer = new JSPacker($js);
        //return $packer->pack();
        echo "-----------\n$js---------\n";

        return JSMin::minify($js);
    }

    public static function minify_css($css)
    {
        return CSSP::fragment($css)->format(CSSP::FORMAT_NOCOMMENTS | CSSP::FORMAT_MINIFY);
    }

    public function format()
    {
        $this->parse();

        //转换所有变量
        $this->uniqid = 0;
        foreach ($this->parsed_var_lists as $var_list) {
            foreach ($var_list as $var_name => $positions) {
                if (isset($positions->root_level) && $positions->root_level) {
                    continue;
                }
                $new_var_name = $this->random_name();
                foreach ($positions as $pos) {
                    $old_name = $this->tokens[$pos][1];
                    $this->tokens[$pos][1] = ($old_name[0] === '$' ? '$' : '').$new_var_name;
                }
            }
        }

        $output = '';
        $php_code = '';
        $has_html = false;
        $this->clear_php_tokens();
        foreach ($this->tokens as $token) {
            if (is_array($token)) {
                $keep_prev_token = false;
                //T_CONSTANT_ENCAPSED_STRING
                //T_ENCAPSED_AND_WHITESPACE
                switch ($token[0]) {
                /*
                case T_CONSTANT_ENCAPSED_STRING:
                    $output .= $this->encode_quoted_string($token[1]);
                    break;
                case T_ENCAPSED_AND_WHITESPACE:
                    $output .= $this->encode_string(stripcslashes($token[1]));
                    break;*/
                case T_INLINE_HTML:
                    if ($php_code) {
                        $output .= $this->php_token($php_code);
                        $php_code = '';
                    }
                    $output .= $token[1];
                    $has_html = true;
                    break;
                case T_OPEN_TAG:
                    $php_code .= '<?php ';
                    break;
                case T_CLOSE_TAG:
                    $php_code .= '?>';
                    $php_code = preg_replace('|<\?php\s*\?>$|', '', $php_code);
                    break;
                case T_WHITESPACE:
                    if ($prev_token != T_OPEN_TAG) {
                        $php_code .= ' ';
                    }
                    $keep_prev_token = true;
                    break;
                case T_DOC_COMMENT:
                case T_COMMENT:
                    $keep_prev_token = true;
                    break;
                default:
                    $php_code .= $token[1];
                    // if (isset($token[3])) $php_code .=json_encode($token[3]);
                }
                if (!$keep_prev_token) {
                    $prev_token = $token[0];
                }
            } else {
                $php_code .= $token;
                $prev_token = null;
            }
        }

        if ($php_code && !preg_match('/^\s*<\?php\s*$/', $php_code)) {
            $output .= $this->php_token($php_code);
            $php_code = '';
        }

        //处理html tidy
        if ($has_html) {
            $output = Minify_HTML::minify($output, array(
                'xhtml' => true,
                'cssMinifier' => 'PHP_Obfuscator::minify_css',
                'jsMinifier' => 'PHP_Obfuscator::minify_js',
            ));
        }

        $output = $this->convert_php_tokens($output);

        return $output;
    }

    public function encode_string($str)
    {
        return $str;

        $len = strlen($str);
        $ns = '';
        for ($i = 0; $i < $len; ++$i) {
            $ns .= '\x'.sprintf('%02x', ord($str[$i]));
        }

        return $ns;
    }

    public function encode_quoted_string($str)
    {
        return $str;

        $quote = $str[0];
        $str = preg_replace('/^([\'"])(.+)\1$/', '$2', $str);
        if ($quote === '"') {
            $str = stripcslashes($str);
        }

        return '"'.$this->encode_string($str).'"';
    }

    private $uniqid = 0;
    public function random_name()
    {
        static $legal_chars = 'abcdefghijklmnopqrstuvwxyz';

        $key = '';
        $n = $this->uniqid;
        $max = strlen($legal_chars);
        do {
            $p = $n % $max;
            $n = floor($n / $max);
            $key .= $legal_chars[$p];
        } while ($n > 0);

        //$key = str_repeat('O', $this->uniqid + 1);

        ++$this->uniqid;

        return $key;
    }
}
