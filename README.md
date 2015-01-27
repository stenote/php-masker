php-masker
==========

A command-line tool to minify JavaScript, HTML, CSS and obfuscate PHP.

Usage
=====

```bash
$ php masker.phar -s|--source somewhere -d|--dest anywhere [-q|--quiet=1]
```

Build a new phar
===============

You can modify the code and use [box2](https://github.com/box-project/box2) to build a new `masker.phar`.

```bash
$ box build -v
```

Install masker
==============

```bash
$ sudo cp masker.phar /usr/local/bin/masker
```
