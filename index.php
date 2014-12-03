#!/usr/bin/env php
<?php

error_reporting(E_ALL ^ E_NOTICE);

require_once 'php_encoder.php';

class Package {

    public $source;
    public $dest;
    public $quiet;

    function __construct() {

        $shortopts = 's:d:q:';
        $longopts = [
            'source:',
            'dest:',
            'quiet:'
        ];

        $opts = getopt($shortopts, $longopts);

        if (!isset($opts['s'])  && !isset($opts['source']) && !isset($opts['dest']) && !isset($opts['d'])) {
            self::Usage();
        }

        $this->source = $opts['s'] ? : $opts['source'];

        $this->dest = $opts['d'] ? : $opts['dest'];

        $this->quiet = $opts['q'] ? : $opts['quiet'];
    }

    static function Usage() {
        die("usage: php masker.phar -s|--source somewhere -d|--dest anywhere [-q|--quiet=1] \n");
    }

    function run() {

        if ($this->quiet) ob_start();

        echo "目录复制: {$this->source} -> {$this->dest} \n";

        //进行文件copy
        @exec("cp -R {$this->source} {$this->dest}");

        //遍历文件
        self::traverse($this->dest, [$this, 'encode_file']);

        if ($this->quiet) ob_end_clean();
    }

    function encode_file($path) {

        if (is_file($path)) {
            //目录打包开始
            echo "正在编码: $path \n";
            PHP_Encoder::encode($path);
        }
    }

    static function traverse($path, $callback, $params=NULL, $parent=NULL) {
        if (FALSE === call_user_func($callback, $path, $params)) return;
        if (!is_link($path) && is_dir($path)) {
            $path = preg_replace('/[^\/]$/', '$0/', $path);
            $dh = opendir($path);
            if ($dh) {
                $files = [];
                while ($file = readdir($dh)) {
                    if ($file[0] == '.') continue;
                    $files[] = $file;
                }
                sort($files);
                foreach($files as $file) {
                    self::traverse($path.$file, $callback, $params, $path);
                }
                closedir($dh);
            }
        }
    }
}

$p = new Package();
$p->run();
