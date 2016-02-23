<?php

require_once 'php_obfuscator.php';
require_once 'cssp.php';
require_once 'jsmin.php';

define('JS_FORMATTED', '/*JSFORMATED*/');
define('CSS_PATCHED_FLAG', '/*CSS PATCHED*/');

class PHP_Encoder
{
    public static function encode($path)
    {
        if (preg_match('/\.(php|phtml)$/', $path, $matches)) {
            echo "Encoding PHP: $rpath...";
            //移除脚本中的注释和回车
            $content = file_get_contents($path);

            $total = strlen($content);

            // 预编译代码
            if (class_exists('PHP_MCompiler')) {
                $mc = PHP_MCompiler($content);
                $content = $mc->compile($content);
            }

            // 混淆变量
            if (class_exists('PHP_Obfuscator')) {
                $ob = new PHP_Obfuscator($content);
                $ob->set_reserved_keywords(['$config', '$lang']);
                $content = $ob->format();
            }

            $converted = strlen($content);
            echo "$converted / $total";
        } elseif (preg_match('/\.(js)$/', $path)) {
            echo "Compiling JS: $rpath...";
            $content = @file_get_contents($path);
            $total = strlen($content);

            // TODO: 使用closure compiler
            ob_start();
            passthru('java -jar '.dirname(__FILE__).'/compiler.jar --js '.escapeshellarg($path), $ret);
            if ($ret == 0) {
                $content = JS_FORMATTED."\n".ob_get_contents();
                $converted = strlen($content);
            }
            ob_end_clean();

            echo "$converted / $total";
        } elseif (preg_match('/\.(css)$/', $path)) {
            echo "Compiling CSS: $rpath...";
            $content = @file_get_contents($path);
            $total = strlen($content);

            $content = CSS_PATCHED_FLAG."\n"
                .CSSP::fragment($content)->format(CSSP::FORMAT_NOCOMMENTS | CSSP::FORMAT_MINIFY);
            $converted = strlen($content);

            echo "$converted / $total";
        } elseif (preg_match('/\.(cssp)$/', $path)) {
            echo "Compressing CSSP: $rpath...";
            $content = @file_get_contents($path);
            $total = strlen($content);

            $content = preg_replace('/\s+/', ' ', $content);
            $converted = strlen($content);
            echo "$converted / $total";
        } else {
            echo "Copying $rpath...";
            //复制相关文件
            $content = @file_get_contents($path);
            $total = strlen($content);
            echo "$total bytes";
        }

        if ($content) {
            file_put_contents($path, $content);
        } else {
            echo '... EMPTY';
        }
        echo "\n";
    }
}
